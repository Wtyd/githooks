# Unit Tests v3 — Guia de patrones

## Cuando crear un unit test v3

Siempre que se cree o modifique una clase en `src/Configuration/`, `src/Jobs/`, `src/Execution/`, o `src/Hooks/`.

## Base class

```php
use PHPUnit\Framework\TestCase;

class MyClassTest extends TestCase
```

Tests v3 extienden `TestCase` directamente (no UnitTestCase). Sin container de Laravel,
sin filesystem, sin procesos.

## Patron para Configuration

Escribir ficheros de config temporal en `sys_get_temp_dir()` y parsear con `ConfigurationParser`.

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
    private ConfigurationParser $parser;

    protected function setUp(): void
    {
        $this->fixturesPath = sys_get_temp_dir() . '/githooks_test_' . uniqid();
        mkdir($this->fixturesPath, 0755, true);
        $this->parser = new ConfigurationParser(new ToolRegistry(), '', new JobRegistry());
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixturesPath . '/*') ?: []);
        @rmdir($this->fixturesPath);
    }

    /** @test */
    function it_detects_errors_when_jobs_section_is_missing()
    {
        $this->writeConfig('<?php return [];');
        $config = $this->parser->parse($this->fixturesPath . '/githooks.php');

        $this->assertTrue($config->hasErrors());
    }

    private function writeConfig(string $content): void
    {
        file_put_contents($this->fixturesPath . '/githooks.php', $content);
    }
}
```

### Que testear en Configuration

- Config valida con hooks/flows/jobs se parsea sin errores
- Config vacia `return []` reporta error de jobs missing
- Flow que referencia job inexistente genera warning
- Job con type invalido genera error
- Hook con evento git invalido genera error
- Flow con nombre de hook git genera error
- Options con processes negativo genera error
- Options con fail-fast no booleano genera error
- Custom job sin script genera error
- Deteccion de formato legacy (presencia de Options/Tools)

## Patron para Jobs (ARGUMENT_MAP)

Instanciar el job con `JobConfiguration` y verificar que `buildCommand()` genera el comando correcto.

```php
declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\CustomJob;
use Wtyd\GitHooks\Jobs\JobRegistry;

class JobBuildCommandTest extends TestCase
{
    /**
     * @test
     * @dataProvider jobCommandProvider
     */
    function it_builds_the_correct_command(string $jobClass, string $type, array $args, string $expected)
    {
        $config = new JobConfiguration('test', $type, $args);
        $job = new $jobClass($config);
        $this->assertEquals($expected, $job->buildCommand());
    }

    public function jobCommandProvider(): array
    {
        return [
            'phpstan with all args' => [
                PhpstanJob::class, 'phpstan',
                ['config' => 'qa/phpstan.neon', 'level' => '8', 'paths' => ['src']],
                'phpstan analyse -c qa/phpstan.neon -l 8 src',
            ],
            'phpcs with ignore csv' => [
                PhpcsJob::class, 'phpcs',
                ['standard' => 'PSR12', 'ignore' => ['vendor', 'tools'], 'paths' => ['./']],
                'phpcs --standard=PSR12 --ignore=vendor,tools ./',
            ],
            // ... más variantes
        ];
    }

    /** @test */
    function custom_job_uses_script_directly()
    {
        $config = new JobConfiguration('test', 'custom', ['script' => 'echo "hello"']);
        $job = new CustomJob($config);
        $this->assertEquals('echo "hello"', $job->buildCommand());
    }
}
```

### Que testear en Jobs

- `buildCommand()` con todos los argumentos
- `buildCommand()` con argumentos opcionales omitidos
- `getThreadCapability()` devuelve capability correcta o null
- `applyThreadLimit()` modifica el argumento de threading
- `getDisplayName()` devuelve el nombre del job
- `isFixApplied()` para phpcbf (exit code 1 = fix applied)

## Patron para Execution

```php
declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\ThreadBudgetAllocator;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpstanJob;
use Wtyd\GitHooks\Configuration\JobConfiguration;

class ThreadBudgetAllocatorTest extends TestCase
{
    /** @test */
    function it_distributes_budget_among_jobs()
    {
        $allocator = new ThreadBudgetAllocator();
        $jobs = [
            new PhpcsJob(new JobConfiguration('phpcs', 'phpcs', ['paths' => ['src']])),
            new PhpstanJob(new JobConfiguration('phpstan', 'phpstan', ['paths' => ['src']])),
        ];

        $plan = $allocator->allocate(4, $jobs);

        $this->assertGreaterThan(0, $plan->getMaxParallelJobs());
    }
}
```

## Patron para Hooks

```php
declare(strict_types=1);

namespace Tests\Unit\Hooks;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Hooks\HookInstaller;

class HookInstallerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/githooks_hooks_test_' . uniqid();
        mkdir($this->tempDir . '/.git/hooks', 0755, true);
    }

    protected function tearDown(): void
    {
        // Limpiar recursivo
        $this->recursiveDelete($this->tempDir);
    }

    /** @test */
    function it_creates_hook_scripts_in_githooks_directory()
    {
        $installer = new HookInstaller($this->tempDir);
        $events = ['pre-commit', 'pre-push'];

        $installer->install($events);

        $this->assertFileExists($this->tempDir . '/.githooks/pre-commit');
        $this->assertFileExists($this->tempDir . '/.githooks/pre-push');
    }
}
```

## Directorio de tests v3

```
tests/Unit/Configuration/
    ConfigurationParserTest.php
    FlowConfigurationTest.php
    HookConfigurationTest.php
    JobConfigurationTest.php
    OptionsConfigurationTest.php
    ValidationResultTest.php
tests/Unit/Execution/
    FlowPreparerTest.php
    ThreadBudgetAllocatorTest.php
tests/Unit/Hooks/
    HookInstallerTest.php
tests/Unit/Jobs/
    JobBuildCommandTest.php
```
