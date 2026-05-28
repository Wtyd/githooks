<?php

namespace Tests\Utils\Traits;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        self::assertNotInsideRecursivePhpunit();

        !defined('APP_ENV') &&  define('APP_ENV', 'testing');

        $app = require getcwd() . '/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Fail-safe against the BUG-21 fork-bomb pattern: if our PHPUnit invocation
     * is itself a *child* of another PHPUnit invocation (because something in
     * the suite spawned `vendor/bin/phpunit` via `JobExecutor` /
     * `FlowExecutor`, which use `Process::fromShellCommandLine()` with
     * `setTimeout(null)` and bypass the fake binding of
     * `ProcessExecutionFactoryAbstract`), abort the child immediately. This
     * caps the recursion at depth 1 and prevents the OOM cascade observed on
     * 2026-05-28 (see `incidente-fork-bomb/INFORME.md`).
     */
    private static function assertNotInsideRecursivePhpunit(): void
    {
        $self = (string) getmypid();
        $parent = getenv('GITHOOKS_PHPUNIT_GUARD_PID');

        if ($parent !== false && $parent !== '' && $parent !== $self) {
            fwrite(
                STDERR,
                "\n[GITHOOKS_PHPUNIT_GUARD] Recursive PHPUnit invocation detected "
                . "(parent PID {$parent}, this PID {$self}). Aborting to prevent fork-bomb cascade.\n"
            );
            exit(99);
        }

        putenv('GITHOOKS_PHPUNIT_GUARD_PID=' . $self);
        $_ENV['GITHOOKS_PHPUNIT_GUARD_PID'] = $self;
        $_SERVER['GITHOOKS_PHPUNIT_GUARD_PID'] = $self;
    }
}
