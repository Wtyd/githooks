<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\History;

use DateTime;
use DateTimeImmutable;
use Exception;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Output\JsonResultFormatter;
use Wtyd\GitHooks\Utils\Storage;

/**
 * Persists the JSON v2 payload of a run under `.githooks/history/` and keeps a
 * FIFO window of the most recent runs per flow (FEAT-5). The activation decision
 * (--save-history OR history-size > 0, never on dry-run) is resolved by the
 * caller and handed in as `$enabled`; the store only honours it.
 */
class RunHistoryStore
{
    public const HISTORY_DIR = '.githooks/history';

    private JsonResultFormatter $formatter;

    public function __construct(?JsonResultFormatter $formatter = null)
    {
        $this->formatter = $formatter ?? new JsonResultFormatter();
    }

    /**
     * Write the run to disk and rotate the flow's window. No-op (returns null)
     * when persistence is disabled or the window size is non-positive. Returns
     * the relative path written so callers/tests can assert on it.
     */
    public function persist(FlowResult $result, string $startedAt, bool $enabled, int $historySize): ?string
    {
        if (!$enabled || $historySize <= 0) {
            return null;
        }

        $slug = self::slug($result->getFlowName());
        $path = $this->uniquePath($slug, self::timestamp($startedAt));

        Storage::put($path, $this->formatter->format($result));
        $this->rotate($slug, $historySize);

        return $path;
    }

    /**
     * Filesystem-safe slug for a flow name. Multi-flow run identifiers (`a+b`)
     * and unusual names collapse to `[A-Za-z0-9_+-]`, so each name keeps its own
     * history series without breaking on exotic filesystems.
     */
    public static function slug(string $flowName): string
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9_+-]/', '-', $flowName);
        return $slug === '' ? 'flow' : $slug;
    }

    /**
     * Whether a history filename belongs to the given flow slug. Matches both
     * `<ts>-<slug>.json` and the anti-collision `<ts>-<slug>-<n>.json` variants.
     */
    public static function matchesFlow(string $basename, string $slug): bool
    {
        $pattern = '/^\d{8}-\d{6}-' . preg_quote($slug, '/') . '(-\d+)?\.json$/';
        return preg_match($pattern, $basename) === 1;
    }

    /**
     * Derive a sortable, filesystem-safe `Ymd-His` timestamp from the run's
     * ISO-8601 startedAt. Falls back to the current wall-clock when the input
     * is not parseable.
     */
    private static function timestamp(string $startedAt): string
    {
        $parsed = DateTimeImmutable::createFromFormat(DateTime::ATOM, $startedAt);
        if ($parsed === false) {
            // startedAt carries millisecond precision (...T..:..:..,SSS+..:..);
            // ATOM has no fractional seconds, so retry tolerantly before giving up.
            try {
                $parsed = new DateTimeImmutable($startedAt);
            } catch (Exception $e) {
                return date('Ymd-His');
            }
        }
        return $parsed->format('Ymd-His');
    }

    /**
     * Resolve a non-colliding path for this run. Same-second runs of the same
     * flow get a `-1`, `-2`… suffix so no payload is overwritten.
     */
    private function uniquePath(string $slug, string $timestamp): string
    {
        $base = self::HISTORY_DIR . "/$timestamp-$slug";
        $path = "$base.json";
        $suffix = 1;
        while (Storage::exists($path)) {
            $path = "$base-$suffix.json";
            $suffix++;
        }
        return $path;
    }

    /**
     * FIFO rotation: keep the $historySize most recent files for the flow,
     * delete the rest. Ordering is lexicographic over the filename, which the
     * `Ymd-His` prefix makes chronological.
     */
    private function rotate(string $slug, int $historySize): void
    {
        $files = [];
        foreach (Storage::files(self::HISTORY_DIR) as $file) {
            if (self::matchesFlow(basename($file), $slug)) {
                $files[] = $file;
            }
        }

        if (count($files) <= $historySize) {
            return;
        }

        sort($files);
        $stale = array_slice($files, 0, count($files) - $historySize);
        Storage::delete($stale);
    }
}
