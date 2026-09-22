<?php

declare(strict_types=1);

namespace Tests\Unit\Container;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Container\RegisterBindings;

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
