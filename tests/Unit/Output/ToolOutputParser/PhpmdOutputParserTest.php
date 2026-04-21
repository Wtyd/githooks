<?php

declare(strict_types=1);

namespace Tests\Unit\Output\ToolOutputParser;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\ToolOutputParser\PhpmdOutputParser;

class PhpmdOutputParserTest extends TestCase
{
    private PhpmdOutputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpmdOutputParser();
    }

    /** @test */
    function it_parses_phpmd_json_with_violations()
    {
        $cwd = getcwd();
        $json = json_encode([
            'version' => '2.15.0',
            'package' => 'phpmd',
            'files' => [
                [
                    'file' => $cwd . '/src/Service.php',
                    'violations' => [
                        [
                            'beginLine' => 15,
                            'endLine' => 45,
                            'description' => 'The method doSomething() has a Cyclomatic Complexity of 12.',
                            'rule' => 'CyclomaticComplexity',
                            'ruleSet' => 'Code Size Rules',
                            'priority' => 3,
                        ],
                        [
                            'beginLine' => 50,
                            'endLine' => 50,
                            'description' => 'Avoid unused local variables such as \'$temp\'.',
                            'rule' => 'UnusedLocalVariable',
                            'ruleSet' => 'Unused Code Rules',
                            'priority' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpmd');

        $this->assertCount(2, $issues);
        $this->assertSame('src/Service.php', $issues[0]->getFile());
        $this->assertSame(15, $issues[0]->getLine());
        $this->assertSame(45, $issues[0]->getEndLine());
        $this->assertSame('warning', $issues[0]->getSeverity());
        $this->assertSame('CyclomaticComplexity', $issues[0]->getRuleId());
        $this->assertSame('error', $issues[1]->getSeverity());
    }

    /** @test */
    function it_returns_empty_for_clean_run()
    {
        $json = json_encode(['version' => '2.15.0', 'package' => 'phpmd', 'files' => []]);

        $this->assertSame([], $this->parser->parse($json, 'phpmd'));
    }

    /** @test */
    function it_returns_empty_for_invalid_json()
    {
        $this->assertSame([], $this->parser->parse('not json', 'phpmd'));
    }

    /** @test */
    function it_returns_empty_for_empty_string()
    {
        $this->assertSame([], $this->parser->parse('', 'phpmd'));
    }

    /** @test */
    function it_returns_empty_when_array_missing_files_key()
    {
        $json = json_encode(['version' => '2.15.0']);

        $this->assertSame([], $this->parser->parse($json, 'phpmd'));
    }

    /**
     * @test
     * @dataProvider fileEntryMissingKeyProvider
     */
    function it_skips_file_entries_missing_required_keys(array $fileEntry)
    {
        $json = json_encode(['files' => [$fileEntry]]);

        $this->assertSame([], $this->parser->parse($json, 'phpmd'));
    }

    public function fileEntryMissingKeyProvider(): array
    {
        return [
            'missing file' => [['violations' => []]],
            'missing violations' => [['file' => '/abs/A.php']],
            'not an array' => [[]],
        ];
    }

    /**
     * @test
     * @dataProvider violationMissingKeyProvider
     */
    function it_skips_violations_missing_required_keys(array $violation)
    {
        $json = json_encode(['files' => [[
            'file' => '/abs/A.php',
            'violations' => [$violation],
        ]
        ]
        ]);

        $this->assertSame([], $this->parser->parse($json, 'phpmd'));
    }

    public function violationMissingKeyProvider(): array
    {
        return [
            'missing beginLine' => [['description' => 'x', 'rule' => 'R']],
            'missing description' => [['beginLine' => 10, 'rule' => 'R']],
        ];
    }

    /**
     * @test
     * @dataProvider priorityToSeverityProvider
     */
    function it_maps_priority_to_severity(int $priority, string $expected)
    {
        $json = json_encode(['files' => [[
            'file' => '/abs/A.php',
            'violations' => [[
                'beginLine' => 10,
                'description' => 'x',
                'priority' => $priority,
            ]
            ],
        ]
        ]
        ]);

        $issues = $this->parser->parse($json, 'phpmd');

        $this->assertSame($expected, $issues[0]->getSeverity());
    }

    public function priorityToSeverityProvider(): array
    {
        return [
            'priority 1 is error' => [1, 'error'],
            'priority 2 is error (boundary)' => [2, 'error'],
            'priority 3 is warning' => [3, 'warning'],
            'priority 4 is warning' => [4, 'warning'],
        ];
    }

    /** @test */
    function it_defaults_priority_to_3_when_missing_producing_warning()
    {
        $json = json_encode(['files' => [[
            'file' => '/abs/A.php',
            'violations' => [[
                'beginLine' => 10,
                'description' => 'x',
            ]
            ],
        ]
        ]
        ]);

        $issues = $this->parser->parse($json, 'phpmd');

        $this->assertSame('warning', $issues[0]->getSeverity());
    }

    /** @test */
    function it_defaults_rule_id_to_phpmd_when_missing()
    {
        $json = json_encode(['files' => [[
            'file' => '/abs/A.php',
            'violations' => [[
                'beginLine' => 10,
                'description' => 'x',
            ]
            ],
        ]
        ]
        ]);

        $issues = $this->parser->parse($json, 'phpmd');

        $this->assertSame('phpmd', $issues[0]->getRuleId());
    }

    /**
     * @test
     * Kills L33 Continue→break: two file entries, first invalid and second valid —
     * `break` drops the second file and the test detects it.
     */
    function it_keeps_parsing_after_skipping_an_invalid_file_entry()
    {
        $json = json_encode(['files' => [
            ['file' => '/abs/Broken.php'], // invalid: no violations key
            [
                'file'       => '/abs/Good.php',
                'violations' => [[
                    'beginLine' => 10,
                    'description' => 'surviving',
                    'rule' => 'SurvivingRule',
                ]
                ],
            ],
        ]
        ]);

        $issues = $this->parser->parse($json, 'phpmd');

        $this->assertCount(1, $issues);
        $this->assertSame(10, $issues[0]->getLine());
        $this->assertSame('surviving', $issues[0]->getMessage());
        $this->assertSame('SurvivingRule', $issues[0]->getRuleId());
    }

    /**
     * @test
     * Kills L39 Continue→break on the violations inner loop.
     */
    function it_keeps_parsing_after_skipping_an_invalid_violation()
    {
        $json = json_encode(['files' => [[
            'file'       => '/abs/A.php',
            'violations' => [
                ['rule' => 'R'], // invalid: no beginLine/description
                [
                    'beginLine'   => 55,
                    'description' => 'surviving',
                    'rule'        => 'S',
                ],
            ],
        ]
        ]
        ]);

        $issues = $this->parser->parse($json, 'phpmd');

        $this->assertCount(1, $issues);
        $this->assertSame(55, $issues[0]->getLine());
        $this->assertSame('surviving', $issues[0]->getMessage());
    }

    /** @test */
    function it_makes_relative_path_when_cwd_has_trailing_slash()
    {
        $cwd = getcwd();
        $json = json_encode(['files' => [[
            'file' => $cwd . '/src/X.php',
            'violations' => [[
                'beginLine' => 10,
                'description' => 'x',
                'priority' => 3,
            ]
            ],
        ]
        ]
        ]);

        $issues = $this->parser->parse($json, 'phpmd');

        $this->assertSame('src/X.php', $issues[0]->getFile());
    }
}
