#!/bin/sh
# Cross-platform Pint simulator. Unlike phpcbf (exit 1 = fixes applied), Pint's
# fix mode exits 0 whether or not it fixed anything (verified against Pint 1.30),
# so the default exit code here is 0. Ignores any extra args the executor may
# inject (--test, paths, etc.) so the test stays decoupled from Pint's flag
# evolution.
#
# With PINT_FAKE_TARGET set it also rewrites that file: a real fixer modifies
# the working tree *while it runs*, and the re-stage scope is derived from
# exactly that. PINT_FAKE_EXIT overrides the exit code (e.g. 1 to simulate
# `--test` on a dirty tree, which never rewrites anything).
if [ -n "$PINT_FAKE_TARGET" ]; then
    printf '<?php\n\nclass PintFixable\n{\n    public function a()\n    {\n    }\n}\n' > "$PINT_FAKE_TARGET"
fi

exit "${PINT_FAKE_EXIT:-0}"
