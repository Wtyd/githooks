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
            if ($jobResult->getType() !== '') {
                $testcase->setAttribute('classname', $jobResult->getType());
            }

            if ($jobResult->isSkipped()) {
                $skipped = $dom->createElement('skipped');
                $reason = $jobResult->getSkipReason();
                if ($reason !== null) {
                    $skipped->setAttribute('message', $reason);
                }
                $testcase->appendChild($skipped);
            } elseif (!$jobResult->isSuccess()) {
                $failure = $dom->createElement('failure');
                $failure->setAttribute('message', $jobResult->getJobName() . ' failed');
                $cleaned = $this->stripAnsi($jobResult->getOutput());
                $failure->appendChild($dom->createTextNode($this->prettyJsonIfApplicable($cleaned)));
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
     * Pretty-print JSON outputs so JUnit viewers (GitLab, Jenkins) render
     * each tool's payload in a readable shape.
     *
     * Rationale: phpmd already emits JSON_PRETTY_PRINT by default, so a JUnit
     * viewer renders it nicely. phpstan/phpcs/psalm/parallel-lint emit
     * compact JSON (one line). When phpstan also writes informational text
     * to stderr (instructions block), githooks captures stdout+stderr
     * combined, so `<failure>` arrives as `<compact-json><explanatory-text>`.
     * We detect the JSON document (first `{` or `[` to its matching closer),
     * pretty-print only that span, and preserve any non-JSON prologue and
     * epilogue verbatim.
     *
     * Edge-cases:
     * - phpmd already pretty stays pretty (semantics preserved; bytes may
     *   differ because JSON_UNESCAPED_SLASHES turns `\/` into `/`).
     * - non-JSON output (custom job, script) passes through unchanged.
     * - JSON we can't parse passes through unchanged.
     */
    private function prettyJsonIfApplicable(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $bounds = $this->findJsonBounds($text);
        if ($bounds === null) {
            return $text;
        }
        [$start, $end] = $bounds;

        $jsonPart = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($jsonPart, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $text;
        }

        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($pretty === false) {
            return $text;
        }

        return substr($text, 0, $start) . $pretty . substr($text, $end + 1);
    }

    /**
     * Locate the outermost JSON document inside `$text`. Picks the earliest
     * opener (`{` or `[`) and pairs it with the matching last closer of the
     * same kind. Returns [start, end] inclusive, or null when no plausible
     * JSON span exists.
     *
     * @return array{0:int,1:int}|null
     */
    private function findJsonBounds(string $text): ?array
    {
        $objStart = strpos($text, '{');
        $arrStart = strpos($text, '[');

        if ($objStart !== false && ($arrStart === false || $objStart < $arrStart)) {
            return $this->boundsFor($text, (int) $objStart, '}');
        }
        if ($arrStart !== false) {
            return $this->boundsFor($text, (int) $arrStart, ']');
        }
        return null;
    }

    /**
     * @return array{0:int,1:int}|null
     */
    private function boundsFor(string $text, int $start, string $closer): ?array
    {
        $end = strrpos($text, $closer);
        return ($end !== false && $end > $start) ? [$start, (int) $end] : null;
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
