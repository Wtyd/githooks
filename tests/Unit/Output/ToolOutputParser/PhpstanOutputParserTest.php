<?php

declare(strict_types=1);

namespace Tests\Unit\Output\ToolOutputParser;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\ToolOutputParser\PhpstanOutputParser;

class PhpstanOutputParserTest extends TestCase
{
    private PhpstanOutputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpstanOutputParser();
    }

    /** @test */
    function it_parses_phpstan_json_with_errors()
    {
        $json = json_encode([
            'totals' => ['errors' => 2, 'file_errors' => 2],
            'files' => [
                'src/User.php' => [
                    'errors' => 1,
                    'messages' => [
                        ['message' => 'Method getRole() not found', 'line' => 14, 'ignorable' => true],
                    ],
                ],
                'src/Order.php' => [
                    'errors' => 1,
                    'messages' => [
                        ['message' => 'Parameter $total expects int, string given', 'line' => 87, 'ignorable' => false],
                    ],
                ],
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpstan');

        $this->assertCount(2, $issues);
        $this->assertSame('src/User.php', $issues[0]->getFile());
        $this->assertSame(14, $issues[0]->getLine());
        $this->assertSame('Method getRole() not found', $issues[0]->getMessage());
        $this->assertSame('error', $issues[0]->getSeverity());
        $this->assertSame('phpstan', $issues[0]->getRuleId());
        $this->assertSame('src/Order.php', $issues[1]->getFile());
        $this->assertSame(87, $issues[1]->getLine());
    }

    /** @test */
    function it_returns_empty_for_clean_run()
    {
        $json = json_encode(['totals' => ['errors' => 0], 'files' => []]);

        $this->assertSame([], $this->parser->parse($json, 'phpstan'));
    }

    /** @test */
    function it_returns_empty_for_invalid_json()
    {
        $this->assertSame([], $this->parser->parse('not json', 'phpstan'));
    }

    /** @test */
    function it_returns_empty_for_empty_string()
    {
        $this->assertSame([], $this->parser->parse('', 'phpstan'));
    }

    /** @test */
    function it_returns_empty_when_array_missing_files_key()
    {
        $json = json_encode(['totals' => ['errors' => 0]]);

        $this->assertSame([], $this->parser->parse($json, 'phpstan'));
    }

    /** @test */
    function it_skips_file_entries_missing_messages_key()
    {
        $json = json_encode(['files' => ['src/A.php' => ['errors' => 1]]]);

        $this->assertSame([], $this->parser->parse($json, 'phpstan'));
    }

    /** @test */
    function it_skips_file_entries_that_are_not_arrays()
    {
        $json = json_encode(['files' => ['src/A.php' => 'broken']]);

        $this->assertSame([], $this->parser->parse($json, 'phpstan'));
    }

    /**
     * @test
     * @dataProvider messageMissingKeyProvider
     */
    function it_skips_message_entries_missing_required_keys(array $message)
    {
        $json = json_encode(['files' => ['src/A.php' => ['messages' => [$message]]]]);

        $this->assertSame([], $this->parser->parse($json, 'phpstan'));
    }

    public function messageMissingKeyProvider(): array
    {
        return [
            'missing line' => [['message' => 'x']],
            'missing message' => [['line' => 1]],
        ];
    }

    /**
     * @test
     * Kills L27 Continue→break: two file entries, first invalid and second valid —
     * `break` aborts the loop and drops the second.
     */
    function it_keeps_parsing_after_skipping_an_invalid_file_entry()
    {
        $json = json_encode([
            'files' => [
                'src/Broken.php' => 'not-an-array',
                'src/Good.php'   => ['messages' => [
                    ['line' => 42, 'message' => 'surviving'],
                ]
                ],
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpstan');

        $this->assertCount(1, $issues);
        $this->assertSame('src/Good.php', $issues[0]->getFile());
        $this->assertSame(42, $issues[0]->getLine());
        $this->assertSame('surviving', $issues[0]->getMessage());
    }

    /** @test */
    function it_uses_identifier_field_as_rule_id_when_present()
    {
        $json = json_encode(['files' => ['src/A.php' => ['messages' => [
            [
                'line' => 14,
                'message' => 'Undefined variable: $y',
                'identifier' => 'variable.undefined',
            ],
            [
                'line' => 11,
                'message' => 'Method has no return type specified.',
                'identifier' => 'missingType.return',
            ],
        ]
        ]
        ]
        ]);

        $issues = $this->parser->parse($json, 'phpstan');

        $this->assertCount(2, $issues);
        $this->assertSame('variable.undefined', $issues[0]->getRuleId());
        $this->assertSame('missingType.return', $issues[1]->getRuleId());
    }

    /** @test */
    function it_falls_back_to_generic_phpstan_rule_id_when_identifier_missing()
    {
        $json = json_encode(['files' => ['src/A.php' => ['messages' => [
            ['line' => 14, 'message' => 'x'],
            ['line' => 15, 'message' => 'y', 'identifier' => ''],
        ]
        ]
        ]
        ]);

        $issues = $this->parser->parse($json, 'phpstan');

        $this->assertCount(2, $issues);
        $this->assertSame('phpstan', $issues[0]->getRuleId(), 'missing identifier falls back');
        $this->assertSame('phpstan', $issues[1]->getRuleId(), 'empty identifier falls back too');
    }

    /**
     * @test
     * PHPStan 2.x prints a human preamble (on stderr, but merged with stdout
     * in some CI capture paths) before the JSON payload. The parser must
     * tolerate that and still surface the issues.
     */
    function it_tolerates_a_human_preamble_before_the_json_document()
    {
        $preamble = "Instructions for interpreting errors\n"
            . "---------\n\n"
            . "Each error has an associated identifier, like `argument.type`\n"
            . "or `return.missing`.\n\n"
            . "Do not add type casts just to silence errors.\n";
        $json = json_encode(['files' => ['src/User.php' => ['messages' => [
            ['line' => 14, 'message' => 'Undefined variable: $y'],
        ]
        ]
        ]
        ]);

        $issues = $this->parser->parse($preamble . $json, 'phpstan');

        $this->assertCount(1, $issues);
        $this->assertSame('src/User.php', $issues[0]->getFile());
        $this->assertSame(14, $issues[0]->getLine());
        $this->assertSame('Undefined variable: $y', $issues[0]->getMessage());
    }

    /**
     * @test
     * Kills L31 Continue→break on the messages inner loop.
     */
    function it_keeps_parsing_after_skipping_an_invalid_message_entry()
    {
        $json = json_encode([
            'files' => ['src/A.php' => ['messages' => [
                ['message' => 'only'], // invalid: no line
                ['line' => 88, 'message' => 'surviving'],
            ]
            ]
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpstan');

        $this->assertCount(1, $issues);
        $this->assertSame(88, $issues[0]->getLine());
        $this->assertSame('surviving', $issues[0]->getMessage());
    }
}
