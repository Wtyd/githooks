<?php

// Cross-platform sleep helper for @group ci-features tests.
// Usage: php sleep.php <seconds>

$seconds = (int) ($argv[1] ?? 1);
if ($seconds < 1) {
    $seconds = 1;
}
sleep($seconds);
exit(0);
