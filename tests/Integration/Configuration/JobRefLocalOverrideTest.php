<?php

declare(strict_types=1);

namespace Tests\Integration\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Registry\ToolRegistry;

/**
 * FEAT-1 · Group D — override of `only-files` / `exclude-files` via githooks.local.php.
 *
 * Documents the merge semantics and the index-merge caveat inherited from
 * `array_replace_recursive`. The override is bidirectional in the limit cases
 * (null ↔ list) but degrades to per-index merge when both sides declare a
 * list of distinct content (D6) — caveat documented, out of scope to fix here.
 */
class JobRefLocalOverrideTest extends TestCase
{
    private string $fixturesPath;
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->fixturesPath = sys_get_temp_dir() . '/githooks_jobref_override_' . uniqid();
        mkdir($this->fixturesPath, 0755, true);
        $this->registry = new ToolRegistry();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixturesPath . '/*') ?: []);
        @rmdir($this->fixturesPath);
    }

    /** @test */
    public function D1_shared_rule_with_no_local_keeps_shared_rule()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => ['src/A/**']],
            ],
        ],
    ],
    'jobs' => [
        'tests_a' => ['type' => 'phpstan', 'paths' => ['src/A']],
    ],
];
PHP;
        $this->writeMain($main);

        $refs = $this->parseFlowJobRefs('qa');

        $this->assertCount(1, $refs);
        $this->assertSame('tests_a', $refs[0]->getTarget());
        $this->assertSame(['src/A/**'], $refs[0]->getOnlyFiles());
    }

    /** @test */
    public function D2_local_null_cancels_shared_rule()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => ['src/A/**']],
            ],
        ],
    ],
    'jobs' => [
        'tests_a' => ['type' => 'phpstan', 'paths' => ['src/A']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => null],
            ],
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $refs = $this->parseFlowJobRefs('qa');

        $this->assertCount(1, $refs);
        $this->assertSame('tests_a', $refs[0]->getTarget());
        $this->assertNull($refs[0]->getOnlyFiles());
        $this->assertFalse($refs[0]->hasAdmissionRules());
    }

    /** @test */
    public function D3_shared_null_with_local_list_keeps_local_list()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => null],
            ],
        ],
    ],
    'jobs' => [
        'tests_a' => ['type' => 'phpstan', 'paths' => ['src/A']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => ['src/**']],
            ],
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $refs = $this->parseFlowJobRefs('qa');

        $this->assertSame(['src/**'], $refs[0]->getOnlyFiles());
    }

    /** @test */
    public function D4_no_shared_rule_with_local_list_adopts_local_list()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a'],
            ],
        ],
    ],
    'jobs' => [
        'tests_a' => ['type' => 'phpstan', 'paths' => ['src/A']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => ['src/X/**']],
            ],
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $refs = $this->parseFlowJobRefs('qa');

        $this->assertSame(['src/X/**'], $refs[0]->getOnlyFiles());
    }

    /** @test */
    public function D5_lists_of_same_length_replace_cleanly()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => ['src/A/**']],
            ],
        ],
    ],
    'jobs' => [
        'tests_a' => ['type' => 'phpstan', 'paths' => ['src/A']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => ['src/X/**']],
            ],
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $refs = $this->parseFlowJobRefs('qa');

        $this->assertSame(['src/X/**'], $refs[0]->getOnlyFiles());
    }

    /**
     * @test
     *
     * D6 — the known caveat of array_replace_recursive: lists merge per index,
     * not as a whole. Documents the actual behaviour so anyone who refactors
     * the merge later has a regression check. The recommendation in docs is
     * "declare null in shared and move the list to local" when the user wants
     * a clean replace and the shared list is longer than the local one.
     */
    public function D6_lists_of_different_length_merge_per_index_caveat()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => ['src/A/**', 'composer.json']],
            ],
        ],
    ],
    'jobs' => [
        'tests_a' => ['type' => 'phpstan', 'paths' => ['src/A']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => [
            'jobs' => [
                ['job' => 'tests_a', 'only-files' => ['src/X/**']],
            ],
        ],
    ],
];
PHP;
        $this->writeMain($main);
        $this->writeLocal($local);

        $refs = $this->parseFlowJobRefs('qa');

        // Caveat documented: composer.json from shared is NOT replaced because
        // local's array index 1 is absent.
        $this->assertSame(['src/X/**', 'composer.json'], $refs[0]->getOnlyFiles());
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
        $this->assertNotNull($flow, "Flow '$flowName' not found");
        return $flow->getJobReferences();
    }
}
