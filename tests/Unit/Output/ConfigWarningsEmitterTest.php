<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Output\ConfigWarningsEmitter;

/**
 * Unit tests for the {@see ConfigWarningsEmitter} class extracted from the
 * legacy {@see \Wtyd\GitHooks\App\Commands\Concerns\EmitsConfigWarnings} trait.
 *
 * Coverage targets:
 *  - emits a normal warning to stderr (via getErrorStyle) when output is a SymfonyStyle.
 *  - filters out warnings whose text contains `skipped` (deduplicated by the handler).
 *  - empty warnings list → no writes at all.
 *  - preserves the `<comment>Warning:</comment> X` formatting.
 *  - falls back to writeln on the raw output when not a SymfonyStyle.
 */
class ConfigWarningsEmitterTest extends TestCase
{
    private function makeStyle(BufferedOutput $buffer): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $buffer);
    }

    /** @test */
    public function it_emits_a_normal_warning(): void
    {
        $validation = $this->validationWith(['Deprecated option foo']);
        $buffer = new BufferedOutput();
        $style = $this->makeStyle($buffer);

        (new ConfigWarningsEmitter())->emit($validation, $style);

        $output = $buffer->fetch();
        $this->assertStringContainsString('Warning:', $output);
        $this->assertStringContainsString('Deprecated option foo', $output);
    }

    /** @test */
    public function it_filters_out_warnings_containing_skipped(): void
    {
        $validation = $this->validationWith([
            'Deprecated option foo',
            "Job 'lint' was skipped",
            'Tool bar not installed',
        ]);
        $buffer = new BufferedOutput();
        $style = $this->makeStyle($buffer);

        (new ConfigWarningsEmitter())->emit($validation, $style);

        $output = $buffer->fetch();
        $this->assertStringContainsString('Deprecated option foo', $output);
        $this->assertStringContainsString('Tool bar not installed', $output);
        $this->assertStringNotContainsString('was skipped', $output);
    }

    /** @test */
    public function it_writes_nothing_when_warnings_are_empty(): void
    {
        $buffer = new BufferedOutput();
        $style = $this->makeStyle($buffer);

        (new ConfigWarningsEmitter())->emit($this->validationWith([]), $style);

        $this->assertSame('', $buffer->fetch());
    }

    /** @test */
    public function it_preserves_the_comment_style_format(): void
    {
        $validation = $this->validationWith(['X']);
        $buffer = new BufferedOutput();
        $style = $this->makeStyle($buffer);
        // BufferedOutput without formatter strips tags by default in non-decorated mode;
        // assert against the raw decorated form by enabling decoration here.
        $buffer->setDecorated(true);

        (new ConfigWarningsEmitter())->emit($validation, $style);

        $output = $buffer->fetch();
        // SymfonyStyle prefixes/suffixes a blank line around block content; just
        // verify the 'Warning:' label and the warning body appear in order.
        $this->assertMatchesRegularExpression('/Warning:.*X/s', $output);
    }

    /** @test */
    public function it_falls_back_to_writeln_when_output_is_not_a_symfony_style(): void
    {
        $validation = $this->validationWith(['Plain warning']);
        $buffer = new BufferedOutput();

        (new ConfigWarningsEmitter())->emit($validation, $buffer);

        $output = $buffer->fetch();
        $this->assertStringContainsString('Plain warning', $output);
    }

    /**
     * @param string[] $warnings
     */
    private function validationWith(array $warnings): ValidationResult
    {
        $v = new ValidationResult();
        foreach ($warnings as $w) {
            $v->addWarning($w);
        }
        return $v;
    }
}
