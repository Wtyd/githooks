<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Asserts for output captured via {@see CapturesStdout}. Built on top of
 * PHPUnit's native asserts but worded around the domain (markers, section
 * bodies) so the test reads as a contract instead of as substring
 * arithmetic.
 */
trait AssertsOutputBody
{
    /**
     * Every marker in $markers appears in $output, in the order given. Any
     * extra content between markers is allowed.
     *
     * @param string[] $markers
     */
    protected function assertMarkersInOrder(array $markers, string $output, string $message = ''): void
    {
        $lastIdx = -1;
        $lastMarker = '<start>';
        foreach ($markers as $marker) {
            $idx = strpos($output, $marker, $lastIdx + 1);
            $this->assertNotFalse(
                $idx,
                $message !== '' ? $message : "marker '$marker' must appear after '$lastMarker' in output"
            );
            $lastIdx = $idx;
            $lastMarker = $marker;
        }
    }

    /**
     * The portion of $output between the first occurrence of $jobName + "\n"
     * (the section header that GitLabCIDecorator emits) and the next
     * "section_end:" marker is exactly $expectedBody. Use to pin what the
     * decorator placed between header and footer when wrapping inner output.
     */
    protected function assertSectionBodyEquals(string $expectedBody, string $jobName, string $output, string $message = ''): void
    {
        $body = $this->extractSectionBody($jobName, $output);
        $this->assertSame(
            $expectedBody,
            $body,
            $message !== '' ? $message : "body between '$jobName' header and section_end must equal expected"
        );
    }

    /**
     * Extracts the body that GitLabCIDecorator placed between the section
     * header ("$jobName\n") and the closing "section_end:" marker.
     */
    protected function extractSectionBody(string $jobName, string $output): string
    {
        $headerEnd = strpos($output, "$jobName\n");
        if ($headerEnd === false) {
            return '';
        }
        $bodyStart = $headerEnd + strlen("$jobName\n");
        $sectionEnd = strpos($output, 'section_end:', $bodyStart);
        if ($sectionEnd === false) {
            return substr($output, $bodyStart);
        }
        return substr($output, $bodyStart, $sectionEnd - $bodyStart);
    }
}
