<?php

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library phpmd/phpmd
 */
class Phpmd extends ToolAbstract
{
    public const NAME = self::MESS_DETECTOR;

    public const RULES = 'rules';

    public const EXCLUDE = 'exclude';

    public const PATHS = 'paths';

    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::PATHS,
        self::RULES,
        self::EXCLUDE,
        self::OTHER_ARGS_OPTION,
    ];

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::MESS_DETECTOR;

        $this->setArguments($toolConfiguration->getToolConfiguration());
        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::NAME;
        }
    }

    protected function prepareCommand(): string
    {
        $command = '';
        foreach (self::ARGUMENTS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    $command = $this->args[self::EXECUTABLE_PATH_OPTION];
                    break;
                case self::PATHS:
                    $command .= ' ' . implode(',', $this->args[$option]);
                    break;
                case self::RULES:
                    $command .= ' ansi ' . $this->args[$option];
                    break;
                case self::EXCLUDE:
                    $command .= ' --exclude "' . implode(',', $this->args[$option]) . '"';
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        //tools/php71/phpmd src ansi ./qa/phpmd-ruleset.xml --exclude "vendor"
        return $command;
    }

    /**
     * Método donde se ejecuta la herramienta mediante exec. La herramienta no producirá ninguna salida.
     *
     * @return void
     */
    protected function run(string $command): void
    {
        parent::run($command);
        if ($this->exitCode == 0 && $this->isThereHiddenError()) {
            $this->exitCode = 1;
        }
    }

    /**
     * Check if the exit of Mess detector is OK.
     * If there is an error in the source file that prevents Mess detector from parsing it, Mess detector will return an exit code 0.
     * But mess detector will not be able to check that file.
     *
     * @return bool
     */
    protected function isThereHiddenError()
    {
        if (is_int(strpos($this->exit[3], 'No mess detected'))) {
            return false;
        }
        return true;
    }
}