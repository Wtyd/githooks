<?php

declare(strict_types=1);

namespace Tests\Unit\Output\Inspection;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Output\Inspection\ConfigCheckJsonFormatter;
use Wtyd\GitHooks\Output\Inspection\ConfigCheckResult;

class ConfigCheckJsonFormatterTest extends UnitTestCase
{
    private function diagnostics(array $overrides = []): array
    {
        return array_merge([
            'errors' => [],
            'warnings' => [],
            'deprecations' => [],
            'hint' => null,
        ], $overrides);
    }

    /** @test */
    public function v3_valid_config_serialises_full_payload_without_hint()
    {
        $result = ConfigCheckResult::forV3(
            'githooks.php',
            null,
            ['processes' => 8, 'failFast' => false],
            [['event' => 'pre-commit', 'targets' => [['target' => 'qa']]]],
            [['name' => 'qa', 'meta' => false, 'jobs' => ['phpstan'], 'flows' => []]],
            [['name' => 'phpstan', 'command' => 'phpstan analyse', 'status' => 'ok', 'issues' => []]],
            $this->diagnostics()
        );

        $payload = json_decode((new ConfigCheckJsonFormatter())->format($result), true);

        $this->assertSame([
            'version' => 1,
            'valid' => true,
            'legacy' => false,
            'file' => ['path' => 'githooks.php', 'localPath' => null],
            'options' => ['processes' => 8, 'failFast' => false],
            'hooks' => [['event' => 'pre-commit', 'targets' => [['target' => 'qa']]]],
            'flows' => [['name' => 'qa', 'meta' => false, 'jobs' => ['phpstan'], 'flows' => []]],
            'jobs' => [['name' => 'phpstan', 'command' => 'phpstan analyse', 'status' => 'ok', 'issues' => []]],
            'errors' => [],
            'warnings' => [],
            'deprecations' => [],
        ], $payload);
        $this->assertArrayNotHasKey('hint', $payload);
    }

    /** @test */
    public function errors_make_valid_false_and_surface_in_payload()
    {
        $result = ConfigCheckResult::forV3(
            'githooks.php',
            'githooks.local.php',
            [],
            [],
            [],
            [['name' => 'phpstan', 'command' => '(error: boom)', 'status' => 'error', 'issues' => ['boom']]],
            $this->diagnostics(['errors' => ['bad report path'], 'warnings' => ['heads up']])
        );

        $payload = json_decode((new ConfigCheckJsonFormatter())->format($result), true);

        $this->assertFalse($payload['valid']);
        $this->assertSame(['bad report path'], $payload['errors']);
        $this->assertSame(['heads up'], $payload['warnings']);
        $this->assertSame('error', $payload['jobs'][0]['status']);
        $this->assertSame(['boom'], $payload['jobs'][0]['issues']);
        $this->assertSame('githooks.local.php', $payload['file']['localPath']);
    }

    /** @test */
    public function deprecations_are_serialised_structured()
    {
        $result = ConfigCheckResult::forV3(
            'githooks.php',
            null,
            [],
            [],
            [],
            [],
            $this->diagnostics(['deprecations' => [['job' => 'phpstan', 'oldKey' => 'paths', 'newKey' => 'files']]])
        );

        $payload = json_decode((new ConfigCheckJsonFormatter())->format($result), true);

        $this->assertSame([['job' => 'phpstan', 'oldKey' => 'paths', 'newKey' => 'files']], $payload['deprecations']);
    }

    /** @test */
    public function legacy_config_omits_v3_blocks_and_carries_hint()
    {
        $result = ConfigCheckResult::legacy(
            'githooks.yml',
            null,
            $this->diagnostics(['hint' => "Run 'githooks conf:migrate' to upgrade to v3."])
        );

        $payload = json_decode((new ConfigCheckJsonFormatter())->format($result), true);

        $this->assertSame([
            'version' => 1,
            'valid' => true,
            'legacy' => true,
            'file' => ['path' => 'githooks.yml', 'localPath' => null],
            'errors' => [],
            'warnings' => [],
            'deprecations' => [],
            'hint' => "Run 'githooks conf:migrate' to upgrade to v3.",
        ], $payload);
        $this->assertArrayNotHasKey('options', $payload);
        $this->assertArrayNotHasKey('jobs', $payload);
    }

    /** @test */
    public function legacy_config_with_errors_is_invalid()
    {
        $result = ConfigCheckResult::legacy(
            'githooks.yml',
            null,
            $this->diagnostics(['errors' => ['Tool xyz is not supported'], 'hint' => 'migrate'])
        );

        $payload = json_decode((new ConfigCheckJsonFormatter())->format($result), true);

        $this->assertFalse($payload['valid']);
        $this->assertSame(['Tool xyz is not supported'], $payload['errors']);
    }
}
