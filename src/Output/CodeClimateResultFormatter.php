<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Output\ToolOutputParser\ToolOutputParserRegistry;

/**
 * Produces Code Climate JSON format for GitLab Code Quality.
 *
 * @see https://docs.gitlab.com/ci/testing/code_quality/
 */
class CodeClimateResultFormatter implements ResultFormatter
{
    private ToolOutputParserRegistry $parserRegistry;

    public function __construct(?ToolOutputParserRegistry $parserRegistry = null)
    {
        $this->parserRegistry = $parserRegistry ?? new ToolOutputParserRegistry();
    }

    public function format(FlowResult $result): string
    {
        $codeClimateIssues = [];

        foreach ($result->getJobResults() as $jobResult) {
            $stdout = $jobResult->getStdout();
            if ($stdout === null || $stdout === '') {
                continue;
            }

            $parser = $this->parserRegistry->getParser($jobResult->getType());
            if ($parser === null) {
                continue;
            }

            $issues = $parser->parse($stdout, $jobResult->getType());
            foreach ($issues as $issue) {
                $codeClimateIssues[] = [
                    'description' => $issue->getMessage(),
                    'check_name'  => $issue->getRuleId(),
                    'fingerprint' => $issue->getFingerprint(),
                    'severity'    => $this->mapSeverity($issue->getSeverity()),
                    'location'    => [
                        'path'  => $this->relativizePath($issue->getFile()),
                        'lines' => ['begin' => $issue->getLine()],
                    ],
                ];
            }
        }

        $json = json_encode($codeClimateIssues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '[]';
    }

    private function relativizePath(string $path): string
    {
        $cwd = getcwd();
        if ($cwd === false || $path === '' || $path[0] !== '/') {
            return $path;
        }
        $prefix = rtrim($cwd, '/') . '/';
        if (strpos($path, $prefix) === 0) {
            return substr($path, strlen($prefix));
        }
        return $path;
    }

    private function mapSeverity(string $severity): string
    {
        switch ($severity) {
            case 'critical':
                return 'critical';
            case 'error':
                return 'major';
            case 'warning':
                return 'minor';
            case 'info':
            default:
                return 'info';
        }
    }
}
