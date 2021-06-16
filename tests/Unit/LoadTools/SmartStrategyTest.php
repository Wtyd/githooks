<?php

namespace Tests\Unit\LoadTools;

use Wtyd\GitHooks\LoadTools\SmartStrategy;
use Wtyd\GitHooks\Tools\CodeSniffer;
use Wtyd\GitHooks\Tools\CopyPasteDetector;
use Wtyd\GitHooks\Tools\CheckSecurity;
use Wtyd\GitHooks\Tools\MessDetector;
use Wtyd\GitHooks\Tools\ParallelLint;
use Wtyd\GitHooks\Tools\ToolsFactoy;
use Wtyd\GitHooks\Utils\FileUtils;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Utils\FileUtilsFake;

class SmartStrategyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function confFileForPhpcsProvider()
    {
        return [
            'Phpcs no tiene configuración' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcs'],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'prueba.php']
            ],
            'Phpcs tiene configuración pero no hay directorios ignorados' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcs'],
                    'phpcs' => [
                        'standard' => './qa/phpcs-softruleset.xml',
                        'error-severity' => 1,
                        'warning-severity' => 6,
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'prueba.php']
            ],
            'Phpcs tiene configuración con directorios ignorados pero algún fichero modificado no pertenece a esos directorios' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcs'],
                    'phpcs' => [
                        'standard' => './qa/phpcs-softruleset.xml',
                        'ignore' => ['app'],
                        'error-severity' => 1,
                        'warning-severity' => 6,
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'app/prueba.php']
            ],
            'Phpcs tiene configurado el tag ignore pero está vacío' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcs'],
                    'phpcs' => [
                        'standard' => './qa/phpcs-softruleset.xml',
                        'ignore' => [],
                        'error-severity' => 1,
                        'warning-severity' => 6,
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'app/prueba.php']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider confFileForPhpcsProvider
     */
    function it_run_phpcs($configurationFile, $modifiedFiles)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf(CodeSniffer::class, $loadedTools['phpcs']);
    }

    public function confFileForSkipPhpcsProvider()
    {
        return [
            'Todos los ficheros modificados pertenecen al mismo directorio ignorado' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcs'],
                    'phpcs' => [
                        'standard' => './qa/phpcs-softruleset.xml',
                        'ignore' => ['app'],
                        'error-severity' => 1,
                        'warning-severity' => 6,
                    ],
                ],
                'Ficheros Modificados' => ['app/file1.php', 'app/file2.php']
            ],
            'Cada fichero pertenece a un directorio ignorado distinto' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcs'],
                    'phpcs' => [
                        'standard' => './qa/phpcs-softruleset.xml',
                        'ignore' => ['app', 'src'],
                        'error-severity' => 1,
                        'warning-severity' => 6,
                    ],
                ],
                'Ficheros Modificados' => ['app/file1.php', 'src/file2.php']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider confFileForSkipPhpcsProvider
     */
    function it_skip_phpcs_when_all_modified_files_are_in_ignore_paths($configurationFile, $modifiedFiles)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories($modifiedFiles);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(0, $loadedTools);
    }

    public function confFileForPhpcpdProvider()
    {
        return [
            'Phpcpd no tiene configuración' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcpd'],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'prueba.php']
            ],
            'Phpcpd tiene configuración pero no hay directorios ignorados' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcpd'],
                    'phpcpd' => [
                        'min-lines' => 10,
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'prueba.php']
            ],
            'Phpcpd tiene configuración con directorios ignorados pero algún fichero modificado no pertenece a esos directorios' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcpd'],
                    'phpcpd' => [
                        'exclude' => ['app'],
                        'min-lines' => 10,
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'app/prueba.php']
            ],
            'Phpcpd tiene el tag exclude en la configuración pero está vacío' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcpd'],
                    'phpcpd' => [
                        'exclude' => [],
                        'min-lines' => 10,
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'app/prueba.php']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider confFileForPhpcpdProvider
     */
    function it_run_phpcpd($configurationFile, $modifiedFiles)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf(CopyPasteDetector::class, $loadedTools['phpcpd']);
    }

    public function confFileForSkipPhpcpdProvider()
    {
        return [
            'Todos los ficheros modificados pertenecen al mismo directorio ignorado' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcpd'],
                    'phpcpd' => [
                        'exclude' => ['app'],
                    ],
                ],
                'Ficheros Modificados' => ['app/file1.php', 'app/file2.php']
            ],
            'Cada fichero pertenece a un directorio ignorado distinto' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpcpd'],
                    'phpcpd' => [
                        'exclude' => ['app', 'src'],
                    ],
                ],
                'Ficheros Modificados' => ['app/file1.php', 'src/file2.php']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider confFileForSkipPhpcpdProvider
     */
    function it_skip_phpcpd_when_all_modified_files_are_in_ignore_paths($configurationFile, $modifiedFiles)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories($modifiedFiles);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(0, $loadedTools);
    }

    /** @test */
    function it_run_DependencyVulnerabilities()
    {
        $configurationFile = [
            'Tools' => ['check-security'],
        ];

        $smartStrategy = new SmartStrategy($configurationFile, new FileUtils(), new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf(CheckSecurity::class, $loadedTools['check-security']);
    }


    public function confFileForPhpmdProvider()
    {
        return [
            'Phpmd no tiene configuración' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpmd'],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'prueba.php']
            ],
            'Phpmd tiene configuración pero no hay directorios ignorados' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpmd'],
                    'phpmd' => [
                        'rules' => './qa/md-rulesheet.xml',
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'prueba.php']
            ],
            'Phpmd tiene configuración con directorios ignorados pero algún fichero modificado no pertenece a esos directorios' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpmd'],
                    'phpmd' => [
                        'rules' => './qa/md-rulesheet.xml',
                        'exclude' => ['app'],
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'app/prueba.php']
            ],
            'Phpmd tiene el tag exclude en la configuración pero está vacío' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpmd'],
                    'phpmd' => [
                        'rules' => './qa/md-rulesheet.xml',
                        'exclude' => ['app'],
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'app/prueba.php']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider confFileForPhpmdProvider
     */
    function it_run_phpmd($configurationFile, $modifiedFiles)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf(MessDetector::class, $loadedTools['phpmd']);
    }

    public function confFileForSkipPhpmdProvider()
    {
        return [
            'Todos los ficheros modificados pertenecen al mismo directorio ignorado' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpmd'],
                    'phpmd' => [
                        'rules' => './qa/md-rulesheet.xml',
                        'exclude' => ['app'],
                    ],
                ],
                'Ficheros Modificados' => ['app/file1.php', 'app/file2.php']
            ],
            'Cada fichero pertenece a un directorio ignorado distinto' => [
                'Fichero de configuración' => [
                    'Tools' => ['phpmd'],
                    'phpmd' => [
                        'rules' => './qa/md-rulesheet.xml',
                        'exclude' => ['app', 'src'],
                    ],
                ],
                'Ficheros Modificados' => ['app/file1.php', 'src/file2.php']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider confFileForSkipPhpmdProvider
     */
    function it_skip_phpmd_when_all_modified_files_are_in_ignore_paths($configurationFile, $modifiedFiles)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories($modifiedFiles);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(0, $loadedTools);
    }

    public function confFileForParallelLintProvider()
    {
        return [
            'ParallelLint no tiene configuración' => [
                'Fichero de configuración' => [
                    'Tools' => ['parallel-lint'],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'prueba.php']
            ],
            'ParallelLint tiene configuración pero no hay directorios ignorados' => [
                'Fichero de configuración' => [
                    'Tools' => ['parallel-lint'],
                    'parallel-lint' => [
                        'algunaConfig' => 'hola',
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'prueba.php']
            ],
            'ParallelLint tiene configuración con directorios ignorados pero algún fichero modificado no pertenece a esos directorios' => [
                'Fichero de configuración' => [
                    'Tools' => ['parallel-lint'],
                    'parallel-lint' => [
                        'exclude' => ['app'],
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'app/prueba.php']
            ],
            'ParallelLint tiene el tag exclude en la configuración pero está vacío' => [
                'Fichero de configuración' => [
                    'Tools' => ['parallel-lint'],
                    'parallel-lint' => [
                        'exclude' => ['app'],
                    ],
                ],
                'Ficheros Modificados' => ['config/conf.php', 'app/prueba.php']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider confFileForParallelLintProvider
     */
    function it_run_parallellLint($configurationFile, $modifiedFiles)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(1, $loadedTools);

        $this->assertInstanceOf(ParallelLint::class, $loadedTools['parallel-lint']);
    }

    public function confFileForSkipParallelLintProvider()
    {
        return [
            'Todos los ficheros modificados pertenecen al mismo directorio ignorado' => [
                'Fichero de configuración' => [
                    'Tools' => ['parallel-lint'],
                    'parallel-lint' => [
                        'exclude' => ['app'],
                    ],
                ],
                'Ficheros Modificados' => ['app/file1.php', 'app/file2.php']
            ],
            'Cada fichero pertenece a un directorio ignorado distinto' => [
                'Fichero de configuración' => [
                    'Tools' => ['parallel-lint'],
                    'parallel-lint' => [
                        'exclude' => ['app', 'src'],
                    ],
                ],
                'Ficheros Modificados' => ['app/file1.php', 'src/file2.php']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider confFileForSkipParallelLintProvider
     */
    function it_skip_parallellLint_when_all_modified_files_are_in_ignore_paths($configurationFile, $modifiedFiles)
    {
        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories($modifiedFiles);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(0, $loadedTools);
    }


    //TODO Las condiciones han cambiado (añadido paths... y security-check ahora debe ejecutarse siempre)
    /**
     * @test
     * Todas las herramientas, salvo PhpStan que se ejecutará siempre, pueden ejecutarse o excluirse.
     * Salen 32 combinaciones pero con el algoritmo de todos los pares reducimos los casos de prueba a tan sólo 4.
     * 1/4 Solo se ejecuta phpstan.
     */
    function it_run_only_phpstan()
    {
        $configurationFile = [
            'Tools' => [
                'phpstan',
                'check-security',
                'parallel-lint',
                'phpcs',
                'phpmd',
                'phpcpd',
            ],
            'parallel-lint' => [
                'exclude' => ['app', 'src'],
            ],
            'phpcs' => [
                'ignore' => ['app', 'src'],
            ],
            'phpmd' => [
                'exclude' => ['app', 'src'],
            ],
            'phpcpd' => [
                'exclude' => ['app', 'src'],
            ],
        ];

        $modifiedFiles = ['app/conf.php', 'src/prueba.php'];

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['app/conf.php', 'src/prueba.php']);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(2, $loadedTools);

        $this->assertArrayHasKey('phpstan', $loadedTools);

        $this->assertArrayHasKey('check-security', $loadedTools);
    }

    /**
     * @test
     * 2/4 Phpcs se salta, el resto se ejecutan
     */
    function it_skip_phpcs_and_run_all_the_others()
    {
        $configurationFile = [
            'Tools' => [
                'phpstan',
                'check-security',
                'parallel-lint',
                'phpcs',
                'phpmd',
                'phpcpd',
            ],
            'phpcs' => [
                'ignore' => ['app', 'src'],
            ],
        ];

        $modifiedFiles = ['app/conf.php', 'src/prueba.php'];

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['app/conf.php', 'src/prueba.php']);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(5, $loadedTools);

        $this->assertArrayNotHasKey('phpcs', $loadedTools);
    }

    /**
     * @test
     * 3/4 Se saltan check-security y Parallel-Lint, el resto se ejecutan
     */
    function it_skip_parallellLint_and_run_all_the_others()
    {
        $configurationFile = [
            'Tools' => [
                'phpstan',
                'check-security',
                'parallel-lint',
                'phpcs',
                'phpmd',
                'phpcpd',
            ],
            'parallel-lint' => [
                'exclude' => ['app', 'src'],
            ],
        ];

        $modifiedFiles = ['app/conf.php', 'src/prueba.php'];

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['app/conf.php', 'src/prueba.php']);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(5, $loadedTools);

        $this->assertArrayNotHasKey('parallel-lint', $loadedTools);
    }

    /**
     * @test
     * 4/4 Se saltan phpcpd y phpmd, el resto se ejecutan
     */
    function it_skip_phpcpd_and_phpmd_and_run_all_the_others()
    {
        $configurationFile = [
            'Tools' => [
                'phpstan',
                'check-security',
                'parallel-lint',
                'phpcs',
                'phpmd',
                'phpcpd',
            ],
            'phpmd' => [
                'exclude' => ['app', 'src'],
            ],
            'phpcpd' => [
                'exclude' => ['app', 'src'],
            ],
        ];

        $modifiedFiles = ['app/conf.php', 'src/prueba.php'];

        $gitFiles = new FileUtilsFake();
        $gitFiles->setModifiedfiles($modifiedFiles);
        $gitFiles->setFilesThatShouldBeFoundInDirectories(['app/conf.php', 'src/prueba.php']);

        $smartStrategy = new SmartStrategy($configurationFile, $gitFiles, new ToolsFactoy());

        $loadedTools = $smartStrategy->getTools();

        $this->assertCount(4, $loadedTools);

        $this->assertArrayNotHasKey('phpmd', $loadedTools);

        $this->assertArrayNotHasKey('phpcpd', $loadedTools);
    }
}
