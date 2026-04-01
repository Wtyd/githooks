<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Configuration\ConfigurationParser;

class SystemInfoCommand extends Command
{
    protected $signature = 'system:info
                            {-c|--config= : Path to configuration file}';

    protected $description = 'Show system CPU information and current processes configuration';

    private ConfigurationParser $parser;

    public function __construct(ConfigurationParser $parser)
    {
        parent::__construct();
        $this->parser = $parser;
    }

    public function handle(): int
    {
        $cpus = $this->detectCpus();

        $this->line('');
        $this->info('System Information');
        $this->line('');
        $this->line("  Available CPUs:  $cpus (detected)");

        $configFile = strval($this->option('config'));
        try {
            $config = $this->parser->parse($configFile);
            if (!$config->isLegacy()) {
                $processes = $config->getGlobalOptions()->getProcesses();
                $this->line("  Configured processes:  $processes");
                $this->line('');

                if ($processes > $cpus) {
                    $this->warn("  Warning: 'processes' ($processes) exceeds available CPUs ($cpus). This may saturate the machine.");
                } elseif ($processes === 1) {
                    $this->line("  Tip: You have $cpus CPUs. Consider increasing 'processes' for parallel execution.");
                } else {
                    $this->line("  OK: $processes processes within $cpus available CPUs.");
                }
            }
        } catch (\Throwable $e) {
            $this->line('');
            $this->line("  No configuration file found. Set 'processes' in githooks.php to control CPU usage.");
        }

        $this->line('');
        return 0;
    }

    private function detectCpus(): int
    {
        // Linux: nproc
        $output = [];
        $exitCode = 0;
        exec('nproc 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            return (int) $output[0];
        }

        // macOS: sysctl
        exec('sysctl -n hw.ncpu 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output)) {
            return (int) $output[0];
        }

        // Fallback: /proc/cpuinfo
        if (is_readable('/proc/cpuinfo')) {
            $content = file_get_contents('/proc/cpuinfo');
            if ($content !== false) {
                return substr_count($content, 'processor');
            }
        }

        return 1;
    }
}
