<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Output\JsonResultFormatter;

class JsonResultFormatterTest extends UnitTestCase
{
    /** @test */
    function it_formats_a_successful_flow_as_json()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1.23s'),
            new JobResult('phpcs_all', true, '', '500ms'),
        ], '1.73s');

        $formatter = new JsonResultFormatter();
        $json = $formatter->format($result);
        $data = json_decode($json, true);

        $this->assertSame('qa', $data['flow']);
        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['passed']);
        $this->assertSame(0, $data['failed']);
        $this->assertCount(2, $data['jobs']);
        $this->assertSame('phpstan_src', $data['jobs'][0]['name']);
        $this->assertTrue($data['jobs'][0]['success']);
    }

    /** @test */
    function it_formats_a_failed_flow_with_output()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1.23s'),
            new JobResult('phpmd_src', false, 'VIOLATION in Foo.php', '500ms'),
        ], '1.73s');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertFalse($data['success']);
        $this->assertSame(1, $data['passed']);
        $this->assertSame(1, $data['failed']);
        $this->assertFalse($data['jobs'][1]['success']);
        $this->assertSame('VIOLATION in Foo.php', $data['jobs'][1]['output']);
    }

    /** @test */
    function it_produces_valid_json()
    {
        $result = new FlowResult('qa', [
            new JobResult('test', true, "line with \"quotes\" and\nnewlines", '100ms'),
        ], '100ms');

        $formatter = new JsonResultFormatter();
        $json = $formatter->format($result);

        $this->assertNotNull(json_decode($json), 'Output must be valid JSON');
    }
}
