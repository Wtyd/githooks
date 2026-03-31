# Unit Tests — Guía de patrones

## Cuándo crear un unit test

Siempre que se cree o modifique una clase en `src/`. Los unit tests verifican una clase en aislamiento total:
sin container de Laravel, sin filesystem, sin ejecución de procesos.

## Base class

```php
use Tests\Utils\TestCase\UnitTestCase;

class MyClassTest extends UnitTestCase
```

`UnitTestCase` extiende `PHPUnit\Framework\TestCase` directamente. Incluye:
- `messageRegExp(string $tool, bool $ok)` para verificar mensajes de éxito/fallo de tools

## Patrón para testear una Tool

Todas las Tool tienen el mismo conjunto de tests. Sigue este esquema exacto:

```php
<?php

namespace Tests\Unit\Tools\Tool;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Tools\Tool\MyTool;
use Wtyd\GitHooks\Tools\Tool\MyToolFake;

class MyToolTest extends UnitTestCase
{
    /** @test */
    function mytool_is_a_supported_tool()
    {
        $this->assertTrue(MyTool::checkTool('mytool'));
    }

    /** @test */
    function set_all_arguments_of_mytool_from_configuration_file()
    {
        $configuration = [
            'executablePath' => 'path/tools/mytool',
            // ... todos los argumentos definidos en MyTool::ARGUMENTS
        ];

        $toolConfiguration = new ToolConfiguration('mytool', $configuration);
        $tool = new MyToolFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $tool->getExecutablePath());
        $this->assertEquals($configuration, $tool->getArguments());
        $this->assertCount(count(MyToolFake::ARGUMENTS), $tool->getArguments());
    }

    /** @test */
    function it_sets_mytool_in_executablePath_when_is_empty()
    {
        $configuration = [
            // ... argumentos SIN executablePath
        ];

        $toolConfiguration = new ToolConfiguration('mytool', $configuration);
        $tool = new MyToolFake($toolConfiguration);

        $this->assertEquals('mytool', $tool->getExecutablePath());
    }

    /** @test */
    function it_ignores_unexpected_arguments()
    {
        $configuration = [
            // ... argumentos válidos + uno inesperado
            'unexpected or supported argument' => 'my value'
        ];

        $toolConfiguration = new ToolConfiguration('mytool', $configuration);
        $tool = new MyToolFake($toolConfiguration);

        $this->assertEquals($configuration['executablePath'], $tool->getExecutablePath());

        unset($configuration['unexpected or supported argument']);
        $this->assertEquals($configuration, $tool->getArguments());
    }

    // Tests adicionales para prepareCommand() con distintas combinaciones
    // Usa assertStringContainsString / assertStringEndsWith sobre $tool->prepareCommand()
}
```

## Cómo se usan las Fake classes

Las clases `*Fake` viven en `src/Tools/Tool/` (no en `tests/`). Extienden la clase real y aplican `TestToolTrait`:

```php
class MyToolFake extends MyTool
{
    use TestToolTrait;
}
```

`TestToolTrait` hace público `prepareCommand()`, `getArguments()` y `getExecutablePath()` para que los unit tests puedan llamarlos directamente.

## Cómo configurar los datos de test

- Construye arrays de configuración inline en cada test
- Usa `ToolConfiguration` para envolver el array (simula lo que haría `ConfigurationFile`)
- Para tests de `ProcessExecution` o clases que usan Mockery: incluye el trait `MockeryPHPUnitIntegration` en la clase de test (ya que `UnitTestCase` NO llama a `Mockery::close()`)

```php
use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ProcessExecutionTest extends UnitTestCase
{
    use MockeryPHPUnitIntegration;
    // ...
}
```

## Tests con @dataProvider

Para cubrir múltiples variantes del mismo escenario:

```php
public function configurationVariantsProvider()
{
    return [
        'variante descriptiva' => [
            'valor1', 'valor2'
        ],
        'otra variante' => [
            'valor3', 'valor4'
        ],
    ];
}

/**
 * @test
 * @dataProvider configurationVariantsProvider
 */
function it_handles_different_configurations($param1, $param2)
{
    // ...
}
```

Las claves del array deben ser descriptivas — aparecen en la salida de PHPUnit cuando un test falla.

## Ubicación del fichero

`tests/Unit/` replicando la estructura de `src/`:
- `src/Tools/Tool/MyTool.php` → `tests/Unit/Tools/Tool/MyToolTest.php`
- `src/ConfigurationFile/Foo.php` → `tests/Unit/ConfigurationFile/FooTest.php`
