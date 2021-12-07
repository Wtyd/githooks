<?php

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library la libreria sebastian/phpcpd
 */
class Phpcpd extends ToolAbstract
{
    public const NAME = self::COPYPASTE_DETECTOR;
    /**
     * @var string EXCLUDE Tag que indica los ficheros excluidos para phpcs en el fichero de configuracion .yml. Su valor es un array de strings.
     */
    public const EXCLUDE = 'exclude';

    /**
     * @var string PATH Tag que indica los ficheros sobre los que se ejecutará phpcpd en el fichero de configuracion .yml
     */
    public const PATHS = 'paths';

    public const OPTIONS = [
        self::EXECUTABLE_PATH_OPTION,
        self::EXCLUDE,
        self::OTHER_ARGS_OPTION,
        self::PATHS
    ];

    /**
     * @var array
     */
    protected $args;

    //TODO add --names-exclude option. Is like --exclude but for files. Check 6.* interfaces because bring changes.
    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::COPYPASTE_DETECTOR;

        $this->setArguments($toolConfiguration->getToolConfiguration());
        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::NAME;
        }
    }

    protected function prepareCommand(): string
    {
        $command = '';
        foreach (self::OPTIONS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    $command = $this->args[self::EXECUTABLE_PATH_OPTION];
                    break;
                case self::PATHS:
                    $command .= ' ' . implode(' ', $this->args[self::PATHS]);
                    break;
                case self::EXCLUDE:
                    $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE], '--exclude ');
                    $command .= ' ' . implode(' ', $prefix);
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        // tools/php71/phpcpd --exclude vendor --exclude tests ./
        return $command;
        // $exclude = '';
        // if (!empty($this->args[self::EXCLUDE])) {
        //     $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE], '--exclude ');
        //     $exclude = implode(' ', $prefix);
        // }

        // $paths = ''; // If path is empty phpmd will not work
        // if (!empty($this->args[self::PATHS])) {
        //     $paths = implode(' ', $this->args[self::PATHS]);
        // }

        // $arguments = "$exclude $paths";

        // // tools/php71/phpcpd --exclude vendor --exclude tests ./
        // return $this->executablePath . ' ' . $arguments;
    }

    protected function getName()
    {
        return self::NAME;
    }
}
