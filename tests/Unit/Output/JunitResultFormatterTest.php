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
}
