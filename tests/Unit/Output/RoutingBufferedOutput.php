<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Test-only OutputInterface that extends BufferedOutput and routes every
 * `writeln(string)` call to one of three public arrays based on the message
 * content:
 *
 *  - "Report written to: …"     → $infos[]
 *  - "<comment>…</comment>"     → $warnings[]  (tags stripped)
 *  - anything else              → $lines[]
 *
 * Lets the Phase 2a/2b test doubles satisfy the renderer/emitter's
 * `OutputInterface` parameter type while preserving the array-based capture
 * pattern used by the existing FormatsOutput / EmitsConditionsHeader tests.
 */
class RoutingBufferedOutput extends BufferedOutput
{
    /** @var string[] */
    public array $lines = [];

    /** @var string[] */
    public array $warnings = [];

    /** @var string[] */
    public array $infos = [];

    /**
     * Bind this buffer's `lines`/`warnings`/`infos` arrays as references to
     * caller-owned arrays. Callers (test doubles) use this so they can keep
     * public array properties on themselves and have writes here mirror into
     * those properties live (avoiding magic __get copies that break
     * `end($double->lines)` / `count()` patterns).
     *
     * @param string[] $lines
     * @param string[] $warnings
     * @param string[] $infos
     */
    public function bindArrays(array &$lines, array &$warnings, array &$infos): void
    {
        $this->lines = &$lines;
        $this->warnings = &$warnings;
        $this->infos = &$infos;
    }

    /**
     * @param string|iterable<string> $messages
     */
    public function writeln($messages, int $options = self::OUTPUT_NORMAL): void
    {
        $list = is_iterable($messages) ? $messages : [$messages];
        foreach ($list as $msg) {
            $this->routeMessage((string) $msg);
        }
    }

    private function routeMessage(string $message): void
    {
        if (strpos($message, 'Report written to: ') === 0) {
            $this->infos[] = $message;
            return;
        }
        if (preg_match('#^<comment>(.*)</comment>$#s', $message, $m) === 1) {
            $this->warnings[] = $m[1];
            return;
        }
        $this->lines[] = $message;
    }
}
