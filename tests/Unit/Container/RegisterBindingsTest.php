<?php

declare(strict_types=1);

namespace Tests\Unit\Container;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\HookConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Container\RegisterBindings;
use Wtyd\GitHooks\Hooks\HookEventStatus;
use Wtyd\GitHooks\Hooks\HookStatusInspector;

/**
 * The production bindings are shadowed in the test harness: `testsRegister`
 * rebinds HookInstaller, HookStatusInspector and friends to fakes so the
 * suite never shell-execs against the real repository. That leaves the real
 * factory closures unexercised — a binding that returns null, or an instance
 * of the wrong class, would not fail a single test.
 *
 * This test resolves every declared binding from a container of its own,
 * untouched by the harness, and checks the contract each closure is there to
 * honour: resolving the key yields an instance of the key.
 */
class RegisterBindingsTest extends UnitTestCase
{
    /**
     * @test
     * @dataProvider declaredBindings
     */
    public function every_declared_binding_resolves_to_an_instance_of_its_key(string $key): void
    {
        $container = new Container();
        Container::setInstance($container);
        // The framework binds the container contract to itself; constructors
        // that type-hint it (ProcessExecutionFactory) rely on that.
        $container->instance(ContainerContract::class, $container);

        try {
            (new RegisterBindings())->register();

            $this->assertInstanceOf($key, $container->make($key));
        } finally {
            Container::setInstance(null);
        }
    }

    /**
     * The two hook bindings are constructed with `getcwd() ?: ''`, and the
     * fallback only exists for the case where getcwd() fails. Resolving to an
     * instance is not enough: a binding that handed over the empty string
     * would look at `/.githooks` instead of the project, and `githooks status`
     * would report every installed hook as missing. Observed through the
     * inspector's own reading rather than by reflecting on a private field.
     *
     * @test
     */
    public function the_hook_bindings_receive_the_working_directory_not_the_fallback(): void
    {
        $project = sys_get_temp_dir() . '/register-bindings-' . uniqid('', true);
        mkdir($project . '/.githooks', 0755, true);
        file_put_contents($project . '/.githooks/pre-commit', "#!/bin/sh\n");

        $previousCwd = getcwd() ?: sys_get_temp_dir();
        chdir($project);

        $container = new Container();
        Container::setInstance($container);
        $container->instance(ContainerContract::class, $container);

        try {
            (new RegisterBindings())->register();
            $report = $container->make(HookStatusInspector::class)->inspect($this->emptyConfig());

            $events = array_map(
                function (HookEventStatus $event): string {
                    return $event->getEvent();
                },
                $report->getEvents()
            );
            $this->assertSame(['pre-commit'], $events, 'the inspector must look inside the working directory');
        } finally {
            Container::setInstance(null);
            chdir($previousCwd);
            @unlink($project . '/.githooks/pre-commit');
            @rmdir($project . '/.githooks');
            @rmdir($project);
        }
    }

    private function emptyConfig(): ConfigurationResult
    {
        return new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [],
            [],
            new HookConfiguration([]),
            new ValidationResult()
        );
    }

    /**
     * Drives off the real binding maps, so a binding added later is covered
     * without touching this test.
     *
     * @return array<string, array{0: string}>
     */
    public function declaredBindings(): array
    {
        $exposer = new class extends RegisterBindings {
            /** @return array<string, mixed> */
            public function allKeys(): array
            {
                return array_merge(array_keys($this->binds()), array_keys($this->singletons()));
            }
        };

        $cases = [];
        foreach ($exposer->allKeys() as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }
}
