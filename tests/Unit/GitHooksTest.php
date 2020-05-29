<?php

namespace Tests\Unit;

use GitHooks\GitHooks;
use GitHooks\Tools\ParallelLint;
use GitHooks\Tools\ToolAbstract;
use Tests\Mock as m;
use PHPUnit\Framework\TestCase;
use Tests\VirtualFileSystemTrait;

class GitHooksTest extends TestCase
{
    use VirtualFileSystemTrait;

    protected $githooks;

    public function setUp(): void
    {
        $this->githooks = m::mock(GitHooks::class)->makePartial();
        $this->githooks->shouldReceive('terminate')->andReturn(0);

        $structure = [
            'src' => [
                'parallel-lintOK.es' => '<?php $a=10; echo $a',
                // 'other.php' => 'Some more text content',
                // 'Invalid.csv' => 'Something else',
                // 'ficheroZip.zip' => 'Something else',
            ]
        ];
        $this->createFileSystem($structure);
    }

    /** @test*/
    function prueba()
    {
        $this->markTestSkipped("Pruebas de integracion con exit 1");
        $a = $this->getFile('src/parallel-lintOK.php');
        var_dump($a);
        $mockParallelLint = m::mock(ParallelLint::class)->makePartial();
        $mockParallelLint->shouldReceive('path')->andReturn($this->getFile('src/parallel-lintOK.php'));
        ob_start();
        $this->githooks->__invoke();
        $string = ob_get_contents();
        ob_end_clean();
        $this->assertRegexp('%parallel-lint - OK%', $string);
    }
}
