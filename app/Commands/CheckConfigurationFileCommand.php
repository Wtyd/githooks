<?php

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\ConfigurationFile\Printer\OptionsTable;
use Wtyd\GitHooks\ConfigurationFile\Printer\ToolsTable;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\ToolsPreparer;
use Wtyd\GitHooks\Utils\Printer;

class CheckConfigurationFileCommand extends Command
{
    protected $signature = 'conf:check  {--config= : Path to configuration file}';
    protected $description = 'Check that the configuration file exists and that it is in the proper format.';

    protected FileReader $fileReader;

    protected Printer $printer;

    protected ToolsPreparer $toolsPreparer;

    protected ToolRegistry $toolRegistry;

    protected ConfigurationParser $configParser;

    protected JobRegistry $jobRegistry;

    public function __construct(
        FileReader $fileReader,
        Printer $printer,
        ToolsPreparer $toolsPreparer,
        ToolRegistry $toolRegistry,
        ConfigurationParser $configParser,
        JobRegistry $jobRegistry
    ) {
        $this->fileReader = $fileReader;
        $this->printer = $printer;
        $this->toolsPreparer = $toolsPreparer;
        $this->toolRegistry = $toolRegistry;
        $this->configParser = $configParser;
        $this->jobRegistry = $jobRegistry;
        parent::__construct();
    }

    public function handle()
    {
        $configFile = strval($this->option('config'));

        // Read the raw config via FileReader (respects testing fakes)
        try {
            $rawFile = $this->fileReader->readfile($configFile);
        } catch (ConfigurationFileNotFoundException $e) {
            $this->printer->resultError($e->getMessage());
            return 1;
        } catch (\Throwable $e) {
            $this->printer->resultError($e->getMessage());
            return 1;
        }

        // Detect format from raw content
        if (!$this->configParser->isLegacyFormat($rawFile)) {
            // v3 format: parse with ConfigurationParser using the file FileReader found
            $filePath = $this->fileReader->getRelativeConfigurationFilePath();
            try {
                $config = $this->configParser->parse($filePath);
                return $this->handleV3($config);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return 1;
            }
        }

        return $this->handleLegacy($configFile);
    }

    protected function handleV3(ConfigurationResult $config): int
    {
        $this->printer->info('Configuration file: ' . $config->getFilePath());
        $this->line('');

        $hasErrors = false;

        // Show errors
        if ($config->hasErrors()) {
            $this->error('The configuration file has some errors');
            foreach ($config->getValidation()->getErrors() as $error) {
                $this->printer->resultError($error);
            }
            $hasErrors = true;
        }

        // Options table
        $options = $config->getGlobalOptions();
        $this->table(
            ['Option', 'Value'],
            [
                ['processes', (string) $options->getProcesses()],
                ['fail-fast', $options->isFailFast() ? 'true' : 'false'],
            ]
        );

        // Hooks table
        $hooks = $config->getHooks();
        if ($hooks !== null) {
            $hookRows = [];
            foreach ($hooks->getAll() as $event => $refs) {
                $targetDescriptions = array_map(function ($ref) {
                    $desc = $ref->getTarget();
                    $conditions = [];
                    if (!empty($ref->getOnlyOnBranches())) {
                        $conditions[] = 'on: ' . implode(',', $ref->getOnlyOnBranches());
                    }
                    if (!empty($ref->getExcludeOnBranches())) {
                        $conditions[] = 'exclude-on: ' . implode(',', $ref->getExcludeOnBranches());
                    }
                    if (!empty($ref->getOnlyFiles())) {
                        $conditions[] = 'files: ' . implode(',', $ref->getOnlyFiles());
                    }
                    if (!empty($ref->getExcludeFiles())) {
                        $conditions[] = 'exclude: ' . implode(',', $ref->getExcludeFiles());
                    }
                    if (!empty($conditions)) {
                        $desc .= ' [' . implode('; ', $conditions) . ']';
                    }
                    return $desc;
                }, $refs);
                $hookRows[] = [$event, implode(', ', $targetDescriptions)];
            }
            if (!empty($hookRows)) {
                $this->line('');
                $this->table(['Hook Event', 'Targets'], $hookRows);
            }
        }

        // Flows table
        $flows = $config->getFlows();
        if (!empty($flows)) {
            $flowRows = [];
            foreach ($flows as $name => $flow) {
                $flowRows[] = [$name, implode(', ', $flow->getJobs())];
            }
            $this->line('');
            $this->table(['Flow', 'Jobs'], $flowRows);
        }

        // Jobs table with command and validation status
        $jobs = $config->getJobs();
        if (!empty($jobs)) {
            $jobRows = [];
            foreach ($jobs as $name => $job) {
                try {
                    $jobInstance = $this->jobRegistry->create($job);
                    $command = $jobInstance->buildCommand();
                    $status = $this->validateJob($jobInstance, $job);
                } catch (\Throwable $e) {
                    $command = '(error: ' . $e->getMessage() . ')';
                    $status = 'error';
                }
                $jobRows[] = [$name, $command, $status];
            }
            $this->line('');
            $this->table(['Job', 'Command', 'Status'], $jobRows);
        }

        // Warnings
        foreach ($config->getValidation()->getWarnings() as $warning) {
            $this->printer->resultWarning($warning);
        }

        if (!$hasErrors) {
            $this->line('');
            $this->info('The configuration file has the correct format.');
        }

        return $hasErrors ? 1 : 0;
    }

    /**
     * @param \Wtyd\GitHooks\Jobs\JobAbstract $jobInstance
     * @param \Wtyd\GitHooks\Configuration\JobConfiguration $jobConfig
     */
    private function validateJob($jobInstance, $jobConfig): string
    {
        $warnings = [];

        $this->validateExecutable($jobInstance->getExecutable(), $warnings);
        $this->validatePaths($jobConfig->getPaths(), $warnings);
        $this->validateConfigFiles($jobConfig->getConfig(), $warnings);

        return empty($warnings) ? "\u{2714}" : implode('; ', $warnings);
    }

    /** @param string[] &$warnings */
    private function validateExecutable(string $executable, array &$warnings): void
    {
        if ($executable !== '' && !$this->executableExists($executable)) {
            $warnings[] = "executable '$executable' not found";
        }
    }

    /**
     * @param string[] $paths
     * @param string[] &$warnings
     */
    private function validatePaths(array $paths, array &$warnings): void
    {
        foreach ($paths as $path) {
            if (!is_dir($path) && !file_exists($path)) {
                $warnings[] = "path '$path' not found";
            }
        }
    }

    /**
     * @param array<string, mixed> $jobArgs
     * @param string[] &$warnings
     */
    private function validateConfigFiles(array $jobArgs, array &$warnings): void
    {
        $this->checkFileRef($jobArgs, 'config', 'config file', $warnings);
        // Only validate 'rules' as file path if it looks like a path (contains / or .xml)
        if (
            !empty($jobArgs['rules']) && is_string($jobArgs['rules'])
            && (strpos($jobArgs['rules'], '/') !== false || strpos($jobArgs['rules'], '.xml') !== false)
        ) {
            $this->checkFileRef($jobArgs, 'rules', 'rules file', $warnings);
        }
    }

    /**
     * @param array<string, mixed> $args
     * @param string[] &$warnings
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    private function checkFileRef(array $args, string $key, string $label, array &$warnings, bool $skipCsv = false): void
    {
        if (empty($args[$key]) || !is_string($args[$key])) {
            return;
        }
        if ($skipCsv && strpos($args[$key], ',') !== false) {
            return;
        }
        if (!file_exists($args[$key])) {
            $warnings[] = "$label '{$args[$key]}' not found";
        }
    }

    private function executableExists(string $executable): bool
    {
        if (file_exists($executable)) {
            return true;
        }
        $output = [];
        $code = 0;
        exec('which ' . escapeshellarg($executable) . ' 2>/dev/null', $output, $code);
        return $code === 0;
    }

    protected function handleLegacy(string $configFile = ''): int
    {
        $errors = new Errors();
        try {
            $file = $this->fileReader->readfile($configFile);

            $this->printer->info('Configuration file: ' . $this->fileReader->getRelativeConfigurationFilePath());

            $configurationFile = new ConfigurationFile($file, ConfigurationFile::ALL_TOOLS, $this->toolRegistry);

            $optionsTable = new OptionsTable($configurationFile);
            $this->table(
                $optionsTable->getHeaders(),
                $optionsTable->getRows()
            );

            $tools = $this->toolsPreparer->__invoke($configurationFile);

            $toolsTable = new ToolsTable($tools);

            $this->table(
                $toolsTable->getHeaders(),
                $toolsTable->getRows()
            );

            $this->info('The configuration file has the correct format.');

            $this->warn("Legacy configuration format detected. Run 'githooks conf:migrate' to upgrade to v3.");
        } catch (ConfigurationFileNotFoundException $exception) {
            $errors->setError('set error', 'to return 1');
            $this->printer->resultError($exception->getMessage());
        } catch (ConfigurationFileException $exception) {
            $this->error($exception->getMessage());
            $errors->setError('set error', 'to return 1');

            foreach ($exception->getConfigurationFile()->getErrors() as $error) {
                $this->printer->resultError($error);
            }
            $this->printWarnings($exception->getConfigurationFile()->getWarnings());
        }

        $exitCode = 0;
        if ($errors->isEmpty()) {
            if (isset($configurationFile)) {
                $this->printWarnings($configurationFile->getWarnings());
            }
        } else {
            $exitCode = 1;
        }

        return $exitCode;
    }

    protected function printWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->printer->resultWarning($warning);
        }
    }
}
