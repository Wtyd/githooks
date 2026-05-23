<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use DOMDocument;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Output\JunitResultFormatter;

class JunitResultFormatterTest extends UnitTestCase
{
    /** @test */
    function it_produces_valid_xml()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1.23s'),
            new JobResult('phpmd_src', false, 'error output', '500ms'),
        ], '1.73s');

        $formatter = new JunitResultFormatter();
        $xml = $formatter->format($result);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'Output must be valid XML');
    }

    /** @test */
    function it_creates_testsuites_structure()
    {
        $result = new FlowResult('lint', [
            new JobResult('phpcs_all', true, '', '200ms'),
        ], '200ms');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testsuites = $dom->getElementsByTagName('testsuites');
        $this->assertSame(1, $testsuites->length);

        $testsuite = $dom->getElementsByTagName('testsuite')->item(0);
        $this->assertSame('lint', $testsuite->getAttribute('name'));
        $this->assertSame('1', $testsuite->getAttribute('tests'));
        $this->assertSame('0', $testsuite->getAttribute('failures'));
    }

    /** @test */
    function it_adds_failure_element_for_failed_jobs()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
            new JobResult('phpmd_src', false, 'VIOLATION found', '500ms'),
        ], '1.50s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $failures = $dom->getElementsByTagName('failure');
        $this->assertSame(1, $failures->length);
        $this->assertSame('phpmd_src failed', $failures->item(0)->getAttribute('message'));
        $this->assertStringContainsString('VIOLATION found', $failures->item(0)->textContent);
    }

    /** @test */
    function it_strips_ansi_escape_sequences_from_failure_output()
    {
        $ansiOutput = "\e[1G\e[2K 5/5 [\e[32m▓▓▓▓▓\e[0m] 100%\r\nSome error";

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, $ansiOutput, '1s'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $xml = $formatter->format($result);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'Output must be valid XML');

        $failureText = $dom->getElementsByTagName('failure')->item(0)->textContent;
        $this->assertStringNotContainsString("\e[", $failureText);
        $this->assertStringNotContainsString("\r", $failureText);
        $this->assertStringContainsString('Some error', $failureText);
    }

    /** @test */
    function it_converts_time_formats_to_seconds()
    {
        $result = new FlowResult('qa', [
            new JobResult('fast_job', true, '', '234ms'),
        ], '234ms');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testcase = $dom->getElementsByTagName('testcase')->item(0);
        $this->assertSame('0.234', $testcase->getAttribute('time'));
    }

    /**
     * @test
     * @dataProvider timeFormatProvider
     */
    function it_parses_time_formats_with_anchored_regex(string $time, string $expected)
    {
        $result = new FlowResult('qa', [
            new JobResult('job', true, '', $time),
        ], $time);

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testcase = $dom->getElementsByTagName('testcase')->item(0);
        $this->assertSame($expected, $testcase->getAttribute('time'));
    }

    public function timeFormatProvider(): array
    {
        return [
            'milliseconds' => ['500ms', '0.500'],
            'seconds integer' => ['2s', '2'],
            'seconds decimal' => ['1.5s', '1.5'],
            'minutes and seconds' => ['2m 30s', '150'],
            'minutes and seconds no space' => ['1m10s', '70'],
            'unrecognised input falls through' => ['1.5s trailing', '1.5s trailing'],
            'seconds with prefix does not match' => ['t 1.5s', 't 1.5s'],
        ];
    }

    /** @test */
    function it_adds_skipped_element_for_skipped_jobs()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s'),
            JobResult::skipped('phpcs_src', 'phpcs', 'no staged files match its paths', ['src']),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $skippedElements = $dom->getElementsByTagName('skipped');
        $this->assertSame(1, $skippedElements->length);
        $this->assertSame('no staged files match its paths', $skippedElements->item(0)->getAttribute('message'));

        // Skipped job should not have failure element
        $testcases = $dom->getElementsByTagName('testcase');
        $skippedTestcase = $testcases->item(1);
        $this->assertSame(0, $skippedTestcase->getElementsByTagName('failure')->length);
    }

    /** @test */
    function it_adds_classname_attribute_with_job_type()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s', false, null, 'phpstan'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testcase = $dom->getElementsByTagName('testcase')->item(0);
        $this->assertSame('phpstan', $testcase->getAttribute('classname'));
    }

    /** @test */
    function it_omits_classname_when_type_is_empty()
    {
        $result = new FlowResult('qa', [
            new JobResult('test', true, '', '1s'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testcase = $dom->getElementsByTagName('testcase')->item(0);
        $this->assertSame('', $testcase->getAttribute('classname'));
    }

    // ========================================================================
    // Mutation testing reinforcements (cluster E)
    // ========================================================================

    /** @test */
    function testsuite_element_carries_a_time_attribute_in_seconds()
    {
        // Kills MethodCallRemoval on `$testsuite->setAttribute('time', ...)`
        // at line 24: without the call, the testsuite element would lack
        // the time attribute entirely.
        $result = new FlowResult('qa', [
            new JobResult('a', true, '', '1.50s'),
        ], '2.00s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $testsuite = $dom->getElementsByTagName('testsuite')->item(0);
        $this->assertTrue($testsuite->hasAttribute('time'));
        $this->assertSame('2.00', $testsuite->getAttribute('time'));
    }

    /** @test */
    function parse_seconds_anchors_at_both_ends_for_minute_format()
    {
        // Kills PregMatchRemoveCaret and PregMatchRemoveDollar on the
        // minute-format regex `/^(\d+)m\s*(\d+)s$/` at line 73. With the
        // caret removed, "garbage 2m 30s" would match and return 150;
        // with the dollar removed, "2m 30s extra" would match and
        // return 150. Both cases must fall through to the `return $time`
        // fallback (line 76) and surface the unparsed string.
        $reflection = new \ReflectionMethod(JunitResultFormatter::class, 'parseSeconds');
        $reflection->setAccessible(true);
        $formatter = new JunitResultFormatter();

        // Anchored input (the happy path) parses to 150 seconds.
        $this->assertSame('150', $reflection->invoke($formatter, '2m 30s'));

        // Inputs with extra prefix or suffix must NOT match.
        $this->assertSame('garbage 2m 30s', $reflection->invoke($formatter, 'garbage 2m 30s'));
        $this->assertSame('2m 30s extra', $reflection->invoke($formatter, '2m 30s extra'));
    }

    /** @test */
    function parse_seconds_handles_each_format_branch()
    {
        // Pin the three format branches (ms / s / m+s) so a parser
        // regression in any one of them is caught.
        $reflection = new \ReflectionMethod(JunitResultFormatter::class, 'parseSeconds');
        $reflection->setAccessible(true);
        $formatter = new JunitResultFormatter();

        $this->assertSame('0.250', $reflection->invoke($formatter, '250ms'));
        $this->assertSame('1.50', $reflection->invoke($formatter, '1.50s'));
        $this->assertSame('150', $reflection->invoke($formatter, '2m 30s'));
        $this->assertSame('60', $reflection->invoke($formatter, '1m 0s'));
    }

    // ========================================================================
    // Pretty-print JSON in <failure> (3.3.2):
    // GitLab/Jenkins JUnit viewers render <failure> verbatim. Tools that emit
    // compact JSON (phpstan, phpcs, psalm, parallel-lint) arrive unreadable;
    // tools that already pretty-print (phpmd) must keep their semantics.
    // ========================================================================

    /** @test */
    function compact_json_failure_output_is_pretty_printed()
    {
        $compactPhpstan = '{"totals":{"errors":0,"file_errors":4},"files":{"src/Foo.php":{"errors":4,"messages":[{"message":"Method has no return type.","line":8,"identifier":"missingType.return"}]}},"errors":[]}';

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, $compactPhpstan, '1s'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $failureText = $dom->getElementsByTagName('failure')->item(0)->textContent;

        $this->assertStringContainsString("\n", $failureText);
        $this->assertStringContainsString('    ', $failureText);
        $decoded = json_decode($failureText, true);
        $this->assertIsArray($decoded);
        $this->assertSame(4, $decoded['totals']['file_errors']);
    }

    /** @test */
    function pretty_printed_json_failure_output_stays_pretty_semantically()
    {
        $payload = [
            'version' => '2.15.0',
            'package' => 'phpmd',
            'files'   => [['file' => '/abs/path/Foo.php', 'violations' => [['beginLine' => 8, 'description' => 'Avoid unused parameters.']]]],
        ];
        $prettyPhpmdEscaped = json_encode($payload, JSON_PRETTY_PRINT);

        $result = new FlowResult('qa', [
            new JobResult('phpmd_src', false, $prettyPhpmdEscaped, '500ms'),
        ], '500ms');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $failureText = $dom->getElementsByTagName('failure')->item(0)->textContent;

        $this->assertStringContainsString("\n", $failureText);
        $this->assertStringContainsString('    ', $failureText);
        $this->assertSame($payload, json_decode($failureText, true));
    }

    /** @test */
    function non_json_failure_output_passes_through_unchanged()
    {
        $rawOutput = "Test failed: expected 5, got 4\nStack trace:\n  at line 23";

        $result = new FlowResult('qa', [
            new JobResult('custom_test', false, $rawOutput, '100ms'),
        ], '100ms');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $failureText = $dom->getElementsByTagName('failure')->item(0)->textContent;

        $this->assertSame($rawOutput, $failureText);
    }

    /** @test */
    function json_with_prologue_or_epilogue_pretty_prints_only_the_json_span()
    {
        $compactJson = '{"totals":{"errors":0,"file_errors":1},"files":{"src/Bad.php":{"errors":1,"messages":[{"message":"Undefined variable","line":9}]}}}';
        $epilogue    = "\nInstructions for interpreting errors\n---------\nSee https://phpstan.org/...";
        $combined    = $compactJson . $epilogue;

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, $combined, '1s'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $failureText = $dom->getElementsByTagName('failure')->item(0)->textContent;

        $this->assertGreaterThan(3, substr_count($failureText, "\n"));
        $this->assertStringContainsString('Instructions for interpreting errors', $failureText);
        $this->assertStringContainsString('https://phpstan.org/', $failureText);
        $jsonEnd = strrpos($failureText, '}');
        $jsonOnly = substr($failureText, 0, $jsonEnd + 1);
        $decoded = json_decode($jsonOnly, true);
        $this->assertSame(1, $decoded['totals']['file_errors']);
    }

    /** @test */
    function empty_failure_output_passes_through_unchanged()
    {
        $result = new FlowResult('qa', [
            new JobResult('silent_failure', false, '', '50ms'),
        ], '50ms');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $failure = $dom->getElementsByTagName('failure')->item(0);
        $this->assertSame('', $failure->textContent);
    }

    /** @test */
    function json_array_payload_is_also_pretty_printed()
    {
        $compactPsalm = '[{"file_name":"src/Foo.php","line_from":8,"message":"Type X is undefined","severity":"error"}]';

        $result = new FlowResult('qa', [
            new JobResult('psalm_src', false, $compactPsalm, '1s'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $failureText = $dom->getElementsByTagName('failure')->item(0)->textContent;

        $this->assertStringContainsString("\n", $failureText);
        $decoded = json_decode($failureText, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Type X is undefined', $decoded[0]['message']);
    }

    /** @test */
    function pretty_print_runs_after_ansi_strip()
    {
        $jsonWithAnsi = "\e[31m" . '{"errors":1}' . "\e[0m";

        $result = new FlowResult('qa', [
            new JobResult('phpcs_src', false, $jsonWithAnsi, '1s'),
        ], '1s');

        $formatter = new JunitResultFormatter();
        $dom = new DOMDocument();
        $dom->loadXML($formatter->format($result));

        $failureText = $dom->getElementsByTagName('failure')->item(0)->textContent;

        $this->assertStringNotContainsString("\e[", $failureText);
        $this->assertStringContainsString("\n", $failureText);
        $decoded = json_decode($failureText, true);
        $this->assertSame(1, $decoded['errors']);
    }

    // ========================================================================
    // Infection Tier 2 — JunitResultFormatter
    // Boundary coverage for parseSeconds arithmetic, findJsonBounds /
    // boundsFor decision table, prettyJsonIfApplicable substring layout,
    // json_decode guard and json_encode flag bitwise OR.
    // ========================================================================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function parseSecondsExactValuesProvider(): array
    {
        return [
            // ms: kills L3522 IncrementInteger `/1000` → `/1001`
            'ms exact division 250'   => ['250ms',  '0.250'],
            'ms boundary 1'           => ['1ms',    '0.001'],
            'ms boundary 1000'        => ['1000ms', '1.000'],
            // s passthrough
            's integer'               => ['7s',     '7'],
            's decimal preserves dot' => ['1.5s',   '1.5'],
            // m+s: kills L3535/L3561 CastInt and L3548 DecrementInteger
            // (matches[1]→[0] would multiply the full "Nm Ms" string by 60)
            'm+s smallest'            => ['1m 0s',  '60'],
            'm+s typical'             => ['2m 30s', '150'],
            'm+s zero'                => ['0m 0s',  '0'],
            'm+s large'               => ['59m 59s', '3599'],
        ];
    }

    /**
     * @test
     * @dataProvider parseSecondsExactValuesProvider
     */
    public function parse_seconds_returns_exact_numeric_value_per_format(string $time, string $expected): void
    {
        $reflection = new \ReflectionMethod(JunitResultFormatter::class, 'parseSeconds');
        $reflection->setAccessible(true);
        $formatter = new JunitResultFormatter();

        $this->assertSame($expected, $reflection->invoke($formatter, $time));
    }

    /**
     * Decision table for `findJsonBounds` — covers L3457 LogicalAnd,
     * L3470 LessThan, L3483 GreaterThan and L3509 LogicalAnd in boundsFor.
     * L3496 CastInt is killed by the assertSame on the array of ints.
     *
     * @return array<string, array{0: string, 1: ?array{0:int,1:int}}>
     */
    public function findJsonBoundsProvider(): array
    {
        return [
            'object only'                        => ['{"a":1}',       [0, 6]],
            'array only'                         => ['[1,2]',         [0, 4]],
            'object before array — object wins'  => ['{"a":[1,2]}',   [0, 10]],
            'array before object — array wins'   => ['[{"k":1}]',     [0, 8]],
            'object at index > 0'                => ['prefix {"a":1}', [7, 13]],
            'no JSON delimiters'                 => ['plain text',    null],
            'opener but no closer'               => ['{"unclosed',    null],
            'closer before opener'               => ['} prefix {"a":1}', [9, 15]],
        ];
    }

    /**
     * @test
     * @dataProvider findJsonBoundsProvider
     * @param ?array{0:int,1:int} $expected
     */
    public function find_json_bounds_locates_outermost_document(string $text, ?array $expected): void
    {
        $reflection = new \ReflectionMethod(JunitResultFormatter::class, 'findJsonBounds');
        $reflection->setAccessible(true);
        $bounds = $reflection->invoke(new JunitResultFormatter(), $text);

        $this->assertSame($expected, $bounds);
        if ($bounds !== null) {
            // L3496 CastInt: ensure both bounds are strict ints, not coerced strings.
            $this->assertIsInt($bounds[0]);
            $this->assertIsInt($bounds[1]);
        }
    }

    /**
     * Kills L3392 (Concat operand swap), L3405 (ConcatOperandRemoval) and
     * L3431/L3444 (Inc/Dec on the substr boundaries) by asserting that the
     * prologue and epilogue surrounding the JSON survive byte-for-byte.
     *
     * @test
     */
    public function pretty_print_preserves_prologue_and_epilogue_byte_for_byte(): void
    {
        $prologue = "phpstan output\n";
        $compactJson = '{"a":1}';
        $epilogue = "\nSee https://phpstan.org for details.";
        $combined = $prologue . $compactJson . $epilogue;

        $reflection = new \ReflectionMethod(JunitResultFormatter::class, 'prettyJsonIfApplicable');
        $reflection->setAccessible(true);
        $out = $reflection->invoke(new JunitResultFormatter(), $combined);

        $this->assertStringStartsWith($prologue, $out);
        $this->assertStringEndsWith($epilogue, $out);

        $body = substr($out, strlen($prologue), strlen($out) - strlen($prologue) - strlen($epilogue));
        // The body must decode back to the original payload.
        $this->assertSame(['a' => 1], json_decode(trim($body), true));
        // And it must be pretty-printed (more than one line).
        $this->assertGreaterThan(0, substr_count(trim($body), "\n"));
    }

    /**
     * Kills L3330 (Identical `===` → `!==`), L3342 (NotIdentical `!==` → `===`)
     * and L3354 (LogicalAnd `&&` → `||`) on the json_decode error guard.
     * Strategy: invalid JSON must fall through to return the original text,
     * while valid JSON whose decode is non-null must pretty-print.
     *
     * @test
     */
    public function pretty_print_returns_input_verbatim_when_json_is_invalid(): void
    {
        $reflection = new \ReflectionMethod(JunitResultFormatter::class, 'prettyJsonIfApplicable');
        $reflection->setAccessible(true);
        $formatter = new JunitResultFormatter();

        // Invalid JSON inside an object span. Original guard:
        //   $decoded === null && json_last_error() !== JSON_ERROR_NONE
        // is TRUE → return $text unchanged.
        // - L3330 (=== → !==): guard becomes false → continues to json_encode(null)
        //   → output is "null" instead of the input. Killed.
        // - L3342 (!== → ===): guard becomes false → same as above. Killed.
        // - L3354 (&& → ||): guard short-circuits on the first true. With a
        //   valid JSON (next test), original guard is false, mutant true →
        //   would early-return the COMPACT input. Killed by the valid-JSON test.
        $invalid = '{"broken": ';
        $this->assertSame($invalid, $reflection->invoke($formatter, $invalid));
    }

    /**
     * Complementary test for L3354 (`&& → ||`): valid JSON must pretty-print
     * (the guard is false; the mutant `||` would short-circuit and early-return).
     *
     * @test
     */
    public function pretty_print_continues_when_json_decodes_successfully(): void
    {
        $reflection = new \ReflectionMethod(JunitResultFormatter::class, 'prettyJsonIfApplicable');
        $reflection->setAccessible(true);
        $formatter = new JunitResultFormatter();

        $compact = '{"value":1}';
        $pretty = $reflection->invoke($formatter, $compact);

        $this->assertStringContainsString("\n", $pretty);
        $this->assertSame(['value' => 1], json_decode(trim($pretty), true));
    }

    /**
     * Kills L3379 (BitwiseOr `|` → `&` on `JSON_PRETTY_PRINT |
     * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`). With `&` the combined
     * flag is 0 → json_encode produces a single-line, escaped output.
     *
     * @test
     */
    public function pretty_print_emits_unescaped_slashes_and_unicode(): void
    {
        $compact = '{"path":"/usr/bin","name":"éclair"}';
        $reflection = new \ReflectionMethod(JunitResultFormatter::class, 'prettyJsonIfApplicable');
        $reflection->setAccessible(true);
        $out = $reflection->invoke(new JunitResultFormatter(), $compact);

        // PRETTY: multi-line.
        $this->assertStringContainsString("\n", $out);
        // UNESCAPED_SLASHES: literal "/usr/bin", not "\/usr\/bin".
        $this->assertStringContainsString('/usr/bin', $out);
        $this->assertStringNotContainsString('\\/usr', $out);
        // UNESCAPED_UNICODE: literal "é", not "é".
        $this->assertStringContainsString('éclair', $out);
        $this->assertStringNotContainsString('\\u00e9', $out);
    }
}
