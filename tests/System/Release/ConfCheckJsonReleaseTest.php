<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * 3.7 — `conf:check --format=json` must serialise the FULL execution-option set in
 * its `options` block, with the two budgets shaped symmetrically: both `null` when
 * unset, both objects (`timeBudget{warnAfter,failAfter}` / `memoryBudget{warnAbove,
 * failAbove}`) when declared. The payload the bundled binary emits is what CI and
 * tooling actually parse, so this asserts the complete-and-symmetric schema is
 * embedded in the compiled `.phar`, not merely present in the source.
 *
 * Required as @group release because `timeBudget` was missing from the payload
 * while `memoryBudget` was present; an asserted regression here means the fix is
 * compiled into the distributed binary.
 *
 * @group release
 */
class ConfCheckJsonReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configPath = self::TESTS_PATH . '/githooks.php';
    }

    /** @test */
    public function options_block_exposes_the_full_set_with_symmetric_budgets(): void
    {
        $this->configurationFileBuilder
            ->enableV3Mode()
            ->setV3GlobalOptions([
                'time-budget'   => ['warn-after' => 10, 'fail-after' => 20],
                'memory-budget' => ['warn-above' => 256, 'fail-above' => 512],
            ])
            ->setV3Flows(['qa' => ['jobs' => ['noop']]])
            ->setV3Jobs(['noop' => ['type' => 'custom', 'script' => 'true']]);
        file_put_contents($this->configPath, $this->configurationFileBuilder->buildV3Php());

        passthru("$this->githooks conf:check --format=json --config=$this->configPath 2>/dev/null", $exitCode);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($this->getActualOutput(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('options', $decoded);

        $expectedKeys = [
            'processes', 'failFast', 'mainBranch', 'fastBranchFallback', 'executablePrefix',
            'reports', 'timeBudget', 'memoryBudget', 'allocator', 'stats', 'historySize',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $decoded['options'], "options must expose '$key' in the .phar");
        }

        // The regression the fix closes: `timeBudget` was absent while `memoryBudget`
        // was serialised. Both budgets must now round-trip symmetrically as objects.
        $this->assertSame(['warnAfter' => 10, 'failAfter' => 20], $decoded['options']['timeBudget']);
        $this->assertSame(256, $decoded['options']['memoryBudget']['warnAbove']);
        $this->assertSame(512, $decoded['options']['memoryBudget']['failAbove']);
    }
}
