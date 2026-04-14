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

        $this->assertSame(2, $data['version']);
        $this->assertSame('qa', $data['flow']);
        $this->assertTrue($data['success']);
        $this->assertSame('full', $data['executionMode']);
        $this->assertSame(2, $data['passed']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(0, $data['skipped']);
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
    function it_strips_ansi_escape_sequences_from_output()
    {
        $ansiOutput = "\e[1G\e[2K 5/5 [\e[32m▓▓▓▓▓\e[0m] 100%\r\nSome error";

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, $ansiOutput, '1s'),
        ], '1s');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $output = $data['jobs'][0]['output'];
        $this->assertStringNotContainsString("\e[", $output);
        $this->assertStringNotContainsString("\r", $output);
        $this->assertStringContainsString('Some error', $output);
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

    /** @test */
    function it_includes_v2_fields_per_job()
    {
        $result = new FlowResult('qa', [
            new JobResult(
                'phpstan_src',
                true,
                '',
                '1.23s',
                false,
                'vendor/bin/phpstan analyse src',
                'phpstan',
                0,
                ['src']
            ),
        ], '1.23s', 0, 0, 'fast');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame(2, $data['version']);
        $this->assertSame('fast', $data['executionMode']);

        $job = $data['jobs'][0];
        $this->assertSame('phpstan', $job['type']);
        $this->assertSame(0, $job['exitCode']);
        $this->assertSame(['src'], $job['paths']);
        $this->assertSame('vendor/bin/phpstan analyse src', $job['command']);
        $this->assertFalse($job['skipped']);
        $this->assertNull($job['skipReason']);
    }

    /** @test */
    function it_includes_skipped_jobs_in_json()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
            JobResult::skipped('phpcs_src', 'phpcs', 'no staged files match its paths', ['src']),
        ], '1s');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame(1, $data['passed']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(1, $data['skipped']);

        $skippedJob = $data['jobs'][1];
        $this->assertSame('phpcs_src', $skippedJob['name']);
        $this->assertSame('phpcs', $skippedJob['type']);
        $this->assertTrue($skippedJob['skipped']);
        $this->assertSame('no staged files match its paths', $skippedJob['skipReason']);
        $this->assertSame(['src'], $skippedJob['paths']);
    }

    /** @test */
    function it_always_includes_command_field()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
        ], '1s');

        $formatter = new JsonResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertArrayHasKey('command', $data['jobs'][0]);
    }
}
