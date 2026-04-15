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
}
