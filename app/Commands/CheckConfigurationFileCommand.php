<?php

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\App\Commands\Concerns\EmitsStderr;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesDiagnosticFormat;
use Wtyd\GitHooks\Configuration\ConfigurationChecker;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\HookConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\ConfigurationFile\Printer\OptionsTable;
use Wtyd\GitHooks\ConfigurationFile\Printer\ToolsTable;
use Wtyd\GitHooks\Output\Inspection\ConfigCheckJsonFormatter;
use Wtyd\GitHooks\Output\Inspection\ConfigCheckResult;
use Wtyd\GitHooks\Registry\ToolRegistry;
use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\ToolsPreparer;
use Wtyd\GitHooks\Utils\Printer;

class CheckConfigurationFileCommand extends Command
{
    use EmitsStderr;
    use ResolvesDiagnosticFormat;

    protected $signature = 'conf:check
                            {--config= : Path to configuration file}
                            {--format= : Output format (text, json)}';
    protected $description = 'Check that the configuration file exists and that it is in the proper format.';

    protected FileReader $fileReader;

    protected Printer $printer;

    protected ToolsPreparer $toolsPreparer;

    protected ToolRegistry $toolRegistry;

    protected ConfigurationParser $configParser;

    protected JobRegistry $jobRegistry;

    protected ConfigurationChecker $checker;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Aggregates the config-read pipeline (reader,
     *   parser, tool registry) plus the rendering helpers (printer) and the pure-validation
     *   checker; splitting would force the Command to know more about the wiring.
     */
    public function __construct(
        FileReader $fileReader,
        Printer $printer,
        ToolsPreparer $toolsPreparer,
        ToolRegistry $toolRegistry,
        ConfigurationParser $configParser,
        JobRegistry $jobRegistry,
        ConfigurationChecker $checker
    ) {
        $this->fileReader = $fileReader;
        $this->printer = $printer;
        $this->toolsPreparer = $toolsPreparer;
        $this->toolRegistry = $toolRegistry;
        $this->configParser = $configParser;
        $this->jobRegistry = $jobRegistry;
        $this->checker = $checker;
        parent::__construct();
    }

    public function handle()
    {
        if ($this->resolveDiagnosticFormat() === 'json') {
            return $this->handleJson(strval($this->option('config')));
        }

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

    private function handleJson(string $configFile): int
    {
        try {
            $rawFile = $this->fileReader->readfile($configFile);
        } catch (\Throwable $e) {
            return $this->emitJsonError($e->getMessage());
        }

        $filePath = $this->fileReader->getRelativeConfigurationFilePath();

        if ($this->configParser->isLegacyFormat($rawFile)) {
            return $this->emitLegacyJson($rawFile, $filePath);
        }

        try {
            $config = $this->configParser->parse($filePath);
        } catch (\Throwable $e) {
            return $this->emitJsonError($e->getMessage());
        }

        $result = $this->buildResult($config);
        $this->output->writeln((new ConfigCheckJsonFormatter())->format($result));

        return $result->isValid() ? 0 : 1;
    }

    /**
     * Emit a minimal structured error (read/parse failure) keeping stdout
     * parseable, and signal failure with exit 1 — same as the text path.
     */
    private function emitJsonError(string $message): int
    {
        $this->output->writeln((string) json_encode([
            'version' => 1,
            'valid' => false,
            'errors' => [$message],
            'warnings' => [],
            'deprecations' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return 1;
    }

    /**
     * @param array<string, mixed> $rawConfig the parsed config array from FileReader::readfile
     */
    private function emitLegacyJson(array $rawConfig, string $filePath): int
    {
        $errors = [];
        $warnings = [];

        try {
            $configurationFile = new ConfigurationFile($rawConfig, ConfigurationFile::ALL_TOOLS, $this->toolRegistry);
            $this->toolsPreparer->__invoke($configurationFile);
            $warnings = $configurationFile->getWarnings();
        } catch (ConfigurationFileException $e) {
            $errors = $e->getConfigurationFile()->getErrors();
            $warnings = $e->getConfigurationFile()->getWarnings();
        }

        $result = ConfigCheckResult::legacy($filePath, null, [
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'deprecations' => [],
            'hint' => "Run 'githooks conf:migrate' to upgrade to v3.",
        ]);

        $this->output->writeln((new ConfigCheckJsonFormatter())->format($result));

        return $result->isValid() ? 0 : 1;
    }

    private function buildResult(ConfigurationResult $config): ConfigCheckResult
    {
        $options = $config->getGlobalOptions();

        $errors = $config->getValidation()->getErrors();
        $warnings = $config->getValidation()->getWarnings();

        // Report-path checks (global options) — mirror the text path's filesystem-level validation.
        $reportCheck = $this->checker->validateReportsPaths($options->getReports(), 'flows.options');
        $errors = array_merge($errors, $reportCheck['errors']);
        $warnings = array_merge($warnings, $reportCheck['warnings']);

        $flowsPayload = [];
        foreach ($config->getFlows() as $name => $flow) {
            $flowsPayload[] = [
                'name' => $name,
                'meta' => $flow->isMetaFlow(),
                'jobs' => $flow->getJobs(),
                'flows' => $flow->getFlowReferences(),
            ];
            $flowOptions = $flow->getOptions();
            if ($flowOptions !== null) {
                $flowCheck = $this->checker->validateReportsPaths($flowOptions->getReports(), "flows.$name.options");
                $errors = array_merge($errors, $flowCheck['errors']);
                $warnings = array_merge($warnings, $flowCheck['warnings']);
            }
        }

        $deprecations = [];
        foreach ($config->getValidation()->getDeprecations() as $deprecation) {
            $deprecations[] = $deprecation->toArray();
        }

        return ConfigCheckResult::forV3(
            $config->getFilePath(),
            $config->getLocalFilePath(),
            $this->buildOptionsPayload($options),
            $this->buildHooksPayload($config->getHooks()),
            $flowsPayload,
            $this->buildJobsPayload($config->getJobs()),
            [
                'errors' => array_values($errors),
                'warnings' => array_values($warnings),
                'deprecations' => $deprecations,
                'hint' => null,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOptionsPayload(OptionsConfiguration $options): array
    {
        $memoryBudget = $options->getMemoryBudget();

        return [
            'processes' => $options->getProcesses(),
            'failFast' => $options->isFailFast(),
            'executablePrefix' => $options->getExecutablePrefix(),
            'reports' => $options->getReports(),
            'memoryBudget' => $memoryBudget === null ? null : [
                'warnAbove' => $memoryBudget->getWarnAbove(),
                'failAbove' => $memoryBudget->getFailAbove(),
            ],
            'allocator' => $options->getAllocator(),
            'stats' => $options->isStats(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildHooksPayload(?HookConfiguration $hooks): array
    {
        if ($hooks === null) {
            return [];
        }

        $payload = [];
        foreach ($hooks->getAll() as $event => $refs) {
            $targets = [];
            foreach ($refs as $ref) {
                $targets[] = [
                    'target' => $ref->getTarget(),
                    'onlyOn' => $ref->getOnlyOnBranches(),
                    'excludeOn' => $ref->getExcludeOnBranches(),
                    'onlyFiles' => $ref->getOnlyFiles(),
                    'excludeFiles' => $ref->getExcludeFiles(),
                ];
            }
            $payload[] = ['event' => $event, 'targets' => $targets];
        }

        return $payload;
    }

    /**
     * @param array<string, \Wtyd\GitHooks\Configuration\JobConfiguration> $jobs
     * @return array<int, array<string, mixed>>
     */
    private function buildJobsPayload(array $jobs): array
    {
        $payload = [];
        foreach ($jobs as $name => $job) {
            try {
                $jobInstance = $this->jobRegistry->create($job);
                $coresOverride = $jobInstance->getCoresOverride();
                if ($coresOverride !== null) {
                    $jobInstance->applyThreadLimit($coresOverride);
                }
                $command = $jobInstance->buildCommand();
                $issues = $this->checker->validateJob($jobInstance, $job);
                $status = empty($issues) ? 'ok' : 'warning';
            } catch (\Throwable $e) {
                $command = '(error: ' . $e->getMessage() . ')';
                $issues = [$e->getMessage()];
                $status = 'error';
            }
            $payload[] = [
                'name' => $name,
                'command' => $command,
                'status' => $status,
                'issues' => array_values($issues),
            ];
        }

        return $payload;
    }

    protected function handleV3(ConfigurationResult $config): int
    {
        $this->printer->info('Configuration file: ' . $config->getFilePath());
        if ($config->getLocalFilePath() !== null) {
            $this->printer->info('Local override: ' . $config->getLocalFilePath());
        }
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
        $optionRows = [
            ['processes', (string) $options->getProcesses()],
            ['fail-fast', $options->isFailFast() ? 'true' : 'false'],
        ];
        if ($options->getExecutablePrefix() !== '') {
            $optionRows[] = ['executable-prefix', $options->getExecutablePrefix()];
        }
        if (!empty($options->getReports())) {
            foreach ($options->getReports() as $format => $path) {
                $optionRows[] = ["reports.$format", $path];
            }
        }
        $memoryBudget = $options->getMemoryBudget();
        if ($memoryBudget !== null) {
            if ($memoryBudget->getWarnAbove() !== null) {
                $optionRows[] = ['memory-budget.warn-above', $memoryBudget->getWarnAbove() . ' MB'];
            }
            if ($memoryBudget->getFailAbove() !== null) {
                $optionRows[] = ['memory-budget.fail-above', $memoryBudget->getFailAbove() . ' MB'];
            }
        }
        if ($options->hasKey('allocator')) {
            $optionRows[] = ['allocator', $options->getAllocator()];
        }
        if ($options->hasKey('stats')) {
            $optionRows[] = ['stats', $options->isStats() ? 'true' : 'false'];
        }
        $this->table(['Option', 'Value'], $optionRows);

        // Validate reports paths (filesystem-level — structural errors are caught
        // in OptionsConfiguration::fromArray and surfaced via the validation result).
        if ($this->validateReportsPaths($options->getReports(), 'flows.options')) {
            $hasErrors = true;
        }

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

        // Flows table — meta-flows are distinguished by a (meta) tag and show their expanded references
        $flows = $config->getFlows();
        if (!empty($flows)) {
            $flowRows = [];
            foreach ($flows as $name => $flow) {
                if ($flow->isMetaFlow()) {
                    $flowRows[] = [$name . ' (meta)', '→ ' . implode(', ', $flow->getFlowReferences())];
                    continue;
                }
                $flowRows[] = [$name, implode(', ', $flow->getJobs())];
            }
            $this->line('');
            $this->table(['Flow', 'Jobs / Flows'], $flowRows);

            foreach ($flows as $name => $flow) {
                $flowOptions = $flow->getOptions();
                if ($flowOptions === null) {
                    continue;
                }
                if ($this->validateReportsPaths($flowOptions->getReports(), "flows.$name.options")) {
                    $hasErrors = true;
                }
            }
        }

        // Jobs table with command and validation status
        $hasValidationWarnings = false;
        $jobs = $config->getJobs();
        if (!empty($jobs)) {
            $jobRows = [];
            foreach ($jobs as $name => $job) {
                try {
                    $jobInstance = $this->jobRegistry->create($job);
                    // Apply the declared cores override so the rendered command
                    // reflects the user's intent (cores wins over the native
                    // flag, native flag-only is promoted as cores). conf:check
                    // does not have flow context, so the budget clamp is NOT
                    // applied — the table shows the declared value, not the
                    // runtime-clamped one.
                    $coresOverride = $jobInstance->getCoresOverride();
                    if ($coresOverride !== null) {
                        $jobInstance->applyThreadLimit($coresOverride);
                    }
                    $command = $jobInstance->buildCommand();
                    $status = $this->validateJob($jobInstance, $job);
                    if ($status !== '<fg=green>✔</>') {
                        $hasValidationWarnings = true;
                    }
                } catch (\Throwable $e) {
                    $command = '(error: ' . $e->getMessage() . ')';
                    $status = '<fg=red>error</>';
                    $hasValidationWarnings = true;
                }
                $jobRows[] = [$name, $this->checker->truncateCommand($command), $status];
            }
            $this->line('');
            $this->table(['Job', 'Command', 'Status'], $jobRows);
        }

        // Deprecations to stderr (non-payload), normal warnings to stdout.
        // Both share the warnings[] list inside ValidationResult so JSON/SARIF
        // consumers don't lose information; here we route by source.
        $deprecationMessages = [];
        foreach ($config->getValidation()->getDeprecations() as $deprecation) {
            $message = $deprecation->getWarningMessage();
            $deprecationMessages[$message] = true;
            $this->emitStderr("⚠️  $message");
        }
        foreach ($config->getValidation()->getWarnings() as $warning) {
            if (isset($deprecationMessages[$warning])) {
                continue;
            }
            $this->printer->resultWarning($warning);
        }

        if (!$hasErrors) {
            $this->line('');
            if ($hasValidationWarnings) {
                $this->warn('The configuration format is correct, but some jobs have validation warnings (see Status column).');
            } else {
                $this->info('The configuration file has the correct format.');
            }
        }

        return $hasErrors ? 1 : 0;
    }

    /**
     * @param \Wtyd\GitHooks\Jobs\JobAbstract $jobInstance
     * @param \Wtyd\GitHooks\Configuration\JobConfiguration $jobConfig
     */
    private function validateJob($jobInstance, $jobConfig): string
    {
        $warnings = $this->checker->validateJob($jobInstance, $jobConfig);

        if (empty($warnings)) {
            return '<fg=green>✔</>';
        }

        return '<fg=red>' . implode('; ', $warnings) . '</>';
    }

    /**
     * Render the report-path checks via the printer. Returns true when any
     * error was emitted so the caller can mark the overall result as failed.
     *
     * @param array<string, string> $reports
     */
    private function validateReportsPaths(array $reports, string $context): bool
    {
        $result = $this->checker->validateReportsPaths($reports, $context);
        foreach ($result['errors'] as $error) {
            $this->printer->resultError($error);
        }
        foreach ($result['warnings'] as $warning) {
            $this->printer->resultWarning($warning);
        }
        return !empty($result['errors']);
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
