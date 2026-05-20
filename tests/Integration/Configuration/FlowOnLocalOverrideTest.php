<?php

declare(strict_types=1);

namespace Tests\Integration\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Registry\ToolRegistry;

/**
 * FEAT-2 · Group E — override of `on` via githooks.local.php.
 *
 * Bidirectional override mirrors FEAT-1's JobRef semantics: `null` cancels an
 * inherited rule, missing key inherits, lists merge per index (heredado
 * caveat de array_replace_recursive, fuera de scope de FEAT-2).
 */
class FlowOnLocalOverrideTest extends TestCase
{
    private string $fixturesPath;
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->fixturesPath = sys_get_temp_dir() . '/githooks_on_override_' . uniqid();
        mkdir($this->fixturesPath, 0755, true);
        $this->registry = new ToolRegistry();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixturesPath . '/*') ?: []);
        @rmdir($this->fixturesPath);
    }

    /** @test */
    public function E1_shared_on_with_no_local_keeps_shared_on()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'ci' => [
            'on' => [
                'master' => ['execution' => 'full'],
                '*'      => ['execution' => 'fast-branch'],
            ],
            'jobs' => ['lint'],
        ],
    ],
    'jobs' => [
        'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
    ],
];
PHP;
        $this->writeMain($main);

        $rules = $this->parseFlowOn('ci');

        $this->assertNotNull($rules);
        $this->assertCount(2, $rules);
        $this->assertSame('master', $rules[0]->getPattern());
        $this->assertSame('full', $rules[0]->getExecutionMode());
        $this->assertSame('*', $rules[1]->getPattern());
        $this->assertSame('fast-branch', $rules[1]->getExecutionMode());
    }

    /** @test */
    public function E2_local_null_cancels_inherited_on()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'ci' => [
            'on' => [
                'master' => ['execution' => 'full'],
                '*'      => ['execution' => 'fast-branch'],
            ],
            'jobs' => ['lint'],
        ],
    ],
    'jobs' => [
        'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'ci' => [
            'on' => null,
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $rules = $this->parseFlowOn('ci');

        $this->assertNull($rules);
    }

    /** @test */
    public function E3_no_shared_with_local_on_adopts_local_on()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'ci' => [
            'jobs' => ['lint'],
        ],
    ],
    'jobs' => [
        'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'ci' => [
            'on' => [
                'master' => ['execution' => 'full'],
                '*'      => ['execution' => 'fast'],
            ],
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $rules = $this->parseFlowOn('ci');

        $this->assertNotNull($rules);
        $this->assertCount(2, $rules);
        $this->assertSame('master', $rules[0]->getPattern());
        $this->assertSame('full', $rules[0]->getExecutionMode());
    }

    /**
     * @test
     *
     * E4 documents the heredado caveat: when both shared and local declare
     * different `on` maps, PHP's array_replace_recursive merges the inner
     * pattern entries (asociative keys preserved, deep-merged values).
     */
    public function E4_local_pattern_overrides_shared_same_pattern()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'ci' => [
            'on' => [
                'master' => ['execution' => 'full'],
                '*'      => ['execution' => 'fast-branch'],
            ],
            'jobs' => ['lint'],
        ],
    ],
    'jobs' => [
        'lint' => ['type' => 'parallel-lint', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'ci' => [
            'on' => [
                'master' => ['execution' => 'fast'],
            ],
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $rules = $this->parseFlowOn('ci');

        $this->assertNotNull($rules);
        $this->assertCount(2, $rules);
        // master's execution comes from local; catch-all is preserved from shared.
        $this->assertSame('master', $rules[0]->getPattern());
        $this->assertSame('fast', $rules[0]->getExecutionMode());
        $this->assertSame('*', $rules[1]->getPattern());
        $this->assertSame('fast-branch', $rules[1]->getExecutionMode());
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

    /** @return \Wtyd\GitHooks\Configuration\FlowOnRule[]|null */
    private function parseFlowOn(string $flowName): ?array
    {
        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();
        $this->assertFalse(
            $result->hasErrors(),
            implode("\n", $result->getValidation()->getErrors())
        );
        $flow = $result->getFlows()[$flowName] ?? null;
        $this->assertNotNull($flow, "Flow '$flowName' not found");
        return $flow->getOn();
    }
}
