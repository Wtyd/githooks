<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Output\Concerns\RelativizesFilePath;
use Wtyd\GitHooks\Output\ToolOutputParser\ToolOutputParserRegistry;

/**
 * Produces SARIF 2.1.0 JSON format for GitHub Code Scanning.
 *
 * @see https://docs.github.com/en/code-security/code-scanning/integrating-with-code-scanning/sarif-support-for-code-scanning
 */
class SarifResultFormatter implements ResultFormatter
{
    use RelativizesFilePath;

    private const SCHEMA = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/main/sarif-2.1/schema/sarif-schema-2.1.0.json';

    private const TOOL_URIS = [
        'phpstan'       => 'https://phpstan.org',
        'phpcs'         => 'https://github.com/PHPCSStandards/PHP_CodeSniffer',
        'psalm'         => 'https://psalm.dev',
        'phpmd'         => 'https://phpmd.org',
        'parallel-lint' => 'https://github.com/php-parallel-lint/PHP-Parallel-Lint',
    ];

    private ToolOutputParserRegistry $parserRegistry;

    public function __construct(?ToolOutputParserRegistry $parserRegistry = null)
    {
        $this->parserRegistry = $parserRegistry ?? new ToolOutputParserRegistry();
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Aggregates issues across multiple tools into SARIF runs
     * @SuppressWarnings(PHPMD.NPathComplexity) Aggregates issues across multiple tools into SARIF runs
     */
    public function format(FlowResult $result): string
    {
        /** @var array<string, CodeIssue[]> */
        $issuesByTool = [];

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
            if (empty($issues)) {
                continue;
            }

            $toolName = $jobResult->getType();
            if (!isset($issuesByTool[$toolName])) {
                $issuesByTool[$toolName] = [];
            }
            array_push($issuesByTool[$toolName], ...$issues);
        }

        $runs = [];
        foreach ($issuesByTool as $toolName => $issues) {
            $runs[] = $this->buildRun($toolName, $issues);
        }

        // Always include at least one empty run if no issues found
        if (empty($runs)) {
            $runs[] = [
                'tool' => ['driver' => ['name' => 'githooks']],
                'results' => [],
            ];
        }

        $sarif = [
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs'    => $runs,
        ];

        $json = json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '{"error": "SARIF encoding failed"}';
    }

    /**
     * @param CodeIssue[] $issues
     * @return array<string, mixed>
     */
    private function buildRun(string $toolName, array $issues): array
    {
        $rules = [];
        $ruleIndex = [];
        $results = [];

        foreach ($issues as $issue) {
            $ruleId = $issue->getRuleId();
            if (!isset($ruleIndex[$ruleId])) {
                $ruleIndex[$ruleId] = count($rules);
                $rules[] = [
                    'id' => $ruleId,
                    'shortDescription' => ['text' => $ruleId],
                ];
            }

            $region = ['startLine' => $issue->getLine()];
            if ($issue->getEndLine() !== null) {
                $region['endLine'] = $issue->getEndLine();
            }
            if ($issue->getColumn() !== null) {
                $region['startColumn'] = $issue->getColumn();
            }

            $results[] = [
                'ruleId'    => $ruleId,
                'ruleIndex' => $ruleIndex[$ruleId],
                'level'     => $this->mapLevel($issue->getSeverity()),
                'message'   => ['text' => $issue->getMessage()],
                'locations' => [
                    [
                        'physicalLocation' => [
                            'artifactLocation' => ['uri' => $this->relativizePath($issue->getFile())],
                            'region' => $region,
                        ],
                    ],
                ],
            ];
        }

        $driver = [
            'name'  => $toolName,
            'rules' => $rules,
        ];
        if (isset(self::TOOL_URIS[$toolName])) {
            $driver['informationUri'] = self::TOOL_URIS[$toolName];
        }

        return [
            'tool'    => ['driver' => $driver],
            'results' => $results,
        ];
    }

    private function mapLevel(string $severity): string
    {
        switch ($severity) {
            case 'critical':
            case 'error':
                return 'error';
            case 'warning':
                return 'warning';
            case 'info':
                return 'note';
            default:
                return 'none';
        }
    }
}
