<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Output\SarifResultFormatter;

/**
 * Contract test: the SARIF produced by SarifResultFormatter validates
 * against the official SARIF 2.1.0 JSON Schema and matches the golden
 * fixture captured from a real phpstan run on tests/Fixtures/sarif-broken-code.
 *
 * When the formatter changes legitimately, refresh the golden by running
 * the on-demand workflow `.github/workflows/sarif-contract.yml` (job `refresh`)
 * or locally:
 *
 *   php7.4 githooks flow sarif-check \
 *     --config=qa/githooks.sarif-check.php \
 *     --format=sarif \
 *     --output=tests/Fixtures/sarif/golden-with-violations.json
 *
 * @SuppressWarnings(PHPMD)
 */
class SarifResultFormatterSchemaTest extends TestCase
{
    private const SCHEMA_PATH = __DIR__ . '/../../Fixtures/sarif/sarif-schema-2.1.0.json';
    private const GOLDEN_PATH = __DIR__ . '/../../Fixtures/sarif/golden-with-violations.json';

    /** @test */
    public function golden_sarif_fixture_validates_against_official_schema()
    {
        $this->assertFileExists(self::SCHEMA_PATH, 'SARIF 2.1.0 schema fixture missing');
        $this->assertFileExists(self::GOLDEN_PATH, 'Golden SARIF fixture missing');

        $schema = json_decode((string) file_get_contents(self::SCHEMA_PATH));
        $golden = json_decode((string) file_get_contents(self::GOLDEN_PATH));

        $this->assertNotNull($schema, 'Schema fixture is not valid JSON');
        $this->assertNotNull($golden, 'Golden fixture is not valid JSON');

        $errors = $this->validateAgainstSchema($golden, $schema);
        $this->assertSame([], $errors, 'Golden fixture does not comply with SARIF 2.1.0: ' . PHP_EOL . implode(PHP_EOL, $errors));
    }

    /** @test */
    public function formatter_output_validates_against_official_schema()
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA_PATH));
        $sarifJson = (new SarifResultFormatter())->format($this->buildFlowResultMatchingFixture());
        $sarifDecoded = json_decode($sarifJson);

        $this->assertNotNull($sarifDecoded, 'Formatter produced invalid JSON');

        $errors = $this->validateAgainstSchema($sarifDecoded, $schema);
        $this->assertSame([], $errors, 'Formatter output does not comply with SARIF 2.1.0: ' . PHP_EOL . implode(PHP_EOL, $errors));
    }

    /** @test */
    public function formatter_output_matches_golden_fixture()
    {
        $golden = json_decode((string) file_get_contents(self::GOLDEN_PATH), true);
        $sarifJson = (new SarifResultFormatter())->format($this->buildFlowResultMatchingFixture());
        $produced = json_decode($sarifJson, true);

        $this->assertSame(
            $golden,
            $produced,
            'Formatter output diverged from the golden fixture. If the change is intentional, refresh the golden file (see class docblock).'
        );
    }

    /**
     * Build a FlowResult equivalent to running `flow sarif-check` on the
     * tests/Fixtures/sarif-broken-code/ fixture. Each synthetic stdout
     * mimics the JSON output of its tool (phpstan --error-format=json,
     * phpcs --report=json, phpmd renderer=json) — exactly what the real
     * flow produces when the SARIF format is requested, frozen against
     * the committed golden fixture.
     */
    private function buildFlowResultMatchingFixture(): FlowResult
    {
        $file = 'tests/Fixtures/sarif-broken-code/BrokenA.php';

        $phpstanStdout = json_encode([
            'totals' => ['errors' => 3, 'file_errors' => 3],
            'files' => [
                $file => [
                    'errors' => 3,
                    'messages' => [
                        [
                            'line' => 15,
                            'message' => 'Method Tests\\Fixtures\\SarifBrokenCode\\BrokenA::a() has no return type specified.',
                            'identifier' => 'missingType.return',
                            'ignorable' => true,
                        ],
                        [
                            'line' => 15,
                            'message' => 'Method Tests\\Fixtures\\SarifBrokenCode\\BrokenA::a() has parameter $x with no type specified.',
                            'identifier' => 'missingType.parameter',
                            'ignorable' => true,
                        ],
                        [
                            'line' => 18,
                            'message' => 'Undefined variable: $y',
                            'identifier' => 'variable.undefined',
                            'ignorable' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $phpcsStdout = json_encode([
            'totals' => ['errors' => 0, 'warnings' => 1, 'fixable' => 0],
            'files' => [
                $file => [
                    'errors' => 0,
                    'warnings' => 1,
                    'messages' => [
                        [
                            'line' => 14,
                            'column' => 5,
                            'message' => 'Line exceeds 120 characters; contains 131 characters',
                            'source' => 'Generic.Files.LineLength.TooLong',
                            'severity' => 5,
                            'type' => 'WARNING',
                            'fixable' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $phpmdStdout = json_encode([
            'files' => [
                [
                    'file' => $file,
                    'violations' => [
                        [
                            'beginLine' => 15,
                            'endLine' => 15,
                            'description' => "Avoid unused parameters such as '\$x'.",
                            'rule' => 'UnusedFormalParameter',
                            'priority' => 3,
                        ],
                        [
                            'beginLine' => 17,
                            'endLine' => 17,
                            'description' => "Avoid unused local variables such as '\$unused'.",
                            'rule' => 'UnusedLocalVariable',
                            'priority' => 3,
                        ],
                        [
                            'beginLine' => 18,
                            'endLine' => 18,
                            'description' => "Avoid unused local variables such as '\$y'.",
                            'rule' => 'UnusedLocalVariable',
                            'priority' => 3,
                        ],
                    ],
                ],
            ],
        ]);

        $psalmStdout = json_encode([
            [
                'severity' => 'error',
                'line_from' => 15,
                'line_to' => 15,
                'column_from' => 21,
                'type' => 'MissingReturnType',
                'message' => 'Method Tests\\Fixtures\\SarifBrokenCode\\BrokenA::a does not have a return type',
                'file_name' => $file,
                'file_path' => $file,
            ],
            [
                'severity' => 'error',
                'line_from' => 15,
                'line_to' => 15,
                'column_from' => 23,
                'type' => 'MissingParamType',
                'message' => 'Parameter $x has no provided type',
                'file_name' => $file,
                'file_path' => $file,
            ],
            [
                'severity' => 'error',
                'line_from' => 18,
                'line_to' => 18,
                'column_from' => 16,
                'type' => 'UndefinedVariable',
                'message' => 'Cannot find referenced variable $y',
                'file_name' => $file,
                'file_path' => $file,
            ],
        ]);

        $jobs = [
            $this->buildJobResult('phpstan-broken', 'phpstan', (string) $phpstanStdout),
            $this->buildJobResult('phpcs-broken', 'phpcs', (string) $phpcsStdout),
            $this->buildJobResult('phpmd-broken', 'phpmd', (string) $phpmdStdout),
            $this->buildJobResult('psalm-broken', 'psalm', (string) $psalmStdout),
        ];

        return new FlowResult('sarif-check', $jobs, '1s');
    }

    private function buildJobResult(string $name, string $type, string $stdout): JobResult
    {
        return new JobResult(
            $name,
            false,
            '',
            '1s',
            false,
            "vendor/bin/$type",
            $type,
            1,
            ['tests/Fixtures/sarif-broken-code'],
            false,
            null,
            $stdout
        );
    }

    /**
     * @param mixed $payload
     * @param mixed $schema
     * @return string[] List of human-readable validation errors (empty = valid)
     */
    private function validateAgainstSchema($payload, $schema): array
    {
        $validator = new Validator();
        $validator->validate($payload, $schema, Constraint::CHECK_MODE_TYPE_CAST);

        if ($validator->isValid()) {
            return [];
        }

        $messages = [];
        foreach ($validator->getErrors() as $error) {
            $property = $error['property'] !== '' ? $error['property'] : '(root)';
            $messages[] = "[{$property}] {$error['message']}";
        }
        return $messages;
    }
}
