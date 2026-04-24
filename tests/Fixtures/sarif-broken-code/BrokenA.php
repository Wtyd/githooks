<?php

namespace Tests\Fixtures\SarifBrokenCode;

// Intentionally broken code used by the SARIF contract tests.
// Each violation below is crafted to trigger a locatable issue (file+line)
// from at least one parser registered in ToolOutputParserRegistry:
//   - phpstan:  no return type, no param type, undefined $y.
//   - phpmd:    unused parameter $x, unused local $unused.
//   - phpcs:    line length over 120 chars on the long comment below.
// DO NOT FIX — the tests depend on it failing.
class BrokenA
{
    // phpcs PSR12 fires "Line exceeds 120 characters" on the following line, which is intentionally longer than the allowed limit.
    public function a($x)
    {
        $unused = 42;
        return $y;
    }
}
