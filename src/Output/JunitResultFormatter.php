<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use DOMDocument;
use Wtyd\GitHooks\Execution\FlowResult;

class JunitResultFormatter implements ResultFormatter
{
    public function format(FlowResult $result): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $testsuites = $dom->createElement('testsuites');
        $dom->appendChild($testsuites);

        $testsuite = $dom->createElement('testsuite');
        $testsuite->setAttribute('name', $result->getFlowName());
        $testsuite->setAttribute('tests', (string) count($result->getJobResults()));
        $testsuite->setAttribute('failures', (string) $result->getFailedCount());
        $testsuite->setAttribute('time', $this->parseSeconds($result->getTotalTime()));
        $testsuites->appendChild($testsuite);

        foreach ($result->getJobResults() as $jobResult) {
            $testcase = $dom->createElement('testcase');
            $testcase->setAttribute('name', $jobResult->getJobName());
            $testcase->setAttribute('time', $this->parseSeconds($jobResult->getExecutionTime()));

            if (!$jobResult->isSuccess()) {
                $failure = $dom->createElement('failure');
                $failure->setAttribute('message', $jobResult->getJobName() . ' failed');
                $failure->appendChild($dom->createTextNode($this->stripAnsi($jobResult->getOutput())));
                $testcase->appendChild($failure);
            }

            $testsuite->appendChild($testcase);
        }

        return $dom->saveXML() ?: '';
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\x1B(?:\[[0-9;]*[A-Za-z]|\][^\x07]*\x07)|\r/', '', $text);
    }

    /**
     * Convert formatted time strings (e.g. "234ms", "1.23s", "2m 30s") to seconds.
     *
     * @SuppressWarnings(PHPMD.UndefinedVariable) preg_match assigns $matches by reference
     */
    private function parseSeconds(string $time): string
    {
        if (preg_match('/^(\d+)ms$/', $time, $matches)) {
            return number_format((int) $matches[1] / 1000, 3);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)s$/', $time, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^(\d+)m\s*(\d+)s$/', $time, $matches)) {
            return (string) ((int) $matches[1] * 60 + (int) $matches[2]);
        }
        return $time;
    }
}
