<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Output\SarifResultFormatter;

class SarifResultFormatterTest extends TestCase
{
    /** @test */
    function it_produces_valid_sarif_structure()
    {
        $phpstanStdout = json_encode([
            'files' => [
                'src/User.php' => [
                    'messages' => [['message' => 'Method not found', 'line' => 14]],
                ],
            ],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, '', '1s', false, null, 'phpstan', 1, ['src'], false, null, $phpstanStdout),
        ], '1s');

        $formatter = new SarifResultFormatter();
        $json = $formatter->format($result);
        $data = json_decode($json, true);

        $this->assertSame('2.1.0', $data['version']);
        $this->assertStringContainsString('sarif-schema', $data['$schema']);
        $this->assertCount(1, $data['runs']);

        $run = $data['runs'][0];
        $this->assertSame('phpstan', $run['tool']['driver']['name']);
        $this->assertSame('https://phpstan.org', $run['tool']['driver']['informationUri']);
        $this->assertCount(1, $run['results']);

        $resultEntry = $run['results'][0];
        $this->assertSame('phpstan', $resultEntry['ruleId']);
        $this->assertSame('error', $resultEntry['level']);
        $this->assertSame('Method not found', $resultEntry['message']['text']);
        $this->assertSame('src/User.php', $resultEntry['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        $this->assertSame(14, $resultEntry['locations'][0]['physicalLocation']['region']['startLine']);
    }

    /** @test */
    function it_groups_issues_by_tool_into_separate_runs()
    {
        $phpstanStdout = json_encode([
            'files' => ['src/A.php' => ['messages' => [['message' => 'e1', 'line' => 1]]]],
        ]);
        $phpcsStdout = json_encode([
            'files' => ['src/B.php' => ['messages' => [
                ['message' => 'e2', 'line' => 2, 'source' => 'PSR12.Rule', 'type' => 'ERROR'],
            ]
            ]
            ],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan', false, '', '1s', false, null, 'phpstan', 1, [], false, null, $phpstanStdout),
            new JobResult('phpcs', false, '', '1s', false, null, 'phpcs', 1, [], false, null, $phpcsStdout),
        ], '2s');

        $formatter = new SarifResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertCount(2, $data['runs']);
        $this->assertSame('phpstan', $data['runs'][0]['tool']['driver']['name']);
        $this->assertSame('phpcs', $data['runs'][1]['tool']['driver']['name']);
    }

    /** @test */
    function it_deduplicates_rules_within_a_run()
    {
        $phpstanStdout = json_encode([
            'files' => [
                'src/A.php' => ['messages' => [['message' => 'err1', 'line' => 1]]],
                'src/B.php' => ['messages' => [['message' => 'err2', 'line' => 2]]],
            ],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan', false, '', '1s', false, null, 'phpstan', 1, [], false, null, $phpstanStdout),
        ], '1s');

        $formatter = new SarifResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $rules = $data['runs'][0]['tool']['driver']['rules'];
        $this->assertCount(1, $rules);
        $this->assertSame('phpstan', $rules[0]['id']);

        $this->assertCount(2, $data['runs'][0]['results']);
        $this->assertSame(0, $data['runs'][0]['results'][0]['ruleIndex']);
        $this->assertSame(0, $data['runs'][0]['results'][1]['ruleIndex']);
    }

    /** @test */
    function it_maps_levels_correctly()
    {
        $phpcsStdout = json_encode([
            'files' => ['f.php' => ['messages' => [
                ['message' => 'e', 'line' => 1, 'source' => 'r1', 'type' => 'ERROR'],
                ['message' => 'w', 'line' => 2, 'source' => 'r2', 'type' => 'WARNING'],
            ]
            ]
            ],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpcs', false, '', '1s', false, null, 'phpcs', 1, [], false, null, $phpcsStdout),
        ], '1s');

        $formatter = new SarifResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame('error', $data['runs'][0]['results'][0]['level']);
        $this->assertSame('warning', $data['runs'][0]['results'][1]['level']);
    }

    /** @test */
    function it_produces_empty_run_when_no_issues()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpstan', true, '', '1s', false, null, 'phpstan', 0, [], false, null, '{"files":{}}'),
        ], '1s');

        $formatter = new SarifResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame('2.1.0', $data['version']);
        $this->assertCount(1, $data['runs']);
        $this->assertSame([], $data['runs'][0]['results']);
        $this->assertSame('githooks', $data['runs'][0]['tool']['driver']['name']);
    }

    /** @test */
    function it_maps_critical_severity_to_sarif_error_level()
    {
        $psalmStdout = json_encode([
            ['file_name' => 'src/X.php', 'line_from' => 1, 'type' => 'CriticalBug', 'message' => 'x', 'severity' => 'error'],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('psalm', false, '', '1s', false, null, 'psalm', 1, [], false, null, $psalmStdout),
        ], '1s');

        $formatter = new SarifResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame('error', $data['runs'][0]['results'][0]['level']);
    }

    /** @test */
    function rule_entries_include_short_description_matching_rule_id()
    {
        $phpcsStdout = json_encode([
            'files' => ['f.php' => ['messages' => [
                ['message' => 'e', 'line' => 1, 'source' => 'PSR12.Files.EndFileNewline', 'type' => 'ERROR'],
            ]]],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpcs', false, '', '1s', false, null, 'phpcs', 1, [], false, null, $phpcsStdout),
        ], '1s');

        $formatter = new SarifResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $rule = $data['runs'][0]['tool']['driver']['rules'][0];
        $this->assertSame('PSR12.Files.EndFileNewline', $rule['id']);
        $this->assertSame('PSR12.Files.EndFileNewline', $rule['shortDescription']['text']);
    }

    /** @test */
    function it_includes_column_and_end_line_when_available()
    {
        $psalmStdout = json_encode([
            ['file_name' => 'src/Foo.php', 'line_from' => 10, 'line_to' => 12, 'column_from' => 5, 'type' => 'UndefinedVar', 'message' => 'err', 'severity' => 'error'],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('psalm', false, '', '1s', false, null, 'psalm', 1, [], false, null, $psalmStdout),
        ], '1s');

        $formatter = new SarifResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $region = $data['runs'][0]['results'][0]['locations'][0]['physicalLocation']['region'];
        $this->assertSame(10, $region['startLine']);
        $this->assertSame(12, $region['endLine']);
        $this->assertSame(5, $region['startColumn']);
    }
}
