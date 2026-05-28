<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Wtyd\GitHooks\App\Commands\Concerns\ValidatesUnknownOptionsBeforeDashDash;

/**
 * @SuppressWarnings(PHPMD)
 */
class ValidatesUnknownOptionsBeforeDashDashCommandDouble
{
    use ValidatesUnknownOptionsBeforeDashDash;

    private InputDefinition $definition;

    protected ?InputInterface $input = null;

    /** @var string[] */
    public array $errLines = [];

    /**
     * @param array<string,?string> $knownOptions name => shortcut|null
     */
    public function __construct(array $knownOptions = [])
    {
        $this->definition = new InputDefinition();
        foreach ($knownOptions as $name => $shortcut) {
            $this->definition->addOption(
                new InputOption($name, $shortcut, InputOption::VALUE_OPTIONAL)
            );
        }
    }

    public function getDefinition(): InputDefinition
    {
        return $this->definition;
    }

    public function error(string $message): void
    {
        $this->errLines[] = $message;
    }

    /**
     * Drive the concern with a synthetic input. The first element of $argv is
     * the binary name (matches ArgvInput convention); ArgvInput strips it
     * internally.
     *
     * @param string[] $argv
     */
    public function call(array $argv): bool
    {
        $this->input = new ArgvInput($argv);
        return $this->assertNoUnknownOptionsBeforeDashDash();
    }

    /**
     * @param string[] $argv
     */
    public function callDashDashCheck(array $argv): bool
    {
        $this->input = new ArgvInput($argv);
        return $this->inputContainsDashDashSeparator();
    }
}
