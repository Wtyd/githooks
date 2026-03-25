# Release Tests — Guía de patrones

## Propósito

Los release tests son **smoke tests del artefacto de distribución** (el `.phar` compilado).
Su objetivo es verificar que la build contiene lo que promete: si se añade una feature nueva,
debe haber al menos un release test que demuestre que funciona con el binario real.

Esto previene un error clásico: que el código pase todos los tests pero la build no incluya los cambios
porque se olvidó buildear o la configuración de Box excluyó algo.

## Base class

```php
use Tests\ReleaseTestCase;

/**
 * @group release
 */
class MyReleaseTest extends ReleaseTestCase
```

`ReleaseTestCase` extiende `PHPUnit\Framework\TestCase` directamente (sin container de Laravel). Proporciona:
- `FileSystemTrait` → crea/destruye `testsDir/` con estructura real
- `copyReleaseBinary()` → copia el `.phar` de `builds/` a `testsDir/githooks`
- `$this->githooks` → path al binario para usar con `passthru()`
- `$this->configurationFileBuilder` y `$this->phpFileBuilder` → fixtures
- Assertions: `assertToolHasBeenExecutedSuccessfully()`, `assertToolHasFailed()`, `assertToolDidNotRun()`, `assertSomeToolHasFailed()`

**Importante:** `@group release` es OBLIGATORIO en la clase. Sin él, el test se ejecutaría en el CI
normal donde no hay build disponible.

## Patrón de test

```php
<?php

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class MyFeatureReleaseTest extends ReleaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Crear fichero PHP limpio en testsDir/src/
        file_put_contents(
            self::TESTS_PATH . '/src/File.php',
            $this->phpFileBuilder->build()
        );
    }

    /** @test */
    function it_returns_exit_0_when_mytool_passes()
    {
        // 1. Escribir githooks.php (se escribe en el directorio de trabajo, NO en testsDir)
        file_put_contents(
            'githooks.php',
            $this->configurationFileBuilder
                ->setTools(['mytool'])
                ->buildPhp()
        );

        // 2. Ejecutar el binario real
        passthru("$this->githooks tool mytool", $exitCode);

        // 3. Verificar
        $this->assertEquals(0, $exitCode);
        $this->assertToolHasBeenExecutedSuccessfully('mytool');
    }
}
```

## Diferencias clave con otros tipos de test

| Aspecto | Release test | System/Integration test |
|---|---|---|
| Ejecuta el binario | `.phar` real via `passthru()` | Comando artisan via container |
| Mocking | Ninguno | Fakes para procesos y filesystem |
| Config file | Se escribe en el directorio de trabajo (`'githooks.php'`) | Se escribe en `testsDir/` |
| Tools reales | Sí — phpstan, phpcs, etc. se ejecutan de verdad | No — `ProcessExecutionFake` las simula |
| Grupo | `@group release` (excluido por defecto) | Sin grupo especial |
| CI | Solo en `release.yml` (ramas `rc**`) | En `main-tests.yml` (push/PR) |

## Fichero de configuración

**Atención:** en release tests, `githooks.php` se escribe en el directorio raíz del proyecto (no en `testsDir/`).
Esto es porque el binario `.phar` busca el fichero en el directorio de trabajo actual.

```php
file_put_contents('githooks.php', $this->configurationFileBuilder->setTools([...])->buildPhp());
```

El `tearDown()` de `ReleaseTestCase` limpia tanto `githooks.php` como `githooks.yml` del directorio raíz.

## Crear errores controlados

Para verificar que una tool detecta errores con el binario real:

```php
file_put_contents(
    self::TESTS_PATH . '/src/FileWithErrors.php',
    $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['phpcs', 'phpmd'])
);
```

Las tools reales analizarán el fichero y encontrarán los errores inyectados.

## Fast execution mode

Para testear el modo rápido (solo ficheros en git staging):

```php
$fileWithoutErrorsPath = self::TESTS_PATH . '/src/File.php';
shell_exec("git add $fileWithoutErrorsPath");

passthru("$this->githooks tool all fast", $exitCode);

// Limpiar git staging después
shell_exec("git restore -- " . self::TESTS_PATH . "/.gitignore");
shell_exec("git reset -- $fileWithoutErrorsPath");
```

## DataProviders

Para execution mode variants:

```php
public function fullExecutionModeProvider()
{
    return [
        'Without override execution mode in file' => [
            'Execution mode argument' => '',
            'Execution mode in file' => ['execution' => 'full']
        ],
        'Overriding execution mode in file' => [
            'Execution mode argument' => 'full',
            'Execution mode in file' => ['execution' => 'fast']
        ]
    ];
}
```

## Happy path mínimo para una feature nueva

Si has añadido una nueva tool `mytool`, el release test mínimo es:

```php
/** @test */
function it_returns_exit_0_when_mytool_passes()
{
    file_put_contents(
        'githooks.php',
        $this->configurationFileBuilder->setTools(['mytool'])->buildPhp()
    );

    passthru("$this->githooks tool mytool", $exitCode);

    $this->assertEquals(0, $exitCode);
    $this->assertToolHasBeenExecutedSuccessfully('mytool');
}
```

Y opcionalmente, verificar que detecta errores:

```php
/** @test */
function it_returns_exit_1_when_mytool_finds_errors()
{
    file_put_contents(
        'githooks.php',
        $this->configurationFileBuilder->setTools(['mytool'])->buildPhp()
    );

    file_put_contents(
        self::TESTS_PATH . '/src/FileWithErrors.php',
        $this->phpFileBuilder->setFileName('FileWithErrors')->buildWithErrors(['mytool'])
    );

    passthru("$this->githooks tool mytool", $exitCode);

    $this->assertEquals(1, $exitCode);
    $this->assertToolHasFailed('mytool');
}
```

## Ubicación

`tests/System/Release/` — todos los ficheros llevan `@group release` a nivel de clase.
