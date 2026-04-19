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
}
