<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\CI;

/**
 * GitLab CI decorator.
 *
 * Wraps job execution in collapsible sections using GitLab's
 * section_start/section_end escape sequences.
 */
class GitLabCIDecorator extends CIOutputDecorator
{
    private int $sectionId = 0;

    public function onJobStart(string $jobName): void
    {
        $this->sectionId++;
        $timestamp = time();
        $id = "githooks_job_{$this->sectionId}";

        echo "\033[0Ksection_start:{$timestamp}:{$id}[collapsed=true]\r\033[0K{$jobName}\n";
        $this->inner->onJobStart($jobName);
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        $this->inner->onJobSuccess($jobName, $time);
        $this->endSection();
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        $this->inner->onJobError($jobName, $time, $output);
        $this->endSection();
    }

    private function endSection(): void
    {
        $timestamp = time();
        $id = "githooks_job_{$this->sectionId}";

        echo "\033[0Ksection_end:{$timestamp}:{$id}\r\033[0K\n";
    }
}
