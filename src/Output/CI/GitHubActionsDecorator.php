<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\CI;

/**
 * GitHub Actions CI decorator.
 *
 * Wraps job execution in ::group/::endgroup for collapsible sections.
 * Parses error output for file:line patterns and emits ::error annotations
 * that appear inline in the PR diff.
 */
class GitHubActionsDecorator extends CIOutputDecorator
{
    public function onJobStart(string $jobName): void
    {
        echo "::group::$jobName\n";
        $this->inner->onJobStart($jobName);
    }

    public function onJobSuccess(string $jobName, string $time): void
    {
        $this->inner->onJobSuccess($jobName, $time);
        echo "::endgroup::\n";
    }

    public function onJobError(string $jobName, string $time, string $output): void
    {
        $this->emitErrorAnnotations($output);
        $this->inner->onJobError($jobName, $time, $output);
        echo "::endgroup::\n";
    }

    /**
     * Parse tool output for file:line patterns and emit GitHub Actions error annotations.
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) preg_match assigns $matches by reference
     */
    private function emitErrorAnnotations(string $output): void
    {
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $matches = [];
            // Match patterns like "file.php:42" or "file.php on line 42"
            if (preg_match('/(?:^|\s)([^\s:]+\.php)(?::(\d+)|\ on\ line\ (\d+))/', $line, $matches)) {
                $file = $matches[1];
                $lineNum = isset($matches[2]) && $matches[2] !== '' ? $matches[2] : (isset($matches[3]) ? $matches[3] : '');
                $message = trim($line);

                if ($lineNum !== '') {
                    echo "::error file=$file,line=$lineNum::$message\n";
                } else {
                    echo "::error file=$file::$message\n";
                }
            }
        }
    }
}
