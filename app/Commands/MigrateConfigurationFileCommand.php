<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Configuration\ConfigurationMigrator;
use Wtyd\GitHooks\Configuration\ConfigurationParser;

class MigrateConfigurationFileCommand extends Command
{
    protected $signature = 'conf:migrate
                            {--config= : Path to configuration file}';

    protected $description = 'Migrate a v2 configuration file (Options/Tools) to v3 format (hooks/flows/jobs)';

    private ConfigurationParser $parser;

    public function __construct(ConfigurationParser $parser)
    {
        parent::__construct();
        $this->parser = $parser;
    }

    public function handle(): int
    {
        $configFile = strval($this->option('config'));

        try {
            $config = $this->parser->parse($configFile);

            if (!$config->isLegacy()) {
                if ($config->hasErrors()) {
                    $this->warn('Configuration file appears to be v3 format but has errors:');
                    foreach ($config->getValidation()->getErrors() as $error) {
                        $this->error("  $error");
                    }
                    $this->info('No migration performed. Fix the errors above or run conf:init to generate a new config.');
                } else {
                    $this->info('Configuration file is already in v3 format. No migration needed.');
                }
                return 0;
            }

            $legacyConfig = $config->getLegacyConfig();
            if ($legacyConfig === null) {
                $this->error('Could not read legacy configuration.');
                return 1;
            }

            $migrator = new ConfigurationMigrator();
            $v3Content = $migrator->migrate($legacyConfig);

            $filePath = $config->getFilePath();
            $backupPath = $filePath . '.v2.bak';

            // Create backup
            if (!copy($filePath, $backupPath)) {
                $this->error("Failed to create backup at $backupPath");
                return 1;
            }
            $this->line("  Backup created: $backupPath");

            // Write new v3 file (always PHP, even if source was YAML)
            $newPath = preg_replace('/\.(yml|yaml)$/', '.php', $filePath);
            file_put_contents($newPath, $v3Content);

            // If source was YAML, remove it
            if ($newPath !== $filePath) {
                unlink($filePath);
                $this->line("  Removed YAML file: $filePath");
            }

            $this->info("  Migrated to v3: $newPath");
            $this->line('');
            $this->warn('  Review the generated file — hook and flow names may need adjustment.');

            return 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
