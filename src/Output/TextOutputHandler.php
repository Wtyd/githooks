<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Wtyd\GitHooks\Utils\Printer;

/**
 * Default text output: success lines print in real-time, error details
 * are buffered and printed grouped at the end via flush().
 */
class TextOutputHandler implements OutputHandler
{
    private Printer $printer;

    /** @var array<array{jobName: string, output: string}> */
    private array $errorBuffer = [];

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        $this->printer->jobSuccess($jobName, $time);
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        $this->printer->jobError($jobName, $time);
        $this->errorBuffer[] = ['jobName' => $jobName, 'output' => $output];
    }

    public function onJobSkipped(string $jobName, string $reason): void
    {
        $this->printer->line("  ⏩ $jobName ($reason)");
    }

    public function onJobDryRun(string $jobName, string $command): void
    {
        $this->printer->line("  \e[36m$jobName\e[0m");
        $this->printer->line("     $command");
    }

    public function flush(): void
    {
        if (empty($this->errorBuffer)) {
            return;
        }

        $this->printer->emptyLine();

        foreach ($this->errorBuffer as $entry) {
            if (!empty(trim($entry['output']))) {
                $this->printer->framedErrorBlock($entry['jobName'], $entry['output']);
                $this->printer->emptyLine();
            }
        }

        $this->errorBuffer = [];
    }
}
