<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Execution\FlowResult;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Output\CodeClimateResultFormatter;

class CodeClimateResultFormatterTest extends TestCase
{
    /** @test */
    function it_produces_valid_code_climate_json()
    {
        $phpstanStdout = json_encode([
            'totals' => ['errors' => 1],
            'files' => [
                'src/User.php' => [
                    'messages' => [['message' => 'Method not found', 'line' => 14]],
                ],
            ],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, 'output', '1s', false, null, 'phpstan', 1, ['src'], false, null, $phpstanStdout),
        ], '1s');

        $formatter = new CodeClimateResultFormatter();
        $json = $formatter->format($result);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Method not found', $data[0]['description']);
        $this->assertSame('phpstan', $data[0]['check_name']);
        $this->assertSame('major', $data[0]['severity']);
        $this->assertSame('src/User.php', $data[0]['location']['path']);
        $this->assertSame(14, $data[0]['location']['lines']['begin']);
        $this->assertNotEmpty($data[0]['fingerprint']);
    }

    /** @test */
    function it_aggregates_issues_from_multiple_tools()
    {
        $phpstanStdout = json_encode([
            'files' => ['src/A.php' => ['messages' => [['message' => 'err1', 'line' => 1]]]],
        ]);
        $phpcsStdout = json_encode([
            'files' => ['src/B.php' => ['messages' => [
                ['message' => 'err2', 'line' => 2, 'column' => 1, 'source' => 'PSR12.Rule', 'type' => 'ERROR'],
            ]
            ]
            ],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, '', '1s', false, null, 'phpstan', 1, [], false, null, $phpstanStdout),
            new JobResult('phpcs_src', false, '', '1s', false, null, 'phpcs', 1, [], false, null, $phpcsStdout),
        ], '2s');

        $formatter = new CodeClimateResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertCount(2, $data);
        $this->assertSame('phpstan', $data[0]['check_name']);
        $this->assertSame('PSR12.Rule', $data[1]['check_name']);
    }

    /** @test */
    function it_skips_unsupported_tools()
    {
        $result = new FlowResult('qa', [
            new JobResult('phpunit', true, 'OK', '1s', false, null, 'phpunit', 0, [], false, null, 'not json'),
        ], '1s');

        $formatter = new CodeClimateResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame([], $data);
    }

    /** @test */
    function it_returns_empty_array_when_no_issues()
    {
        $phpstanStdout = json_encode(['totals' => ['errors' => 0], 'files' => []]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', true, '', '1s', false, null, 'phpstan', 0, [], false, null, $phpstanStdout),
        ], '1s');

        $formatter = new CodeClimateResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame([], $data);
    }

    /** @test */
    function fingerprints_are_deterministic()
    {
        $stdout = json_encode([
            'files' => ['src/A.php' => ['messages' => [['message' => 'err', 'line' => 1]]]],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan', false, '', '1s', false, null, 'phpstan', 1, [], false, null, $stdout),
        ], '1s');

        $formatter = new CodeClimateResultFormatter();
        $data1 = json_decode($formatter->format($result), true);
        $data2 = json_decode($formatter->format($result), true);

        $this->assertSame($data1[0]['fingerprint'], $data2[0]['fingerprint']);
    }

    /** @test */
    function it_relativizes_absolute_paths_within_cwd()
    {
        $cwd = getcwd();
        $absolutePath = $cwd . '/src/errors/SyntaxError.php';

        $phpstanStdout = json_encode([
            'files' => [
                $absolutePath => [
                    'messages' => [['message' => 'Boom', 'line' => 7]],
                ],
            ],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, '', '1s', false, null, 'phpstan', 1, [], false, null, $phpstanStdout),
        ], '1s');

        $data = json_decode((new CodeClimateResultFormatter())->format($result), true);

        $this->assertSame('src/errors/SyntaxError.php', $data[0]['location']['path']);
    }

    /** @test */
    function it_keeps_paths_outside_cwd_unchanged()
    {
        $outsidePath = '/tmp/out-of-project/File.php';

        $phpstanStdout = json_encode([
            'files' => [
                $outsidePath => [
                    'messages' => [['message' => 'Boom', 'line' => 1]],
                ],
            ],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, '', '1s', false, null, 'phpstan', 1, [], false, null, $phpstanStdout),
        ], '1s');

        $data = json_decode((new CodeClimateResultFormatter())->format($result), true);

        $this->assertSame($outsidePath, $data[0]['location']['path']);
    }

    /** @test */
    function it_keeps_already_relative_paths_unchanged()
    {
        $phpstanStdout = json_encode([
            'files' => [
                'src/Relative.php' => [
                    'messages' => [['message' => 'Boom', 'line' => 1]],
                ],
            ],
        ]);

        $result = new FlowResult('qa', [
            new JobResult('phpstan_src', false, '', '1s', false, null, 'phpstan', 1, [], false, null, $phpstanStdout),
        ], '1s');

        $data = json_decode((new CodeClimateResultFormatter())->format($result), true);

        $this->assertSame('src/Relative.php', $data[0]['location']['path']);
    }

    /** @test */
    function it_maps_severities_correctly()
    {
        // PHPCS produces both errors and warnings
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

        $formatter = new CodeClimateResultFormatter();
        $data = json_decode($formatter->format($result), true);

        $this->assertSame('major', $data[0]['severity']);
        $this->assertSame('minor', $data[1]['severity']);
    }
}
