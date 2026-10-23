<?php

namespace Wtyd\Alfil\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\Alfil\Configuration\ConfigurationGenerator;
use Wtyd\Alfil\Configuration\ToolDetector;
use Wtyd\Alfil\Utils\Printer;
use Wtyd\Alfil\Utils\Storage;

class CreateConfigurationFileCommand extends Command
{
    protected $signature = 'conf:init';
    protected $description = 'Creates the configuration file githooks.php in the project path';

    /** The locations the runtime looks a configuration up in, in order. */
    private const CONFIGURATION_FILES = ['githooks.php', 'qa/githooks.php'];

    protected Printer $printer;

    private ToolDetector $toolDetector;

    public function __construct(Printer $printer, ToolDetector $toolDetector)
    {
        $this->printer = $printer;
        $this->toolDetector = $toolDetector;
        parent::__construct();
    }

    public function handle()
    {
        foreach (self::CONFIGURATION_FILES as $candidate) {
            if (Storage::exists($candidate)) {
                $this->printer->error('githooks configuration file already exists');
                return 1;
            }
        }

        $result = $this->input->isInteractive()
            ? $this->interactive()
            : $this->copyDistFile();

        if ($result === 0) {
            $this->ensureGitignoreEntry();
        }

        return $result;
    }

    /**
     * FEAT-5: keep the local run history out of version control. Appends
     * `.githooks/history/` to `.gitignore` (creating the file if needed), only
     * when the line is not already present.
     */
    private function ensureGitignoreEntry(): void
    {
        $line = '.githooks/history/';
        $existing = Storage::exists('.gitignore') ? Storage::get('.gitignore') : '';

        $present = in_array($line, array_map('trim', preg_split('/\R/', $existing) ?: []), true);
        if ($present) {
            return;
        }

        $separator = ($existing !== '' && substr($existing, -1) !== "\n") ? "\n" : '';
        Storage::put('.gitignore', $existing . $separator . $line . "\n");
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
        $distFile = 'githooks.dist.php';

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
