# Integration Tests — Guía de patrones

## Cuándo crear un integration test

Cuando necesitas verificar que varios componentes interactúan correctamente a través del container de Laravel,
pero sin filesystem real ni ejecución real de procesos. El caso típico es testear flags o comportamientos
que cruzan varias capas (configuración → ejecución → resultado).

## Base class

```php
use Tests\Utils\TestCase\ConsoleTestCase;

class MyFeatureTest extends ConsoleTestCase
```

`ConsoleTestCase` arranca la aplicación Laravel Zero completa y registra automáticamente:
- `ProcessExecutionFactoryFake` como singleton (ningún proceso real se ejecuta)
- `ConfigurationFileBuilder` con configuración por defecto vacía (`rootPath = ''`)

## Patrón de test

```php
<?php

namespace Tests\Integration;

use Tests\Utils\TestCase\ConsoleTestCase;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\Tools\Process\Execution\ProcessExecutionFake;

class MyFeatureTest extends ConsoleTestCase
{
    /**
     * @test
     * @dataProvider allToolsProvider
     */
    function it_does_something_when_condition($toolName)
    {
        // 1. Configurar el fichero de configuración mockeado
        $fileReader = $this->app->make(FileReader::class);
        $fileReader->mockConfigurationFile(
            $this->configurationFileBuilder
                ->changeToolOption($toolName, ['someOption' => 'value'])
                ->buildArray()
        );

        // 2. (Opcional) Configurar el fake de ejecución para simular fallo
        $this->app->resolving(ProcessExecutionFake::class, function ($fake) use ($toolName) {
            $fake->setToolsThatMustFail([$toolName]);
        });

        // 3. Ejecutar el comando y verificar
        $this->artisan("tool $toolName")
            ->assertExitCode(0)
            ->toolHasFailed($toolName);
    }
}
```

## Mecanismos clave

### Mockear la configuración

`FileReader::mockConfigurationFile()` inyecta un array de configuración en memoria, evitando leer del filesystem:

```php
$fileReader = $this->app->make(FileReader::class);
$fileReader->mockConfigurationFile($this->configurationFileBuilder->buildArray());
```

### Simular fallos de tools

Usa `app->resolving()` para configurar el fake justo antes de que el container lo resuelva:

```php
// Para ejecución individual (ProcessExecutionFake)
$this->app->resolving(ProcessExecutionFake::class, function ($fake) use ($toolName) {
    $fake->setToolsThatMustFail([$toolName]);
});

// Para ejecución múltiple (MultiProcessesExecutionFake)
$this->app->resolving(MultiProcessesExecutionFake::class, function ($fake) use ($toolName) {
    $fake->setToolsThatMustFail([$toolName]);
});
```

### Assertions fluent del artisan

`PendingCommand` ofrece assertions encadenables:

```php
$this->artisan("tool $toolName")
    ->assertExitCode(0)                              // Exit code
    ->toolHasBeenExecutedSuccessfully($toolName)     // Regex: "tool - OK. Time: X.XX"
    ->toolHasFailed($toolName)                       // Regex: "tool - KO. Time: X.XX"
    ->toolDidNotRun($toolName)                       // No aparece en output
    ->containsStringInOutput('string')               // Substring match
    ->notContainsStringInOutput('string')             // Negativo
    ->expectsOutput('exact string');                  // Match exacto de línea
```

## DataProvider para todas las tools

El patrón estándar para cubrir todas las tools:

```php
public function allToolsProvider()
{
    return [
        'Code Sniffer Phpcs' => ['phpcs'],
        'Code Sniffer Phpcbf' => ['phpcbf'],
        'Php Stan' => ['phpstan'],
        'Php Mess Detector' => ['phpmd'],
        'Php Copy Paste Detector' => ['phpcpd'],
        'Parallel-Lint' => ['parallel-lint'],
        'Composer Check-security' => ['security-checker'],
        // Añadir aquí la nueva tool
    ];
}
```

**Importante:** cuando se añade una nueva tool, actualizar TODOS los `allToolsProvider` existentes en los tests de integración.

## Ubicación

`tests/Integration/` — los ficheros se nombran por feature, no por clase:
- `IgnoreErrorsOnExitFlagTest.php`
- `FileUtilsTest.php` (este lleva `@group git`)

## Grupo git

Tests que necesitan interactuar con el repositorio git real (ficheros en staging) llevan `@group git`
y se excluyen del run por defecto. Se ejecutan explícitamente con `vendor/bin/phpunit --group git`.
