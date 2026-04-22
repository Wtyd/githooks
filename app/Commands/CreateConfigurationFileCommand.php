<?php

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Configuration\ConfigurationGenerator;
use Wtyd\GitHooks\Configuration\ToolDetector;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\Utils\Printer;
use Wtyd\GitHooks\Utils\Storage;

class CreateConfigurationFileCommand extends Command
{
    protected $signature = 'conf:init {--legacy : Generate legacy (v2) format instead of v3 hooks/flows/jobs}';
    protected $description = 'Creates the configuration file githooks.php in the project path';

    protected Printer $printer;

    protected FileReader $fileReader;

    private ToolDetector $toolDetector;

    public function __construct(Printer $printer, FileReader $fileReader, ToolDetector $toolDetector)
    {
        $this->printer = $printer;
        $this->fileReader = $fileReader;
        $this->toolDetector = $toolDetector;
        parent::__construct();
    }

    public function handle()
    {
        try {
            $this->fileReader->findConfigurationFile();
            $this->printer->error('githooks configuration file already exists');
            return 1;
        } catch (ConfigurationFileNotFoundException $ex) {
            // OK — no config exists, we can create one
        }

        if (!$this->input->isInteractive() || $this->option('legacy')) {
            return $this->copyDistFile();
        }

        return $this->interactive();
    }

    /** @SuppressWarnings(PHPMD.CyclomaticComplexity) Interactive flow with multiple user prompts */
    private function interactive(): int
    {
        $detected = $this->toolDetector->detect();

        if (empty($detected)) {
            $this->warn('No QA tools detected in vendor/bin/. Falling back to template.');
            return $this->copyDistFile();
        }

        $this->info('Detected QA tools in vendor/bin/:');

        $selectedTools = [];
        foreach ($detected as $type) {
            if ($this->confirm("  Include $type?", true)) {
                $selectedTools[] = $type;
            }
        }

        if (empty($selectedTools)) {
            $this->warn('No tools selected. Falling back to template.');
            return $this->copyDistFile();
        }

        /** @var string $pathsInput */
        $pathsInput = $this->ask('Source directories (comma-separated)', 'src');
        $paths = array_map('trim', explode(',', $pathsInput));

        /** @var string $hookChoice */
        $hookChoice = $this->choice(
            'Which hook events to configure?',
            ['pre-commit', 'pre-push', 'both', 'none'],
            0
        );

        $hookEvents = [];
        if ($hookChoice === 'pre-commit') {
            $hookEvents = ['pre-commit'];
        } elseif ($hookChoice === 'pre-push') {
            $hookEvents = ['pre-push'];
        } elseif ($hookChoice === 'both') {
            $hookEvents = ['pre-commit', 'pre-push'];
        }

        $generator = new ConfigurationGenerator();
        $content = $generator->generate($selectedTools, $paths, $hookEvents);

        Storage::put('githooks.php', $content);
        $this->printer->success('Configuration file githooks.php created with ' . count($selectedTools) . ' tool(s).');

        return 0;
    }

    private function copyDistFile(): int
    {
        $distFile = $this->option('legacy')
            ? 'githooks.dist.yml'
            : 'githooks.dist.php';

        $origin = $this->resolveDistFile($distFile);

        if ($origin === null) {
            $this->printer->error("Distribution file '$distFile' not found.");
            return 1;
        }

        return $this->copyFile($origin, 'githooks.php');
    }

    protected function resolveDistFile(string $distFile): ?string
    {
        $candidates = [
            "vendor/wtyd/githooks/qa/$distFile",
            "qa/$distFile",
        ];

        foreach ($candidates as $path) {
            if (Storage::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function copyFile(string $origin, string $destiny): int
    {
        try {
            if (Storage::copy($origin, $destiny)) {
                $this->printer->success('Configuration file githooks.php has been created in root path');
                return 0;
            }
            $this->printer->error("Failed to copy $origin to $destiny");
            return 1;
        } catch (\Throwable $th) {
            $this->printer->error("Failed to copy $origin to $destiny");
            return 1;
        }
    }
}
