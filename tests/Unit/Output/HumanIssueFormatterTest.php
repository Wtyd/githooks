<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Output\HumanIssueFormatter;
use Wtyd\GitHooks\Output\ToolOutputParser\ToolOutputParserRegistry;

/**
 * BUG-18: covers the decision table of `HumanIssueFormatter::format()`.
 *
 * The factors are:
 *   - jobType: phpstan / phpcs / phpmd / psalm / parallel-lint / unknown
 *   - shape of the raw input: valid JSON / broken JSON / empty / non-JSON / preamble + JSON
 *   - parsed issue count: 0 / 1 / >1
 *   - issues distributed: 1 file / >1 file
 *   - column field present / absent (covers `line N` vs `line N:C`)
 *
 * Fallback policy under test:
 *   - parser missing OR parser returns []  → return raw
 *   - trim(raw) === ''                     → return ''
 *   - parser returns >=1 issue             → render human text
 */
class HumanIssueFormatterTest extends UnitTestCase
{
    private HumanIssueFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new HumanIssueFormatter(new ToolOutputParserRegistry());
    }

    /**
     * @test
     * @dataProvider formatScenarios
     *
     * @param string[]    $expectedContains
     * @param string[]    $expectedNotContains
     */
    public function format_matches_decision_table(
        string $jobType,
        string $rawOutput,
        array $expectedContains,
        array $expectedNotContains,
        ?string $expectedExact = null
    ): void {
        $actual = $this->formatter->format($jobType, $rawOutput);

        if ($expectedExact !== null) {
            $this->assertSame($expectedExact, $actual);
            return;
        }
        foreach ($expectedContains as $sub) {
            $this->assertStringContainsString($sub, $actual);
        }
        foreach ($expectedNotContains as $sub) {
            $this->assertStringNotContainsString($sub, $actual);
        }
    }

    public function formatScenarios(): array
    {
        // Row 1 — phpstan, valid JSON, 1 file, 1 issue → human block + totals.
        $phpstanOne = json_encode([
            'totals' => ['errors' => 1, 'file_errors' => 1],
            'files'  => [
                'src/Foo.php' => [
                    'errors'   => 1,
                    'messages' => [
                        ['message' => 'Class Foo not found.', 'line' => 42, 'identifier' => 'class.notFound'],
                    ],
                ],
            ],
        ]);

        // Row 2 — phpcs, valid JSON, 2 files, 3 issues, mixing with/without column.
        // Covers the `line N:C` (with column) and `line N` (without column) branches.
        $phpcsTwo = json_encode([
            'totals' => ['errors' => 3, 'warnings' => 0, 'fixable' => 0],
            'files'  => [
                'src/A.php' => [
                    'errors'   => 1,
                    'warnings' => 0,
                    'messages' => [
                        ['line' => 10, 'column' => 5, 'message' => 'first message', 'source' => 'PSR12.A.RuleA', 'type' => 'ERROR'],
                    ],
                ],
                'src/B.php' => [
                    'errors'   => 2,
                    'warnings' => 0,
                    'messages' => [
                        ['line' => 20, 'column' => 1, 'message' => 'second message', 'source' => 'PSR12.B.RuleB', 'type' => 'ERROR'],
                        // No column on this one — must render as `line 30` without `:N`.
                        ['line' => 30, 'message' => 'third message', 'source' => 'PSR12.C.RuleC', 'type' => 'WARNING'],
                    ],
                ],
            ],
        ]);

        // Row 3 — phpmd, valid JSON pretty-printed (json_encode with PRETTY_PRINT), 1 file, 1 violation.
        $phpmdOne = json_encode([
            'version' => '2.15.0',
            'files'   => [
                [
                    'file'       => 'src/Foo.php',
                    'violations' => [
                        [
                            'beginLine'   => 67,
                            'endLine'     => 80,
                            'description' => 'NPath complexity too high',
                            'rule'        => 'NPathComplexity',
                            'priority'    => 3,
                        ],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT);

        // Row 4 — psalm, valid JSON but 0 issues → fallback to raw.
        $psalmEmpty = '[]';

        // Row 5 — parallel-lint, >1 issue across 2 files.
        $parallelLint = json_encode([
            'results' => [
                'errors' => [
                    ['type' => 'ParseError', 'file' => 'src/X.php', 'line' => 3, 'message' => "Unexpected '}'"],
                    ['type' => 'ParseError', 'file' => 'src/Y.php', 'line' => 7, 'message' => 'Unterminated string literal'],
                ],
            ],
        ]);

        // Row 6 — phpstan broken/truncated JSON → fallback to raw.
        $phpstanBroken = '{"totals":{"errors":1},"files":{"src/Foo.php":';

        // Row 7 — phpstan with human preamble before the JSON payload (PHPStan 2.x).
        $phpstanWithPreamble = "Note: PHPStan running with 4 workers\n" . json_encode([
            'totals' => ['errors' => 1],
            'files'  => [
                'src/Bar.php' => [
                    'errors'   => 1,
                    'messages' => [
                        ['message' => 'Bad type', 'line' => 5, 'identifier' => 'bad.rule'],
                    ],
                ],
            ],
        ]);

        // Row 8 — unknown jobType (no parser registered) → fallback to raw.
        $localScript = "error: some custom failure\n";

        // Row 9 — phpstan with empty / whitespace-only raw → returns "".
        $phpstanEmpty = '';
        $phpstanWhitespace = "   \n  ";

        return [
            // 1: phpstan, valid, 1 file, 1 issue
            'phpstan valid: 1 file, 1 issue → human block + totals' => [
                'phpstan',
                $phpstanOne,
                ['src/Foo.php', 'line 42', 'Class Foo not found.', '[class.notFound]', 'Totals: 1 file, 1 issue'],
                ['"totals":', '"messages":'],
                null,
            ],

            // 2: phpcs, valid, 2 files, 3 issues (with/without column)
            'phpcs valid: 2 files, 3 issues, mixed column → aggregated totals' => [
                'phpcs',
                $phpcsTwo,
                [
                    'src/A.php', 'line 10:5', 'first message', '[PSR12.A.RuleA]',
                    'src/B.php', 'line 20:1', 'second message', '[PSR12.B.RuleB]',
                    'line 30', 'third message', '[PSR12.C.RuleC]',
                    'Totals: 2 files, 3 issues',
                ],
                ['"totals":', '"messages":', 'line 30:'],
                null,
            ],

            // 3: phpmd, valid pretty, 1 file, 1 violation
            'phpmd valid pretty: 1 file, 1 violation → human block' => [
                'phpmd',
                $phpmdOne,
                ['src/Foo.php', 'line 67', 'NPath complexity too high', '[NPathComplexity]', 'Totals: 1 file, 1 issue'],
                ['"violations":', '"description":'],
                null,
            ],

            // 4: psalm `[]` → raw fallback
            'psalm valid empty array: 0 issues → fallback to raw' => [
                'psalm',
                $psalmEmpty,
                [],
                [],
                $psalmEmpty,
            ],

            // 5: parallel-lint, >1 issue
            'parallel-lint valid: 2 files, 2 errors → listing + totals' => [
                'parallel-lint',
                $parallelLint,
                [
                    'src/X.php', 'line 3', "Unexpected '}'", '[SyntaxError]',
                    'src/Y.php', 'line 7', 'Unterminated string literal',
                    'Totals: 2 files, 2 issues',
                ],
                ['"results":', '"errors":'],
                null,
            ],

            // 6: phpstan broken JSON → raw fallback
            'phpstan broken JSON: parser returns 0 issues → fallback to raw' => [
                'phpstan',
                $phpstanBroken,
                [],
                [],
                $phpstanBroken,
            ],

            // 7: phpstan with preamble — ExtractsJsonDocument slices it; issue parsed
            'phpstan with human preamble → issue parsed, preamble dropped' => [
                'phpstan',
                $phpstanWithPreamble,
                ['src/Bar.php', 'line 5', 'Bad type', '[bad.rule]', 'Totals: 1 file, 1 issue'],
                ['Note: PHPStan running', '"totals":', '"messages":'],
                null,
            ],

            // 8: unknown jobType → raw fallback
            'unknown jobType (local-script) → fallback to raw, untouched' => [
                'local-script',
                $localScript,
                [],
                [],
                $localScript,
            ],

            // 9a: phpstan empty
            'phpstan empty raw → empty string' => [
                'phpstan',
                $phpstanEmpty,
                [],
                [],
                '',
            ],

            // 9b: phpstan whitespace only
            'phpstan whitespace-only raw → empty string' => [
                'phpstan',
                $phpstanWhitespace,
                [],
                [],
                '',
            ],
        ];
    }

    /**
     * Sanity check the rendered shape directly — guards against future
     * accidental reshuffles (line indent, totals format, pluralization).
     *
     * @test
     */
    public function rendered_shape_uses_two_space_indent_for_issues_and_pluralized_totals(): void
    {
        $raw = json_encode([
            'totals' => ['errors' => 2],
            'files'  => [
                'src/Foo.php' => [
                    'errors'   => 2,
                    'messages' => [
                        ['message' => 'first', 'line' => 1, 'identifier' => 'rule.one'],
                        ['message' => 'second', 'line' => 2, 'identifier' => 'rule.two'],
                    ],
                ],
            ],
        ]);

        $out = $this->formatter->format('phpstan', $raw);

        $this->assertStringContainsString("src/Foo.php\n  line 1  first  [rule.one]\n  line 2  second  [rule.two]\n", $out);
        $this->assertStringContainsString('Totals: 1 file, 2 issues', $out);
    }

    /**
     * Singular pluralization (1 file, 1 issue) — guards against the trivial
     * "always plural" mutation.
     *
     * @test
     */
    public function singular_totals_use_file_and_issue_without_s(): void
    {
        $raw = json_encode([
            'totals' => ['errors' => 1],
            'files'  => [
                'src/Foo.php' => [
                    'errors'   => 1,
                    'messages' => [
                        ['message' => 'm', 'line' => 1, 'identifier' => 'r'],
                    ],
                ],
            ],
        ]);

        $out = $this->formatter->format('phpstan', $raw);

        $this->assertStringContainsString('Totals: 1 file, 1 issue', $out);
        $this->assertStringNotContainsString('Totals: 1 files', $out);
        $this->assertStringNotContainsString('1 issues', $out);
    }
}
