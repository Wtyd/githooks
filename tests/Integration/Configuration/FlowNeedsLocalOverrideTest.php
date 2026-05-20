<?php

declare(strict_types=1);

namespace Tests\Integration\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Registry\ToolRegistry;

/**
 * FEAT-3 · Group J — override of `needs` via githooks.local.php.
 *
 * Same semantics as FEAT-1's only-files/exclude-files: missing key inherits,
 * `null` cancels the inherited list, declared local list replaces by index
 * (heredado caveat of array_replace_recursive — out of scope to fix).
 */
class FlowNeedsLocalOverrideTest extends TestCase
{
    private string $fixturesPath;
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->fixturesPath = sys_get_temp_dir() . '/githooks_needs_override_' . uniqid();
        mkdir($this->fixturesPath, 0755, true);
        $this->registry = new ToolRegistry();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixturesPath . '/*') ?: []);
        @rmdir($this->fixturesPath);
    }

    /** @test */
    public function J1_shared_needs_with_no_local_keeps_shared_needs()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                'compile',
                ['job' => 'lint', 'needs' => ['compile']],
            ],
        ],
    ],
    'jobs' => [
        'compile' => ['type' => 'parallel-lint', 'paths' => ['src']],
        'lint'    => ['type' => 'parallel-lint', 'paths' => ['src']],
    ],
];
PHP;
        $this->writeMain($main);

        $refs = $this->parseFlowJobRefs('qa');

        $this->assertCount(2, $refs);
        $this->assertSame('lint', $refs[1]->getTarget());
        $this->assertSame(['compile'], $refs[1]->getNeeds());
    }

    /** @test */
    public function J2_local_null_cancels_inherited_needs()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                'compile',
                ['job' => 'lint', 'needs' => ['compile']],
            ],
        ],
    ],
    'jobs' => [
        'compile' => ['type' => 'parallel-lint', 'paths' => ['src']],
        'lint'    => ['type' => 'parallel-lint', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                'compile',
                ['job' => 'lint', 'needs' => null],
            ],
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $refs = $this->parseFlowJobRefs('qa');

        $this->assertSame('lint', $refs[1]->getTarget());
        $this->assertSame([], $refs[1]->getNeeds());
    }

    /** @test */
    public function J3_no_shared_with_local_needs_adopts_local_needs()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => ['compile', 'lint'],
        ],
    ],
    'jobs' => [
        'compile' => ['type' => 'parallel-lint', 'paths' => ['src']],
        'lint'    => ['type' => 'parallel-lint', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                'compile',
                ['job' => 'lint', 'needs' => ['compile']],
            ],
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $refs = $this->parseFlowJobRefs('qa');

        $this->assertSame('lint', $refs[1]->getTarget());
        $this->assertSame(['compile'], $refs[1]->getNeeds());
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function writeMain(string $contents): void
    {
        file_put_contents($this->fixturesPath . '/githooks.php', $contents);
    }

    private function writeLocal(string $contents): void
    {
        file_put_contents($this->fixturesPath . '/githooks.local.php', $contents);
    }

    /** @return \Wtyd\GitHooks\Configuration\JobRef[] */
    private function parseFlowJobRefs(string $flowName): array
    {
        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();
        $this->assertFalse(
            $result->hasErrors(),
            implode("\n", $result->getValidation()->getErrors())
        );
        $flow = $result->getFlows()[$flowName] ?? null;
        $this->assertNotNull($flow);
        return $flow->getJobReferences();
    }
}
