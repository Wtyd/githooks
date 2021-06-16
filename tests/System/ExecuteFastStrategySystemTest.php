<?php

namespace Tests\System;

use Wtyd\GitHooks\GitHooks;
use Tests\SystemTestCase;
use Tests\Utils\FileUtilsFake;
use Tests\Utils\CheckSecurityFake;
use Tests\Utils\PhpFileBuilder;

/**
 * The tests plan is explained in Tests Plain.md file
 */
class ExecuteFastStrategySystemTest extends SystemTestCase
{
    protected $configurationFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationFileBuilder->setOptions(['execution' => 'fast']);
    }

    /** @test */
    function fast_strategy_01()
    {
        $fileBuilder = new PhpFileBuilder('File');

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcs', 'phpmd', 'parallel-lint', 'phpstan', 'phpcpd']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });

        $githooks = $this->container->makeWith(GitHooks::class);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasFailed('phpcbf');
        $this->assertToolHasFailed('phpmd');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolHasFailed('phpstan');
        $this->assertToolHasFailed('parallel-lint');
    }

    /** @test */
    function fast_strategy_02()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setCopyPasteDetectorConfiguration([
            'paths' => [$this->getPath() . '/app'],
        ]);

        mkdir($this->getPath() . '/app');
        $fileBuilderForApp = new PhpFileBuilder('FileForCopyPasteDetector');
        file_put_contents($this->getPath() . '/app/FileForCopyPasteDetector.php', $fileBuilderForApp->build());

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->build());

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/app/File.php']);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        $githooks();

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolDidNotRun('phpcbf');
        $this->assertToolDidNotRun('phpmd');
        $this->assertToolDidNotRun('phpstan');
        $this->assertToolDidNotRun('parallel-lint');
        $this->assertMatchesRegularExpression('%Total run time = \d+\.\d{2} sec%', $this->getActualOutput());
        $this->assertStringContainsString('Your changes have been committed.', $this->getActualOutput());
    }

    /** @test */
    function fast_strategy_03()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpCSConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'ignore' => [$this->getPath() . '/src/File.php'],
        ]);
        $this->configurationFileBuilder->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);
        $this->configurationFileBuilder->setMessDetectorConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'rules' => 'unusedcode',
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcpd']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function fast_strategy_04()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpCSConfiguration([
            'paths' => [$this->getPath() . '/app'],
        ]);

        $this->configurationFileBuilder->setMessDetectorConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'rules' => 'unusedcode',
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcpd']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolDidNotRun('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function fast_strategy_05()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpCSConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'ignore' => [$this->getPath() . '/src/File.php'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpstan']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php']);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasFailed('phpstan');
    }

    /**
     * A error in Parallel-lint causes Mess Detector and Php Stan to fail. To avoid this, we make these tools run against other paths.
     * @test
     */
    function fast_strategy_06()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpStanConfiguration([
            'paths' => [$this->getPath() . '/other'],
        ]);

        $this->configurationFileBuilder->setMessDetectorConfiguration([
            'paths' => [$this->getPath() . '/app'],
            'rules' => 'unusedcode',
            'exclude' => ['vendor']
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcpd', 'parallel-lint']));

        mkdir($this->getPath() . '/app');
        $fileBuilderForApp = new PhpFileBuilder('FileForMessDetector');
        file_put_contents($this->getPath() . '/app/FileForMessDetector.php', $fileBuilderForApp->build());


        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([
                $this->getPath() . '/src/File.php',
                $this->getPath() . '/app/FileForMessDetector.php'
            ]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasFailed('parallel-lint');
        $this->assertToolDidNotRun('phpstan');
    }

    /** @test */
    function fast_strategy_07()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/app'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpmd']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasFailed('phpmd');
        $this->assertToolDidNotRun('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function fast_strategy_08()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setMessDetectorConfiguration([
            'paths' => [$this->getPath() . '/app'],
        ]);

        $this->configurationFileBuilder->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcs', 'phpstan']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasFailed('phpcbf');
        $this->assertToolDidNotRun('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasFailed('phpstan');
    }

    /** @test */
    function fast_strategy_09()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpmd']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasFailed('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function fast_strategy_10()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setMessDetectorConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'rules' => 'unusedcode',
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        $this->configurationFileBuilder->setPhpStanConfiguration([
            'paths' => [$this->getPath() . '/other'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcs', 'phpmd']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasFailed('phpcbf');
        //TODO phpmd no funciona correctamente. Esperando la respuesta del issue: https://github.com/phpmd/phpmd/issues/888
        // $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolDidNotRun('phpstan');
    }

    /**
     * A error in Parallel-lint causes Mess Detector and Php Stan to fail. To avoid this, we make these tools run against other paths.
     * @test
     */
    function fast_strategy_11()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpCSConfiguration([
            'paths' => [$this->getPath() . '/other'],
            'rules' => 'unusedcode',
        ]);

        $this->configurationFileBuilder->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/app'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->build());

        mkdir($this->getPath() . '/app');
        $fileBuilderForApp = new PhpFileBuilder('AppFile');
        file_put_contents($this->getPath() . '/app/AppFile.php', $fileBuilderForApp->buildWithErrors(['parallel-lint']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([
                $this->getPath() . '/src/File.php',
                $this->getPath() . '/app/AppFile.php',
            ]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolDidNotRun('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasFailed('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function fast_strategy_12()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpCSConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        $this->configurationFileBuilder->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/other'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcpd', 'phpstan']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolDidNotRun('parallel-lint');
        $this->assertToolHasFailed('phpstan');
    }

    /** @test */
    function fast_strategy_13()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setParallelLintConfiguration(['paths' => [$this->getPath() . '/app']]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcpd', 'phpcs']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolHasFailed('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolDidNotRun('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function fast_strategy_14()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpCSConfiguration(['paths' => [$this->getPath() . '/app']]);

        $this->configurationFileBuilder->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpstan']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolDidNotRun('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasFailed('phpstan');
    }

    /** @test */
    function fast_strategy_15()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpCSConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        $this->configurationFileBuilder->setPhpStanConfiguration(['paths' => [$this->getPath() . '/app']]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpmd']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasFailed('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolDidNotRun('phpstan');
    }

    /** @test */
    function fast_strategy_16()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setMessDetectorConfiguration(['paths' => [$this->getPath() . '/app']]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcpd']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolDidNotRun('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /**
     * A error in Parallel-lint causes Mess Detector and Php Stan to fail. To avoid this, we make these tools run against other paths.
     * @test
     */
    function fast_strategy_17()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setParallelLintConfiguration(['paths' => [$this->getPath() . '/app']]);

        $this->configurationFileBuilder->setMessDetectorConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'rules' => 'unusedcode',
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpstan']));

        mkdir($this->getPath() . '/app');
        $fileBuilderForApp = new PhpFileBuilder('AppFile');
        file_put_contents($this->getPath() . '/app/AppFile.php', $fileBuilderForApp->buildWithErrors(['parallel-lint']));


        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setKOExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([
                $this->getPath() . '/src/File.php',
                $this->getPath() . '/app/AppFile.php',
            ]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasFailed('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasFailed('parallel-lint');
        $this->assertToolHasFailed('phpstan');
    }

    /**
     * A error in Parallel-lint causes Mess Detector and Php Stan to fail. To avoid this, we make these tools run against other paths.
     * @test
     */
    function fast_strategy_18()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setMessDetectorConfiguration(['paths' => [$this->getPath() . '/other']]);

        $this->configurationFileBuilder->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        $this->configurationFileBuilder->setParallelLintConfiguration(['paths' => [$this->getPath() . '/app']]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->build());

        mkdir($this->getPath() . '/app');
        $fileBuilderForApp = new PhpFileBuilder('AppFile');
        file_put_contents($this->getPath() . '/app/AppFile.php', $fileBuilderForApp->buildWithErrors(['parallel-lint']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([
                $this->getPath() . '/src/File.php',
                $this->getPath() . '/app/AppFile.php',
            ]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolDidNotRun('phpmd');
        $this->assertToolHasFailed('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function fast_strategy_19()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setParallelLintConfiguration(['paths' => [$this->getPath() . '/app']]);

        $this->configurationFileBuilder->setMessDetectorConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'rules' => 'unusedcode',
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpstan']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolDidNotRun('parallel-lint');
        $this->assertToolHasFailed('phpstan');
    }

    /** @test */
    function fast_strategy_20()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpStanConfiguration(['paths' => [$this->getPath() . '/app']]);

        $this->configurationFileBuilder->setParallelLintConfiguration([
            'paths' => [$this->getPath() . '/src'],
            'exclude' => [$this->getPath() . '/src/File.php'],
        ]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcpd']));


        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolHasBeenExecutedSuccessfully('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolDidNotRun('phpstan');
    }

    /** @test */
    function fast_strategy_21()
    {
        $fileBuilder = new PhpFileBuilder('File');

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcs']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasBeenExecutedSuccessfully('phpcpd');
        $this->assertToolHasFailed('phpcbf');
        $this->assertToolHasBeenExecutedSuccessfully('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasBeenExecutedSuccessfully('phpstan');
    }

    /** @test */
    function fast_strategy_22()
    {
        $fileBuilder = new PhpFileBuilder('File');

        $this->configurationFileBuilder->setPhpCSConfiguration(['paths' => [$this->getPath() . '/app']]);

        file_put_contents($this->getPath() . '/githooks.yml', $this->configurationFileBuilder->buildYalm());

        file_put_contents($this->getPath() . '/src/File.php', $fileBuilder->buildWithErrors(['phpcpd', 'phpcs', 'phpmd', 'phpstan']));

        $this->container->resolving(CheckSecurityFake::class, function (CheckSecurityFake $checkSecurity) {
            return $checkSecurity->setOKExit();
        });

        $this->container->resolving(FileUtilsFake::class, function ($gitFiles) {
            $gitFiles->setModifiedfiles([$this->getPath() . '/src/File.php',]);
        });

        $githooks = $this->container->makeWith(GitHooks::class, ['configFile' => $this->getPath() . '/githooks.yml']);

        try {
            $githooks();
        } catch (\Throwable $th) {
            $this->assertSomeToolHasFailed($th, 'Your changes have not been committed. Please fix the errors and try again.');
        }

        $this->assertToolHasBeenExecutedSuccessfully('check-security');
        $this->assertToolHasFailed('phpcpd');
        $this->assertToolDidNotRun('phpcbf');
        $this->assertToolHasFailed('phpmd');
        $this->assertToolHasBeenExecutedSuccessfully('parallel-lint');
        $this->assertToolHasFailed('phpstan');
    }
}
