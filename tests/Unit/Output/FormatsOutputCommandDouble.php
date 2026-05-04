<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Wtyd\GitHooks\App\Commands\Concerns\FormatsOutput;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Execution\FlowExecutor;
use Wtyd\GitHooks\Execution\FlowPlan;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Output\ResultFormatter;

/**
 * Test double that exposes the private methods of FormatsOutput trait.
 *
 * @SuppressWarnings(PHPMD)
 */
class FormatsOutputCommandDouble
{
    use FormatsOutput;

    /** @var array<string, mixed> */
    public array $options = [];

    /** @var string[] */
    public array $warnings = [];

    /** @var string[] */
    public array $lines = [];

    /** @var string[] */
    public array $infos = [];

    public $laravel;

    /** @var \Symfony\Component\Console\Output\OutputInterface|null */
    public $symfonyOutput = null;

    public function getOutput()
    {
        return $this->symfonyOutput;
    }

    public function option(string $name = null)
    {
        return $this->options[$name] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function info(string $message): void
    {
        $this->infos[] = $message;
    }

    /**
     * Override of the trait's emitReportWrittenNotice() so the test can
     * inspect what was sent to stderr without actually writing to it.
     * Tests that previously expected this to land in $infos[] now use
     * $reportNotices[]; both are kept in lock-step here for back-compat.
     */
    protected function emitReportWrittenNotice(string $path): void
    {
        $this->infos[] = "Report written to: $path";
    }

    public function getLaravel()
    {
        return $this->laravel;
    }

    public function callApplyFormat(FlowExecutor $executor, ?FlowPlan $plan = null): void
    {
        $this->applyFormat($executor, $plan);
    }

    public function callRenderFormattedResult(FlowResult $result, ?OptionsConfiguration $options = null): void
    {
        $this->renderFormattedResult($result, $options);
    }

    /**
     * @return array<string, string>
     */
    public function callCollectReportTargets(?OptionsConfiguration $options): array
    {
        return $this->collectReportTargets($options);
    }

    public function callFormatterFor(string $format): ResultFormatter
    {
        return $this->formatterFor($format);
    }
}
