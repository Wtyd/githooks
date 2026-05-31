<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\OutputFormats;

class OutputFormatsTest extends TestCase
{
    /** @test claude-code is accepted by --format but is not a report-file target. */
    function claude_code_is_supported_but_not_structured()
    {
        $this->assertContains('claude-code', OutputFormats::SUPPORTED);
        $this->assertNotContains('claude-code', OutputFormats::STRUCTURED);
    }

    /**
     * @test claude-code always exits 0 (the stop-hook JSON only takes effect on exit 0);
     *       every other format keeps the success?0:1 mapping.
     * @dataProvider exitCodeCases
     */
    function exit_code_for_maps_format_and_outcome(string $format, bool $success, int $expected)
    {
        $this->assertSame($expected, OutputFormats::exitCodeFor($format, $success));
    }

    /** @return array<string, array{0: string, 1: bool, 2: int}> */
    public function exitCodeCases(): array
    {
        return [
            'claude-code pass'  => ['claude-code', true, 0],
            'claude-code fail'  => ['claude-code', false, 0],
            'text pass'         => ['text', true, 0],
            'text fail'         => ['text', false, 1],
            'json pass'         => ['json', true, 0],
            'json fail'         => ['json', false, 1],
            'empty (default) fail' => ['', false, 1],
        ];
    }
}
