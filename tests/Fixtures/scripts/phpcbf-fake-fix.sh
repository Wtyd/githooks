#!/bin/sh
# Cross-platform phpcbf simulator for tests that need a "fix applied"
# exit code (1) without invoking the real binary. Ignores any extra args
# the executor may inject (--parallel=N, paths, etc.) so the test stays
# decoupled from phpcs/phpcbf flag evolution.
exit 1
