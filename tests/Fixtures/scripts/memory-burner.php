<?php

// Cross-platform memory allocator for @group ci-features tests.
// Allocates roughly N MB and holds the process alive S seconds so
// the RSS sampler has time to observe a stable peak.
//
// Usage: php memory-burner.php <megabytes> <seconds>

$megabytes = (int) ($argv[1] ?? 64);
$seconds   = (int) ($argv[2] ?? 2);
if ($megabytes < 1) {
    $megabytes = 1;
}
if ($seconds < 1) {
    $seconds = 1;
}

// PHP strings have ~40 bytes of header overhead; use a plain repeated
// string so RSS reflects the requested size closely. Buffer is held
// in a variable so it is not GC'd before the sleep returns.
$buffer = str_repeat('x', $megabytes * 1024 * 1024);

sleep($seconds);

// Touch the buffer so the optimizer cannot strip the allocation.
echo strlen($buffer) === $megabytes * 1024 * 1024 ? "OK\n" : "MISMATCH\n";
exit(0);
