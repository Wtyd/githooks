#!/bin/sh
# Cross-platform phpcbf simulator for tests that need a "fix applied"
# exit code (1) without invoking the real binary. Ignores any extra args
# the executor may inject (--parallel=N, paths, etc.) so the test stays
# decoupled from phpcs/phpcbf flag evolution.
#
# With PHPCBF_FAKE_TARGET set it also rewrites that file: a real fixer
# modifies the working tree *while it runs*, and the re-stage scope is
# derived from exactly that. Without the variable the behaviour is
# unchanged — exit 1, touch nothing.
if [ -n "$PHPCBF_FAKE_TARGET" ]; then
    printf '<?php\n\nclass Fixable\n{\n    public function a()\n    {\n    }\n}\n' > "$PHPCBF_FAKE_TARGET"
fi

exit 1
