<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsStderr;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesDiagnosticFormat;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Output\Inspection\SystemInfo;
use Wtyd\GitHooks\Output\Inspection\SystemInfoJsonFormatter;
use Wtyd\GitHooks\Utils\CpuDetector;

class SystemInfoCommand extends Command
{
    use EmitsStderr;
    use ResolvesDiagnosticFormat;

    protected $signature = 'system:info
                            {--config= : Path to configuration file}
                            {--format= : Output format (text, json)}';

    protected $description = 'Show system CPU information and current processes configuration';

    private ConfigurationParser $parser;

    private CpuDetector $cpuDetector;

    public function __construct(ConfigurationParser $parser, CpuDetector $cpuDetector)
    {
        parent::__construct();
        $this->parser = $parser;
        $this->cpuDetector = $cpuDetector;
    }

    public function handle(): int
    {
        $cpus = $this->cpuDetector->detect();
        $format = $this->resolveDiagnosticFormat();

        [$processes, $parseError] = $this->resolveProcesses();
        $info = new SystemInfo($cpus, $processes);

        if ($format === 'json') {
            $this->output->writeln((new SystemInfoJsonFormatter())->format($info));
            return 0;
        }

        return $this->renderText($info, $parseError);
    }

    /**
     * Resolve the configured number of processes from the v3 config.
     *
     * @return array{0: int|null, 1: bool} [processes (null for legacy or no
     *   config), parseError (true only when the config could not be parsed)]
     */
    private function resolveProcesses(): array
    {
        $configFile = strval($this->option('config'));
        try {
            $config = $this->parser->parse($configFile);
            if ($config->isLegacy()) {
                return [null, false];
            }
            return [$config->getGlobalOptions()->getProcesses(), false];
        } catch (\Throwable $e) {
            return [null, true];
        }
    }

    private function renderText(SystemInfo $info, bool $parseError): int
    {
        $cpus = $info->getCpus();
        $processes = $info->getProcesses();

        $this->line('');
        $this->info('System Information');
        $this->line('');
        $this->line("  Available CPUs:  $cpus (detected)");

        if ($parseError) {
            $this->line('');
            $this->line("  No configuration file found. Set 'processes' in githooks.php to control CPU usage.");
        } elseif ($processes !== null) {
            $this->line("  Configured processes:  $processes");
            $this->line('');

            switch ($info->status()) {
                case SystemInfo::STATUS_WARNING:
                    $this->warn('  Warning: ' . $info->warning());
                    break;
                case SystemInfo::STATUS_TIP:
                    $this->line("  Tip: You have $cpus CPUs. Consider increasing 'processes' for parallel execution.");
                    break;
                default:
                    $this->line("  OK: $processes processes within $cpus available CPUs.");
            }
        }

        $this->line('');
        return 0;
    }
}
