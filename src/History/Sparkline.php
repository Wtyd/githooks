<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\History;

/**
 * Renders a series of numeric values as a one-line ASCII sparkline using the
 * eight Unicode block characters ▁▂▃▄▅▆▇█ (FEAT-5).
 */
class Sparkline
{
    /** @var string[] U+2581 … U+2588, lowest to highest. */
    private const BLOCKS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    /**
     * @param float[] $values
     */
    public static function render(array $values): string
    {
        $count = count($values);
        if ($count === 0) {
            return '';
        }

        $min = min($values);
        $max = max($values);
        $range = $max - $min;

        // A single value or a flat series has no relief: render every point at
        // the mid block rather than dividing by a zero range.
        if ($range <= 0.0) {
            return str_repeat(self::BLOCKS[3], $count);
        }

        $top = count(self::BLOCKS) - 1;
        $line = '';
        foreach ($values as $value) {
            $level = (int) round(($value - $min) / $range * $top);
            $line .= self::BLOCKS[max(0, min($top, $level))];
        }
        return $line;
    }
}
