# System Tests — Guía de patrones

## Cuándo crear un system test

Cuando necesitas verificar el flujo completo de un comando artisan con ficheros de configuración reales
escritos en disco y una estructura de directorios real. Los procesos de las tools siguen fakeados
(no se ejecutan phpstan, phpcs, etc. realmente).

## Base class

```php
use Tests\Utils\TestCase\SystemTestCase;

class MyCommandTest extends SystemTestCase
```

`SystemTestCase` extiende `ConsoleTestCase` y añade:
- `FileSystemTrait` → crea/destruye `testsDir/src/` y `testsDir/vendor/` reales
- `ConfigurationFileBuilder` inicializado con `rootPath = 'testsDir'`
- `FileUtilsFake` bindeado para `FileUtilsInterface`
- Limpieza automática en `tearDown()`

La constante `self::TESTS_PATH` vale `'testsDir'` y `$this->path` apunta a lo mismo.

## Patrón de test

```php
<?php

namespace Tests\System\Commands;

use Tests\Utils\PhpFileBuilder;
use Tests\Utils\TestCase\SystemTestCase;
use Wtyd\GitHooks\Tools\Process\Execution\ProcessExecutionFake;

class MyCommandTest extends SystemTestCase
{
    protected $phpFileBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileBuilder = new PhpFileBuilder('File');
    }

    /** @test */
    function it_runs_tool_successfully_with_real_config()
    {
        // 1. Escribir el fichero de configuración en disco
        $this->configurationFileBuilder->buildInFileSystem();

        // 2. Crear ficheros PHP de ejemplo
        file_put_contents($this->path . '/src/File.php', $this->fileBuilder->build());

        // 3. Ejecutar y verificar
        $this->artisan("tool phpmd")
            ->assertExitCode(0)
            ->toolHasBeenExecutedSuccessfully('phpmd')
            ->containsStringInOutput("phpmd $this->path/src ansi unusedcode");
    }
}
```

## Fixtures

### ConfigurationFileBuilder

Para escribir el fichero `githooks.php` en disco:

```php
// Escribe githooks.php con config por defecto en testsDir/
$this->configurationFileBuilder->buildInFileSystem();

// Personalizar antes de escribir
$this->configurationFileBuilder
    ->setTools(['phpcs', 'phpmd'])
    ->changeToolOption('phpmd', ['rules' => 'codesize'])
    ->buildInFileSystem();
```

Para YAML (escribir manualmente):

```php
file_put_contents(
    $this->path . '/githooks.yml',
    $this->configurationFileBuilder->setTools($tools)->buildYaml()
);
```

### PhpFileBuilder

Genera ficheros PHP con o sin errores controlados:

```php
$builder = new PhpFileBuilder('File');

// Fichero sin errores
file_put_contents($this->path . '/src/File.php', $builder->build());

// Fichero con errores que phpcs y phpmd detectarán
file_put_contents(
    $this->path . '/src/FileWithErrors.php',
    $builder->setFileName('FileWithErrors')->buildWithErrors(['phpcs', 'phpmd'])
);
```

Errores soportados: `phpmd`, `phpcs`, `phpcbf`, `phpcs-no-fixable`, `phpstan`, `parallel-lint`, `phpcpd`.
Si tu tool nueva analiza código, añade un método `addMyToolError()` en `PhpFileBuilder`.

### Simular fallos

Igual que en integration tests, via `app->resolving()`:

```php
// Tool individual falla
$this->app->resolving(ProcessExecutionFake::class, function ($fake) use ($tool) {
    $fake->setToolsThatMustFail([$tool]);
});

// Tool con timeout
$this->app->resolving(ProcessExecutionFake::class, function ($fake) use ($tool) {
    $fake->setToolsWithTimeout([$tool]);
});
```

### Simular fast mode (ficheros en git staging)

```php
$this->app->resolving(FileUtilsFake::class, function ($gitFiles) use ($filePath) {
    $gitFiles->setModifiedfiles([$filePath]);
    $gitFiles->setFilesThatShouldBeFoundInDirectories([$filePath]);
});
```

## DataProviders

El patrón usa arrays descriptivos con el comando real esperado:

```php
public function allToolsOKDataProvider()
{
    return [
        'phpmd' => [
            'tool' => 'phpmd',
            'command' => "phpmd $this->path/src ansi unusedcode --exclude \"$this->path/vendor\"",
            'Alias of the tool when is executed' => 'phpmd'
        ],
        // ... una entrada por tool
    ];
}
```

## bindFakeTools()

Para tests que necesitan inyectar las clases Fake en el container:

```php
$this->bindFakeTools(); // Registra todos los *Fake en el container
```

Cuando añadas una nueva tool, actualiza `ConsoleTestCase::bindFakeTools()` con el nuevo binding.

## Verificar el comando completo

Los system tests verifican que el string del comando construido es correcto:

```php
->containsStringInOutput("phpmd $this->path/src ansi unusedcode --exclude \"$this->path/vendor\"")
```

Esto es diferente de los unit tests (que llaman a `prepareCommand()` directamente).

## Ubicación

`tests/System/Commands/` — nombrado por comando: `ExecuteToolCommandTest.php`, `CreateHookCommandTest.php`, etc.
