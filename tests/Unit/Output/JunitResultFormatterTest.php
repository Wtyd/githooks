<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use DOMDocument;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Output\JunitResultFormatter;

class JunitResultFormatterTest extends UnitTestCase
{
    /** @test */
    function it_produces_valid_xml()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1.23s'),
            new JobResult('phpmd_src', false, 'error output', '500ms'),
        ], '1.73s');

        $formatter = new JunitResultFormatter();
        $xml = $formatter->format($result);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'Output must be valid XML');
    }

    /** @test */
    function it_creates_testsuites_structure()
    {
        $result = new FlowResult('lint', [
            new JobResult('phpcs_all', true, '', '200ms'),
        ], '200ms');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testsuites = $dom->getElementsByTagName('testsuites');
        $this->assertSame(1, $testsuites->length);

        $testsuite = $dom->getElementsByTagName('testsuite')->item(0);
        $this->assertSame('lint', $testsuite->getAttribute('name'));
        $this->assertSame('1', $testsuite->getAttribute('tests'));
        $this->assertSame('0', $testsuite->getAttribute('failures'));
    }

    /** @test */
    function it_adds_failure_element_for_failed_jobs()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
            new JobResult('phpmd_src', false, 'VIOLATION found', '500ms'),
        ], '1.50s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $failures = $dom->getElementsByTagName('failure');
        $this->assertSame(1, $failures->length);
        $this->assertSame('phpmd_src failed', $failures->item(0)->getAttribute('message'));
        $this->assertStringContainsString('VIOLATION found', $failures->item(0)->textContent);
    }

    /** @test */
    function it_strips_ansi_escape_sequences_from_failure_output()
    {
        $ansiOutput = "\e[1G\e[2K 5/5 [\e[32m▓▓▓▓▓\e[0m] 100%\r\nSome error";

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, $ansiOutput, '1s'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $xml = $formatter->format($result);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'Output must be valid XML');

        $failureText = $dom->getElementsByTagName('failure')->item(0)->textContent;
        $this->assertStringNotContainsString("\e[", $failureText);
        $this->assertStringNotContainsString("\r", $failureText);
        $this->assertStringContainsString('Some error', $failureText);
    }

    /** @test */
    function it_converts_time_formats_to_seconds()
    {
        $result = new FlowResult('qa', [
            new JobResult('fast_job', true, '', '234ms'),
        ], '234ms');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testcase = $dom->getElementsByTagName('testcase')->item(0);
        $this->assertSame('0.234', $testcase->getAttribute('time'));
    }

    /**
     * @test
     * @dataProvider timeFormatProvider
     */
    function it_parses_time_formats_with_anchored_regex(string $time, string $expected)
    {
        $result = new FlowResult('qa', [
            new JobResult('job', true, '', $time),
        ], $time);

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testcase = $dom->getElementsByTagName('testcase')->item(0);
        $this->assertSame($expected, $testcase->getAttribute('time'));
    }

    public function timeFormatProvider(): array
    {
        return [
            'milliseconds' => ['500ms', '0.500'],
            'seconds integer' => ['2s', '2'],
            'seconds decimal' => ['1.5s', '1.5'],
            'minutes and seconds' => ['2m 30s', '150'],
            'minutes and seconds no space' => ['1m10s', '70'],
            'unrecognised input falls through' => ['1.5s trailing', '1.5s trailing'],
            'seconds with prefix does not match' => ['t 1.5s', 't 1.5s'],
        ];
    }

    /** @test */
    function it_adds_skipped_element_for_skipped_jobs()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
            JobResult::skipped('phpcs_src', 'phpcs', 'no staged files match its paths', ['src']),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $skippedElements = $dom->getElementsByTagName('skipped');
        $this->assertSame(1, $skippedElements->length);
        $this->assertSame('no staged files match its paths', $skippedElements->item(0)->getAttribute('message'));

        // Skipped job should not have failure element
        $testcases = $dom->getElementsByTagName('testcase');
        $skippedTestcase = $testcases->item(1);
        $this->assertSame(0, $skippedTestcase->getElementsByTagName('failure')->length);
    }

    /** @test */
    function it_adds_classname_attribute_with_job_type()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s', false, null, 'phpstan'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testcase = $dom->getElementsByTagName('testcase')->item(0);
        $this->assertSame('phpstan', $testcase->getAttribute('classname'));
    }

    /** @test */
    function it_omits_classname_when_type_is_empty()
    {
        $result = new FlowResult('qa', [
            new JobResult('test', true, '', '1s'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testcase = $dom->getElementsByTagName('testcase')->item(0);
        $this->assertSame('', $testcase->getAttribute('classname'));
    }
}
