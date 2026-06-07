<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Jobs\PhpcsJob;
use Wtyd\GitHooks\Jobs\PhpstanJob;

/**
 * Test de regresión que doble la salida real de las QA tools usando los
 * fixtures capturados durante el QA de v3.3.3 (BUG-1).
 *
 * Los fixtures viven en tests/fixtures/tool-outputs/{tool}/{scenario}.{exit,stdout,stderr,cmd}
 * y son outputs literales de ejecuciones reales contra:
 *   - phpstan 2.2.x-dev
 *   - phpcs (Squiz) 3.13.6
 *   - phpcs (PHPCSStandards) 4.0.1
 *
 * Si una versión futura cambia el wording del marker o el exit code, este
 * test falla y avisa antes de que el bug del cliente vuelva en silencio.
 *
 * La tabla de factores que cubre vive en {@see factors-empty-input.md}.
 */
class ToolOutputFixturesRegressionTest extends UnitTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/tool-outputs';

    /**
     * @test
     * @dataProvider phpstanFixtures
     */
    public function phpstan_empty_input_heuristic_against_real_tool_output(
        string $scenario,
        bool $expectedTolerated
    ): void {
        [$exit, $output] = $this->loadFixture('phpstan', $scenario);

        $job = new PhpstanJob(new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]));

        $this->assertSame(
            $expectedTolerated,
            $job->isEmptyInputTolerated($exit, $output),
            "Fixture phpstan/$scenario (exit=$exit) classified incorrectly"
        );
    }

    /** @return array<string, array{string, bool}> */
    public function phpstanFixtures(): array
    {
        return [
            'A: valid php (success exit 0) → NOT tolerated' => ['A_valid_php', false],
            'B: php out of scope (success exit 0) → NOT tolerated' => ['B_php_out_of_scope', false],
            'C: markdown input (success exit 0) → NOT tolerated' => ['C_md', false],
            'D: BUG-1 trigger (exit 1 + marker on stderr) → tolerated' => ['D_excluded_all', true],
        ];
    }

    /**
     * Squiz 3.13.x devuelve exit 0 silencioso aunque `--ignore` cubra el
     * input por completo. Ningún escenario debe reinterpretarse — la defensiva
     * sigue activa para el día que cambien al modo "exit 16" del fork.
     *
     * @test
     * @dataProvider phpcsSquiz313xFixtures
     */
    public function phpcs_squiz_3_13_x_silent_outputs_are_never_tolerated(string $scenario): void
    {
        [$exit, $output] = $this->loadFixture('phpcs', $scenario);

        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $this->assertFalse(
            $job->isEmptyInputTolerated($exit, $output),
            "Fixture phpcs/$scenario (exit=$exit) should NOT be tolerated — Squiz 3.13.x is silent"
        );
    }

    /** @return array<string, array{string}> */
    public function phpcsSquiz313xFixtures(): array
    {
        return [
            'A: valid php' => ['A_valid_php'],
            'B: php out of scope' => ['B_php_out_of_scope'],
            'C: markdown input' => ['C_md'],
            'D: --ignore covers all (3.13.x silent: exit 0)' => ['D_all_ignored'],
            'E: mixed md + php' => ['E_mixed_md_php'],
        ];
    }

    /**
     * Clase patógena. PHPCSStandards 4.0.1 (fork) sí devuelve exit 16 con
     * `ERROR: All specified files were excluded`. La heurística defensiva
     * (exit ∈ {1,2,3,16}) lo cubre. Mata el mutante `Identical→NotIdentical`
     * sobre el `in_array(exitCode, [1,2,3,16], true)`.
     *
     * @test
     */
    public function phpcs_phpcsstandards_4_0_1_exit_16_with_marker_is_tolerated(): void
    {
        [$exit, $output] = $this->loadFixture('phpcs-4.0.1', 'D_all_ignored');

        // Sanity: el fixture debe seguir conteniendo exit 16. Si una recaptura
        // futura lo cambia, el test falla con un mensaje claro.
        $this->assertSame(16, $exit, 'fixture phpcs-4.0.1/D_all_ignored exit code must be 16');
        $this->assertStringContainsString(
            'All specified files were excluded',
            $output,
            'fixture phpcs-4.0.1/D_all_ignored must contain the marker on stderr'
        );

        $job = new PhpcsJob(new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]));

        $this->assertTrue(
            $job->isEmptyInputTolerated($exit, $output),
            'PHPCSStandards 4.0.1 exit 16 + marker must be tolerated by defensive heuristic'
        );
    }

    /**
     * Carga un fixture devolviendo (exitCode, output) donde output replica
     * la concatenación que hace JobExecutor/FlowExecutor: stdout + stderr.
     *
     * @return array{int, string}
     */
    private function loadFixture(string $tool, string $scenario): array
    {
        $base = self::FIXTURES . "/$tool/$scenario";

        $exitFile = "$base.exit";
        $stdoutFile = "$base.stdout";
        $stderrFile = "$base.stderr";

        $this->assertFileExists($exitFile, "fixture not found: $exitFile");
        $this->assertFileExists($stdoutFile, "fixture not found: $stdoutFile");
        $this->assertFileExists($stderrFile, "fixture not found: $stderrFile");

        $exit = (int) trim((string) file_get_contents($exitFile));
        $output = (string) file_get_contents($stdoutFile) . (string) file_get_contents($stderrFile);

        return [$exit, $output];
    }
}
