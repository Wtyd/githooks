<?php

namespace GitHooks\Commands\Console;

use GitHooks\Commands\Tools\{
    CheckSecurityCommand,
    CodeSnifferCommand,
    CopyPasteDetectorCommand,
    MessDetectorCommand,
    ParallelLintCommand,
    StanCommand,
    ExecuteAllToolsCommand
};
use GitHooks\Commands\{
    CheckConfigurationFileCommand,
    CleanHookCommand,
    CreateConfigurationFileCommand,
    CreateHookCommand
};

class RegisterCommands
{
    public function __invoke(): array
    {
        return [
            //Tools Commands
            CheckSecurityCommand::class,
            CodeSnifferCommand::class,
            CopyPasteDetectorCommand::class,
            ExecuteAllToolsCommand::class,
            MessDetectorCommand::class,
            ParallelLintCommand::class,
            StanCommand::class,
            //Other Commands
            CheckConfigurationFileCommand::class,
            CleanHookCommand::class,
            CreateConfigurationFileCommand::class,
            CreateHookCommand::class,
        ];
    }
}
