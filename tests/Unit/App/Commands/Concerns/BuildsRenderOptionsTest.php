<?php

declare(strict_types=1);

namespace Tests\Unit\App\Commands\Concerns;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Output\RenderOptions;

/**
 * `--stats-sort` is an enumerated flag and has to behave like the rest of them
 * (`--allocator`, `--format`, a pest job's `runner`): take the declared value,
 * and say something when it cannot. `RenderOptions` falls back to `exec` for
 * anything it does not recognise, which on its own turns a typo into a table
 * quietly sorted by something other than what was asked for.
 */
class BuildsRenderOptionsTest extends UnitTestCase
{
    /**
     * @test
     * @dataProvider statsSortCases
     *
     * @param array<string, mixed> $options
     */
    public function stats_sort_takes_the_declared_value_or_falls_back_out_loud(
        array $options,
        string $expectedSort,
        bool $expectsWarning
    ): void {
        $double = new BuildsRenderOptionsCommandDouble();
        $double->options = ['format' => 'text'] + $options;

        $rendered = $double->call();

        $this->assertSame($expectedSort, $rendered->statsSort);

        if (!$expectsWarning) {
            $this->assertSame([], $double->errLines, 'a valid value must not warn');
            return;
        }

        $this->assertCount(1, $double->errLines);
        $this->assertStringContainsString('--stats-sort expects one of: exec, name, type', $double->errLines[0]);
        $this->assertStringContainsString('Falling back to exec', $double->errLines[0]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: bool}>
     */
    public function statsSortCases(): array
    {
        return [
            // options,                      effective sort,                warns
            'flag absent'      => [[], RenderOptions::STATS_SORT_EXEC, false],
            'empty value'      => [['stats-sort' => ''], RenderOptions::STATS_SORT_EXEC, false],
            'exec declared'    => [['stats-sort' => 'exec'], RenderOptions::STATS_SORT_EXEC, false],
            'name declared'    => [['stats-sort' => 'name'], RenderOptions::STATS_SORT_NAME, false],
            'type declared'    => [['stats-sort' => 'type'], RenderOptions::STATS_SORT_TYPE, false],
            'unknown value'    => [['stats-sort' => 'inventado'], RenderOptions::STATS_SORT_EXEC, true],
            'near-miss typo'   => [['stats-sort' => 'names'], RenderOptions::STATS_SORT_EXEC, true],
            'wrong case'       => [['stats-sort' => 'NAME'], RenderOptions::STATS_SORT_EXEC, true],
        ];
    }
}
