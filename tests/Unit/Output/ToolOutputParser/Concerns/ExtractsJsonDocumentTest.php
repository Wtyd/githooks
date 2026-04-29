<?php

declare(strict_types=1);

namespace Tests\Unit\Output\ToolOutputParser\Concerns;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\ToolOutputParser\Concerns\ExtractsJsonDocument;

/**
 * Cover the ExtractsJsonDocument trait directly via a host class so the
 * private extractJsonDocument() method is reachable. The trait is shared
 * across PHPStan / Psalm / Rector parsers; bugs here propagate.
 */
class ExtractsJsonDocumentTest extends TestCase
{
    /** @var object */
    private $host;

    protected function setUp(): void
    {
        $this->host = new class {
            use ExtractsJsonDocument;

            public function extract(string $stdout): string
            {
                return $this->extractJsonDocument($stdout);
            }
        };
    }

    /** @test */
    public function it_extracts_a_clean_json_document_unchanged(): void
    {
        $this->assertSame('{"a":1}', $this->host->extract('{"a":1}'));
    }

    /** @test */
    public function it_strips_human_prologue_before_the_opening_brace(): void
    {
        $input = "Note: tool warning\n{\"a\":1}";

        $this->assertSame('{"a":1}', $this->host->extract($input));
    }

    /** @test */
    public function it_strips_human_epilogue_after_the_closing_brace(): void
    {
        // Kills IncrementInteger / Minus mutants on `$end - $start + 1`
        // at line 27: with `+ 2` the slice would include the first
        // character of the epilogue; with `$end + $start + 1` it would
        // include all of it.
        $input = '{"a":1}trailing garbage';

        $this->assertSame('{"a":1}', $this->host->extract($input));
    }

    /** @test */
    public function it_strips_both_prologue_and_epilogue_around_a_mid_string_document(): void
    {
        // Same family of mutants on line 27 as above, but with non-zero
        // start to ensure the offset arithmetic is exercised.
        $input = "warning text\n{\"a\":1}\ntrailing garbage";

        $this->assertSame('{"a":1}', $this->host->extract($input));
    }

    /** @test */
    public function it_returns_empty_string_when_no_opening_brace_present(): void
    {
        // Kills the LogicalOr `||` -> `&&` mutant on the FIRST OR at
        // line 24 (`$start === false || $end === false || ...`):
        // with `&&`, an input that has `}` but no `{` would fall through
        // to substr() with $start === false.
        $this->assertSame('', $this->host->extract('abc}'));
    }

    /** @test */
    public function it_returns_empty_string_when_no_closing_brace_present(): void
    {
        // Kills the LogicalOr `||` -> `&&` mutant on the SECOND OR at
        // line 24 (`... || $end === false || $end < $start`): with
        // `&&`, an input that has `{` but no `}` would fall through and
        // substr would slice from start=0 with bogus length.
        $this->assertSame('', $this->host->extract('{abc'));
    }

    /** @test */
    public function it_returns_empty_string_when_input_has_neither_brace(): void
    {
        $this->assertSame('', $this->host->extract('plain text without json'));
    }

    /** @test */
    public function it_returns_empty_string_when_input_is_empty(): void
    {
        $this->assertSame('', $this->host->extract(''));
    }

    /** @test */
    public function it_keeps_nested_braces_inside_the_extracted_document(): void
    {
        // strpos returns the FIRST '{' and strrpos returns the LAST '}',
        // so nested objects survive correctly.
        $input = '{"outer":{"inner":1}}';

        $this->assertSame('{"outer":{"inner":1}}', $this->host->extract($input));
    }

    /** @test */
    public function it_extracts_from_first_open_to_last_close_when_multiple_documents_are_present(): void
    {
        // Note: the trait is intentionally lax — it does not validate
        // syntactic JSON, only the outermost brace span. Two documents
        // glued together get returned as one large slice; consumers
        // (PHPStan parser etc.) are responsible for validating.
        $input = '{"first":1}garbage{"second":2}';

        $this->assertSame('{"first":1}garbage{"second":2}', $this->host->extract($input));
    }
}
