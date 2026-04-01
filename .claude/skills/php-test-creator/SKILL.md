---
name: php-test-creator
description: >
  Crea tests PHPUnit para el proyecto GitHooks (wtyd/githooks) siguiendo sus convenciones y patrones.
  Usa esta skill siempre que el usuario pida crear tests, añadir tests, testear una feature, verificar
  una funcionalidad, o cuando se implemente una nueva feature que necesite cobertura de tests.
  También cuando se mencione: "añadir tests", "crear tests", "test unitario", "test de integración",
  "test de sistema", "necesito tests para esto", "falta cobertura".
---

# PHP Test Creator para GitHooks

Esta skill genera tests para el proyecto GitHooks siguiendo sus convenciones.
El proyecto tiene 4 niveles de testing, cada uno con un propósito claro.

## Flujo de decisión

### 1. Determinar qué tests son necesarios

| Pregunta | Si sí → |
|---|---|
| ¿Clase nueva/modificada en `src/Configuration/`? | **Unit test** (patrón v3) |
| ¿Clase nueva/modificada en `src/Jobs/`? | **Unit test** (patrón v3 — JobBuildCommandTest) |
| ¿Clase nueva/modificada en `src/Execution/`? | **Unit test** (patrón v3) |
| ¿Clase nueva/modificada en `src/Hooks/`? | **Unit test** (patrón v3) |
| ¿Clase nueva/modificada en `src/Tools/Tool/`? | **Unit test** (patrón legacy) + Fake + infra |
| ¿Comando nuevo/modificado en `app/Commands/`? | **System test** + **testing funcional** (qa-tester) |
| ¿Comando con opciones CLI nuevas? | **Test que verifique que cada opción llega al servicio** |
| ¿Feature visible para el usuario final del `.phar`? | **Release test** (al menos happy path) |

**Regla de oro:** toda opción CLI nueva necesita un test que verifique que el valor
se propaga hasta el servicio (no solo que el comando no crashea).

### 2. Crear los tests

Para cada tipo, lee la referencia correspondiente:

- **Unit tests v3** → lee `references/unit-tests-v3.md`
- **Unit tests legacy** → lee `references/unit-tests.md`
- **Integration tests** → lee `references/integration-tests.md`
- **System tests** → lee `references/system-tests.md`
- **Release tests** → lee `references/release-tests.md`

### 3. Verificar con testing funcional

Después de los tests automatizados, usar la skill `qa-tester` para:
- Probar el comando manualmente con distintos inputs
- Probar edge cases que PHPUnit no cubre (config rota, flags combinados, formatos de salida)

## Convenciones transversales

- **`/** @test */`** en cada método (nunca prefijo `test`)
- **snake_case con lenguaje natural**: `it_does_something_when_condition()`
- **`@dataProvider`** para cobertura paramétrica
- **Namespace** sigue estructura de directorios
- **Una clase de test por clase testeada**
- **No usar `setUp()` si no es necesario**

## Patrones de test v3

### Unit test para Configuration

```php
declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Jobs\JobRegistry;

class ConfigurationParserTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = sys_get_temp_dir() . '/githooks_test_' . uniqid();
        mkdir($this->fixturesPath, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixturesPath . '/*') ?: []);
        @rmdir($this->fixturesPath);
    }

    /** @test */
    function it_parses_v3_config_with_flows_and_jobs()
    {
        file_put_contents($this->fixturesPath . '/githooks.php', '<?php return [
            "flows" => ["qa" => ["jobs" => ["myjob"]]],
            "jobs" => ["myjob" => ["type" => "phpstan", "paths" => ["src"]]],
        ];');

        $parser = new ConfigurationParser(new ToolRegistry(), '', new JobRegistry());
        $config = $parser->parse($this->fixturesPath . '/githooks.php');

        $this->assertFalse($config->hasErrors());
        $this->assertNotNull($config->getFlow('qa'));
    }
}
```

### Unit test para Jobs (ARGUMENT_MAP)

```php
declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\PhpstanJob;

class JobBuildCommandTest extends TestCase
{
    /** @test */
    function phpstan_builds_correct_command()
    {
        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', [
            'executablePath' => 'vendor/bin/phpstan',
            'config'         => 'qa/phpstan.neon',
            'level'          => '8',
            'paths'          => ['src'],
            'otherArguments' => '--ansi',
        ]));

        $this->assertEquals(
            'vendor/bin/phpstan analyse -c qa/phpstan.neon -l 8 --ansi src',
            $job->buildCommand()
        );
    }

    /** @test */
    function phpstan_with_empty_paths_omits_paths()
    {
        $job = new PhpstanJob(new JobConfiguration('test', 'phpstan', [
            'paths' => [],
        ]));

        $command = $job->buildCommand();
        $this->assertStringNotContainsString('  ', $command); // no double spaces
    }
}
```

### Unit test para Execution

```php
declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Jobs\JobRegistry;

class FlowPreparerTest extends TestCase
{
    private FlowPreparer $preparer;

    protected function setUp(): void
    {
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    /** @test */
    function it_prepares_a_flow_with_valid_jobs()
    {
        $jobs = [
            'myjob' => new JobConfiguration('myjob', 'phpstan', ['paths' => ['src']]),
        ];
        $flow = new FlowConfiguration('qa', ['myjob'], null);
        $options = OptionsConfiguration::defaults();
        $config = new ConfigurationResult('/tmp/test.php', $options, null, ['qa' => $flow], $jobs, new ValidationResult());

        $plan = $this->preparer->prepare($flow, $config);

        $this->assertCount(1, $plan->getJobs());
    }
}
```

### Test para opciones CLI de un comando

**CRITICO: cada opción del signature debe tener un test que verifique que modifica el comportamiento.**

```php
/** @test */
function fail_fast_option_stops_execution_on_first_failure()
{
    // Configurar 3 jobs donde el 2o falla
    // Ejecutar con --fail-fast
    // Verificar que el 3o NO se ejecutó
}

/** @test */
function processes_option_overrides_config_value()
{
    // Config tiene processes=1
    // Ejecutar con --processes=4
    // Verificar que FlowPlan tiene processes=4
}

/** @test */
function exclude_jobs_option_removes_jobs_from_plan()
{
    // Config tiene jobs [a, b, c]
    // Ejecutar con --exclude-jobs=b
    // Verificar que solo se ejecutan a y c
}
```

## Checklist de verificación

### Estructura
- [ ] Tests en directorio correcto (`tests/Unit/`, `tests/System/Commands/`, etc.)
- [ ] Clase extiende la base class correcta
- [ ] Namespace coincide con la ruta del fichero

### Convenciones
- [ ] `/** @test */` en todos los métodos
- [ ] snake_case descriptivo
- [ ] `@dataProvider` donde hay variantes

### Cobertura de opciones CLI (CRITICA)
- [ ] **Cada opción del signature tiene un test que verifica que el valor llega al servicio**
- [ ] **No hay opciones del signature sin cobertura de test**
- [ ] Test con config inexistente → no produce stack trace
- [ ] Test con config inválida → errores descriptivos
- [ ] Test con formato inválido (--format=csv) → comportamiento definido

### Para Jobs v3
- [ ] `buildCommand()` genera el comando correcto con todos los argumentos
- [ ] Argumentos opcionales ausentes no generan flags vacíos
- [ ] `getThreadCapability()` devuelve la capability correcta (o null)
- [ ] `applyThreadLimit()` modifica el argumento correcto

### Para Tools legacy (solo si se toca código legacy)
- [ ] Ver `references/unit-tests.md` para el patrón legacy completo

### Ejecución final (OBLIGATORIO)
- [ ] `php7.4 vendor/bin/phpunit --order-by random` — 0 fallos
- [ ] `php7.4 githooks flow qa` — QA completo sin violaciones nuevas
- [ ] Skill `qa-tester` sobre los comandos tocados

## Contexto legacy

Los tests legacy (en `tests/Unit/Tools/`, `tests/Integration/`, `tests/System/Commands/ExecuteToolCommandTest.php`,
`tests/System/Release/`) siguen siendo válidos para el sistema v2. Las references `unit-tests.md`,
`integration-tests.md`, `system-tests.md`, `release-tests.md` documentan estos patrones.
No añadir tests legacy para funcionalidades nuevas — usar los patrones v3 de arriba.
