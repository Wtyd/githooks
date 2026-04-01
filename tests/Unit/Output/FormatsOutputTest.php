<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Output\JsonResultFormatter;
use Wtyd\GitHooks\Output\JunitResultFormatter;

class FormatsOutputTest extends TestCase
{
    /** @test */
    public function it_formats_json_result_as_valid_json()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpcs', true, "OK\n", '150ms'),
            new JobResult('phpstan', false, "Error found\n", '1.23s'),
        ], '1.38s', 2, 4);

        $json = (new JsonResultFormatter())->format($result);

        $decoded = json_decode($json, true);

        $this->assertNotNull($decoded, 'Output is not valid JSON');
        $this->assertEquals('qa', $decoded['flow']);
        $this->assertFalse($decoded['success']);
        $this->assertEquals(1, $decoded['passed']);
        $this->assertEquals(1, $decoded['failed']);
        $this->assertCount(2, $decoded['jobs']);
        $this->assertEquals('phpcs', $decoded['jobs'][0]['name']);
        $this->assertTrue($decoded['jobs'][0]['success']);
        $this->assertEquals('phpstan', $decoded['jobs'][1]['name']);
        $this->assertFalse($decoded['jobs'][1]['success']);
    }

    /** @test */
    public function it_formats_junit_result_as_valid_xml()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpcs', true, '', '100ms'),
            new JobResult('phpstan', false, "Line 42: error\n", '2.50s'),
        ], '2.60s');

        $xml = (new JunitResultFormatter())->format($result);

        $this->assertStringContainsString('<?xml', $xml);

        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        $this->assertTrue($loaded, 'Output is not valid XML');

        $testsuites = $dom->getElementsByTagName('testsuite');
        $this->assertEquals(1, $testsuites->length);
        $this->assertEquals('qa', $testsuites->item(0)->getAttribute('name'));
        $this->assertEquals('2', $testsuites->item(0)->getAttribute('tests'));
        $this->assertEquals('1', $testsuites->item(0)->getAttribute('failures'));

        $testcases = $dom->getElementsByTagName('testcase');
        $this->assertEquals(2, $testcases->length);

        $failures = $dom->getElementsByTagName('failure');
        $this->assertEquals(1, $failures->length);
        $this->assertStringContainsString('Line 42: error', $failures->item(0)->textContent);
    }
}
