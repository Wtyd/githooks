<?php

declare(strict_types=1);

namespace Tests\Unit\Flaky;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\Alfil\Flaky\JunitReport;
use Wtyd\Alfil\Flaky\TestStatus;

/**
 * Parser side of table A (tests/Unit/Flaky/factors.md): F1 file state, F3
 * testcase status and the identity rule.
 */
class JunitReportTest extends UnitTestCase
{
    private static function wrap(string $testcases): string
    {
        return "<?xml version=\"1.0\"?>\n<testsuites><testsuite name=\"s\">$testcases</testsuite></testsuites>";
    }

    /**
     * @test
     * @dataProvider statusCases
     */
    public function it_normalises_each_testcase_outcome(string $children, string $expected): void
    {
        $report = JunitReport::fromString(self::wrap("<testcase classname=\"T\" name=\"t\">$children</testcase>"));

        $this->assertSame(['T::t' => $expected], $report->getTests());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function statusCases(): array
    {
        return [
            'no child (passed)'     => ['', TestStatus::GREEN],
            'failure'               => ['<failure>boom</failure>', TestStatus::NONGREEN],
            'error'                 => ['<error>boom</error>', TestStatus::NONGREEN],
            'skipped'               => ['<skipped/>', TestStatus::NEUTRAL],
            'warning'               => ['<warning>w</warning>', TestStatus::NEUTRAL],
            'failure beats skipped' => ['<skipped/><failure>boom</failure>', TestStatus::NONGREEN],
            'system-out only'       => ['<system-out>log</system-out>', TestStatus::GREEN],
        ];
    }

    /**
     * @test
     * @dataProvider identities
     */
    public function it_identifies_a_test_by_its_owner_and_verbatim_name(string $attributes, string $expectedId): void
    {
        $report = JunitReport::fromString(self::wrap("<testcase $attributes/>"));

        $this->assertSame([$expectedId], array_keys($report->getTests()));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function identities(): array
    {
        return [
            'classname (phpunit)'          => ['classname="A\\BTest" class="X" name="m"', 'A\\BTest::m'],
            'class when no classname (pest)' => ['class="Tests\\Feature\\X" file="f.php" name="it works"', 'Tests\\Feature\\X::it works'],
            'file when no class'           => ['file="tests/x.php" name="m"', 'tests/x.php::m'],
            'name alone'                   => ['name="m"', 'm'],
            'dataset suffix kept verbatim' => ['classname="T" name="m with data set #1"', 'T::m with data set #1'],
            'named dataset kept verbatim'  => ['classname="T" name="m with data set &quot;big&quot;"', 'T::m with data set "big"'],
        ];
    }

    /** @test */
    public function it_reads_testcases_from_nested_suites(): void
    {
        $xml = '<?xml version="1.0"?><testsuites><testsuite name="all">'
            . '<testsuite name="T"><testcase classname="T" name="a"/>'
            . '<testsuite name="T::b"><testcase classname="T" name="b with data set #0"><failure/></testcase></testsuite>'
            . '</testsuite></testsuite></testsuites>';

        $this->assertSame(
            ['T::a' => TestStatus::GREEN, 'T::b with data set #0' => TestStatus::NONGREEN],
            JunitReport::fromString($xml)->getTests()
        );
    }

    /**
     * @test
     * @dataProvider duplicates
     */
    public function a_test_reported_twice_keeps_the_worst_outcome_and_is_flagged(string $first, string $second, string $expected): void
    {
        $report = JunitReport::fromString(self::wrap(
            "<testcase classname=\"T\" name=\"t\">$first</testcase><testcase classname=\"T\" name=\"u\"/>"
            . "<testcase classname=\"T\" name=\"t\">$second</testcase>"
        ));

        $this->assertSame(['T::t' => $expected, 'T::u' => TestStatus::GREEN], $report->getTests());
        $this->assertSame(['T::t'], $report->getCollisions());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public function duplicates(): array
    {
        return [
            'green then failure'   => ['', '<failure/>', TestStatus::NONGREEN],
            'failure then green'   => ['<failure/>', '', TestStatus::NONGREEN],
            'skipped then green'   => ['<skipped/>', '', TestStatus::GREEN],
            'green then skipped'   => ['', '<skipped/>', TestStatus::GREEN],
        ];
    }

    /**
     * @test
     * @dataProvider unreadableContents
     */
    public function unreadable_content_is_corrupt_never_an_empty_green_run(string $content): void
    {
        $report = JunitReport::fromString($content);

        $this->assertSame(JunitReport::FILE_CORRUPT, $report->getFileState());
        $this->assertFalse($report->isReadable());
    }

    /**
     * @return array<string, array{string}>
     */
    public function unreadableContents(): array
    {
        return [
            'empty'            => [''],
            'whitespace only'  => ["  \n"],
            'truncated'        => ['<?xml version="1.0"?><testsuites><testsuite name="s"><testcase classname="T" name="a"/>'],
            'not xml'          => ['PHPUnit crashed: Allowed memory size exhausted'],
            'unexpected root'  => ['<?xml version="1.0"?><html><testcase name="a"/></html>'],
        ];
    }

    /** @test */
    public function a_well_formed_suite_without_testcases_is_readable_and_empty(): void
    {
        $report = JunitReport::fromString('<?xml version="1.0"?><testsuite name="s"/>');

        $this->assertSame(JunitReport::FILE_OK, $report->getFileState());
        $this->assertSame([], $report->getTests());
    }

    /** @test */
    public function a_file_that_does_not_exist_is_missing(): void
    {
        $this->assertSame(JunitReport::FILE_MISSING, JunitReport::fromFile('/no/such/junit.xml')->getFileState());
    }

    /** @test */
    public function it_reads_an_existing_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'junit');
        file_put_contents((string) $path, self::wrap('<testcase classname="T" name="a"><error/></testcase>'));

        try {
            $this->assertSame(['T::a' => TestStatus::NONGREEN], JunitReport::fromFile((string) $path)->getTests());
        } finally {
            unlink((string) $path);
        }
    }

    /** @test */
    public function the_unreadable_factories_carry_their_state(): void
    {
        $this->assertSame(JunitReport::FILE_STALE, JunitReport::stale()->getFileState());
        $this->assertSame(JunitReport::FILE_MISSING, JunitReport::missing()->getFileState());
        $this->assertSame([], JunitReport::stale()->getTests());
    }
}
