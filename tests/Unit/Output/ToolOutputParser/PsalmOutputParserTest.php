<?php

declare(strict_types=1);

namespace Tests\Unit\Output\ToolOutputParser;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\ToolOutputParser\PsalmOutputParser;

class PsalmOutputParserTest extends TestCase
{
    private PsalmOutputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PsalmOutputParser();
    }

    /** @test */
    function it_parses_psalm_json_output()
    {
        $json = json_encode([
            [
                'severity' => 'error',
                'line_from' => 10,
                'line_to' => 12,
                'type' => 'UndefinedVariable',
                'message' => 'Cannot find referenced variable $undefined',
                'file_name' => 'src/Foo.php',
                'file_path' => '/var/www/html1/src/Foo.php',
                'column_from' => 5,
                'column_to' => 15,
            ],
            [
                'severity' => 'info',
                'line_from' => 20,
                'type' => 'PossiblyUnusedMethod',
                'message' => 'Method bar is never used',
                'file_name' => 'src/Bar.php',
                'file_path' => '/var/www/html1/src/Bar.php',
                'column_from' => 21,
            ],
        ]);

        $issues = $this->parser->parse($json, 'psalm');

        $this->assertCount(2, $issues);
        $this->assertSame('src/Foo.php', $issues[0]->getFile());
        $this->assertSame(10, $issues[0]->getLine());
        $this->assertSame(12, $issues[0]->getEndLine());
        $this->assertSame(5, $issues[0]->getColumn());
        $this->assertSame('error', $issues[0]->getSeverity());
        $this->assertSame('UndefinedVariable', $issues[0]->getRuleId());
        $this->assertSame('info', $issues[1]->getSeverity());
    }

    /** @test */
    function it_returns_empty_for_clean_run()
    {
        $this->assertSame([], $this->parser->parse('[]', 'psalm'));
    }

    /** @test */
    function it_returns_empty_for_invalid_json()
    {
        $this->assertSame([], $this->parser->parse('not json', 'psalm'));
    }

    /** @test */
    function it_returns_empty_for_empty_string()
    {
        $this->assertSame([], $this->parser->parse('', 'psalm'));
    }

    /**
     * @test
     * @dataProvider itemMissingKeyProvider
     */
    function it_skips_items_missing_required_keys(array $item)
    {
        $json = json_encode([$item]);

        $this->assertSame([], $this->parser->parse($json, 'psalm'));
    }

    public function itemMissingKeyProvider(): array
    {
        return [
            'missing file_name' => [['line_from' => 1, 'message' => 'x']],
            'missing line_from' => [['file_name' => 'A.php', 'message' => 'x']],
            'missing message' => [['file_name' => 'A.php', 'line_from' => 1]],
            'not an array' => [[]],
        ];
    }

    /** @test */
    function it_defaults_severity_to_info_when_not_error()
    {
        $json = json_encode([[
            'file_name' => 'A.php',
            'line_from' => 1,
            'message' => 'x',
            'severity' => 'info',
        ]]);

        $issues = $this->parser->parse($json, 'psalm');

        $this->assertSame('info', $issues[0]->getSeverity());
    }

    /** @test */
    function it_defaults_severity_to_info_when_severity_missing()
    {
        $json = json_encode([[
            'file_name' => 'A.php',
            'line_from' => 1,
            'message' => 'x',
        ]]);

        $issues = $this->parser->parse($json, 'psalm');

        $this->assertSame('info', $issues[0]->getSeverity());
    }

    /** @test */
    function it_casts_line_from_and_line_to_to_int()
    {
        $json = json_encode([[
            'file_name' => 'A.php',
            'line_from' => '10',
            'line_to' => '15',
            'column_from' => '3',
            'message' => 'x',
        ]]);

        $issues = $this->parser->parse($json, 'psalm');

        $this->assertSame(10, $issues[0]->getLine());
        $this->assertSame(15, $issues[0]->getEndLine());
        $this->assertSame(3, $issues[0]->getColumn());
    }

    /** @test */
    function it_defaults_rule_id_to_psalm_when_type_missing()
    {
        $json = json_encode([[
            'file_name' => 'A.php',
            'line_from' => 1,
            'message' => 'x',
        ]]);

        $issues = $this->parser->parse($json, 'psalm');

        $this->assertSame('psalm', $issues[0]->getRuleId());
    }
}
