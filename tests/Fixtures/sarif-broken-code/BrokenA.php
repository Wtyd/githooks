<?php

namespace Tests\Fixtures\SarifBrokenCode;

// Intentionally broken code used by the SARIF contract tests.
// Each violation below is crafted to trigger a locatable issue (file+line)
// from at least one parser registered in ToolOutputParserRegistry.
// DO NOT FIX — the tests depend on it failing.
class BrokenA
{
    public function a($x)
    {
        $unused = 42;
        return $y;
    }
}
