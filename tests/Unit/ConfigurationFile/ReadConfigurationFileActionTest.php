<?php

declare(strict_types=1);

namespace Tests\Unit\ConfigurationFile;

use Faker\Factory;
use Tests\Utils\ConfigurationFileBuilder;
use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\ConfigurationFile\CliArguments;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\ConfigurationFile\Exception\WrongOptionsFormatException;
use Wtyd\GitHooks\ConfigurationFile\FileReaderFake;
use Wtyd\GitHooks\ConfigurationFile\ReadConfigurationFileAction;

/**
 * Pairwise algorithm https://pairwise.teremokgames.com/2axq4/
 */
class ReadConfigurationFileActionTest extends UnitTestCase
{
    /** @var \Tests\Utils\ConfigurationFileBuilder */
    private $configurationFileBuilder;

    /** @var \Faker\Generator */
    private $faker;

    protected function setUp(): void
    {
        $this->configurationFileBuilder = new ConfigurationFileBuilder('');
        $this->faker = Factory::create();
    }

    public function toolIsDifferentFromAllDataProvider()
    {
        $faker = Factory::create();
        $tool = $faker->randomElement(['phpcs', 'phpcbf', 'security-checker', 'phpmd', 'phpstan', 'phpcpd', 'parallel-lint']);
        $processes = $faker->numberBetween(1, 100); // 0 empty
        return [
            'Case 4' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => 'fast',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '',
                    'paths' => '',
                ],
                'Expected Options' => [
                    'execution' => 'fast',
                    'processes' => $processes,
                ],
                'Expected tool configuration' => [
                    'otherArguments' => '--argument or flag',
                ]
            ],
            'Case 5' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => '',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => true,
                    'otherArguments' => '',
                    'executablePath' => '',
                    'paths' => '/otherPath,/andOtherPath',
                ],
                'Expected Options' => [],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => true,
                    'paths' => ['/otherPath', '/andOtherPath'],
                ]
            ],
            'Case 6' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => 'full',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => false,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '/executablePath',
                    'paths' => '',
                ],
                'Expected Options' => ['execution' => 'full'],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => false,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '/executablePath',
                ]
            ],
            'Case 7' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => 'full',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '',
                    'executablePath' => '',
                    'paths' => '/otherPath',
                ],
                'Expected Options' => [
                    'execution' => 'full',
                    'processes' => $processes,
                ],
                'Expected tool configuration' => [
                    'paths' => ['/otherPath'],
                ]
            ],
            'Case 8' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => 'fast',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => true,
                    'otherArguments' => '',
                    'executablePath' => '/executablePath',
                    'paths' => '',
                ],
                'Expected Options' => ['execution' => 'fast'],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => true,
                    'executablePath' => '/executablePath',
                ]
            ],
            'Case 9' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => '',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => false,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '',
                    'paths' => '',
                ],
                'Expected Options' => [],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => false,
                    'otherArguments' => '--argument or flag',
                ]
            ],
            'Case 16' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => '',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '',
                    'paths' => '',
                ],
                'Expected Options' => [],
                'Expected tool configuration' => [
                    'otherArguments' => '--argument or flag',
                ]
            ],
            'Case 17' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => 'full',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => true,
                    'otherArguments' => '',
                    'executablePath' => '',
                    'paths' => '/otherPath,/andOtherPath',
                ],
                'Expected Options' => [
                    'execution' => 'full',
                    'processes' => $processes,
                ],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => true,
                    'paths' => ['/otherPath', '/andOtherPath'],
                ]
            ],
            'Case 18' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => 'fast',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '',
                    'executablePath' => '',
                    'paths' => '',
                ],
                'Expected Options' => [
                    'execution' => 'fast',
                    'processes' => $processes,
                ],
                'Expected tool configuration' => []
            ],
            'Case 19' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => '',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '/executablePath',
                    'paths' => '/onePath',
                ],
                'Expected Options' => ['processes' => $processes],
                'Expected tool configuration' => [
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '/executablePath',
                    'paths' => ['/onePath'],
                ]
            ],
            'Case 20' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => 'full',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => true,
                    'otherArguments' => '',
                    'executablePath' => '',
                    'paths' => '',
                ],
                'Expected Options' => ['execution' => 'full'],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => true,
                ]
            ],
            'Case 21' => [
                'CLI Arguments Parameters' => [
                    'tool' => $tool,
                    'execution' => 'fast',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => false,
                    'otherArguments' => '',
                    'executablePath' => '/executablePath.exe',
                    'paths' => '/myPath',
                ],
                'Expected Options' => ['execution' => 'fast'],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => false,
                    'executablePath' => '/executablePath.exe',
                    'paths' => ['/myPath'],
                ]
            ],
        ];
    }

    /**
     * @test
     * @dataProvider toolIsDifferentFromAllDataProvider
     */
    function it_overrides_configuration_when_the_tool_is_different_from_all(
        $cliArgumentsParameters,
        $expectedOptions,
        $expectedToolConfiguration
    ) {
        $fileReaderFake = new FileReaderFake();
        $originalConfigurationFile = $this->configurationFileBuilder->setOptions([
            'execution' => $this->faker->randomElement(['full', 'fast']),
            'processes' => $this->faker->numberBetween(1, 100),
        ])->buildArray();
        $fileReaderFake->mockConfigurationFile(
            $originalConfigurationFile
        );

        $action = new ReadConfigurationFileAction($fileReaderFake);

        // merge $cliArgumentsParameters with 'config' = ''
        $cliArgumentsParameters = array_merge($cliArgumentsParameters, ['config' => '']);
        $cliArguments = new CliArguments(
            $cliArgumentsParameters['tool'],
            $cliArgumentsParameters['execution'],
            $cliArgumentsParameters['ignoreErrorsOnExit'],
            $cliArgumentsParameters['otherArguments'],
            $cliArgumentsParameters['executablePath'],
            $cliArgumentsParameters['paths'],
            $cliArgumentsParameters['processes'],
            $cliArgumentsParameters['config']
        );

        $configurationFile = $action($cliArguments);

        $originalConfigurationFile['Options'] = array_replace($originalConfigurationFile['Options'], $expectedOptions);
        $originalConfigurationFile[$cliArguments->getTool()] = array_replace($originalConfigurationFile[$cliArguments->getTool()], $expectedToolConfiguration);
        $expectedConfigurationFile = new ConfigurationFile($originalConfigurationFile, $cliArguments->getTool());

        $this->assertEquals($expectedConfigurationFile, $configurationFile);
    }

    public function toolIsAllDataProvider()
    {
        $faker = Factory::create();
        $tool = $faker->randomElement(['phpcs', 'phpcbf', 'security-checker', 'phpmd', 'phpstan', 'phpcpd', 'parallel-lint']);
        $processes = $faker->numberBetween(2, 100); // 0 is empty, 1 default
        return [
            'Case 1' => [
                'CLI Arguments Parameters' => [
                    'execution' => '',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => true,
                    'otherArguments' => '',
                    'executablePath' => '/executablePath',
                    'paths' => '/onePath',
                ],
                'Expected Options' => ['processes' => $processes,],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => true,
                ]
            ],
            'Case 2' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'fast',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '/executablePath',
                    'paths' => '/onePath',
                ],
                'Expected Options' => ['execution' => 'fast',],
                'Expected tool configuration' => []
            ],
            'Case 3' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'full',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => true,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '/executablePath',
                    'paths' => '',
                ],
                'Expected Options' => ['execution' => 'full',],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => true,
                ]
            ],
            'Case 10' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'fast',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => true,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '',
                    'paths' => '',
                ],
                'Expected Options' => [
                    'execution' => 'fast',
                    'processes' => $processes,
                ],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => true,
                ]
            ],
            'Case 11' => [
                'CLI Arguments Parameters' => [
                    'execution' => '',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => false,
                    'otherArguments' => '',
                    'executablePath' => '/executablePath',
                    'paths' => '',
                ],
                'Expected Options' => [],
                'Expected tool configuration' => [
                    'ignoreErrorsOnExit' => false,
                ]
            ],
            'Case 12' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'full',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '',
                    'executablePath' => '',
                    'paths' => '/myPath',
                ],
                'Expected Options' => [
                    'execution' => 'full',
                    'processes' => $processes,
                ],
                'Expected tool configuration' => []
            ],
            'Case 13' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'fast',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => false,
                    'otherArguments' => '',
                    'executablePath' => '',
                    'paths' => '/myPath',
                ],
                'Expected Options' => ['execution' => 'fast',],
                'Expected tool configuration' => ['ignoreErrorsOnExit' => false,]
            ],
            'Case 14' => [
                'CLI Arguments Parameters' => [
                    'execution' => '',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '',
                    'paths' => '',
                ],
                'Expected Options' => ['processes' => $processes,],
                'Expected tool configuration' => []
            ],
            'Case 15' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'full',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '',
                    'executablePath' => '/executablePath',
                    'paths' => '/path, /otherPath',
                ],
                'Expected Options' => ['execution' => 'full',],
                'Expected tool configuration' => []
            ],
            'Case 22' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'full',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => false,
                    'otherArguments' => '',
                    'executablePath' => '/executablePath',
                    'paths' => '',
                ],
                'Expected Options' => [
                    'execution' => 'full',
                    'processes' => $processes,
                ],
                'Expected tool configuration' => []
            ],
            'Case 23' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'full',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '',
                    'paths' => '/paths',
                ],
                'Expected Options' => ['execution' => 'full',],
                'Expected tool configuration' => []
            ],
            'Case 24' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'fast',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '',
                    'executablePath' => '/executablePath',
                    'paths' => '',
                ],
                'Expected Options' => [
                    'execution' => 'fast',
                    'processes' => $processes,
                ],
                'Expected tool configuration' => []
            ],
            'Case 25' => [
                'CLI Arguments Parameters' => [
                    'execution' => '',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => true,
                    'otherArguments' => '',
                    'executablePath' => '',
                    'paths' => '/paths',
                ],
                'Expected Options' => [],
                'Expected tool configuration' => ['ignoreErrorsOnExit' => true,]
            ],
            'Case 26' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'full',
                    'processes' => $processes,
                    'ignoreErrorsOnExit' => false,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '',
                    'paths' => '',
                ],
                'Expected Options' => [
                    'execution' => 'full',
                    'processes' => $processes,
                ],
                'Expected tool configuration' => ['ignoreErrorsOnExit' => false,]
            ],
            'Case 27' => [
                'CLI Arguments Parameters' => [
                    'execution' => 'fast',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => null,
                    'otherArguments' => '',
                    'executablePath' => '/executablePath',
                    'paths' => '',
                ],
                'Expected Options' => ['execution' => 'fast',],
                'Expected tool configuration' => []
            ],
            'Case 28' => [
                'CLI Arguments Parameters' => [
                    'execution' => '',
                    'processes' => 0,
                    'ignoreErrorsOnExit' => true,
                    'otherArguments' => '--argument or flag',
                    'executablePath' => '',
                    'paths' => '/paths',
                ],
                'Expected Options' => [],
                'Expected tool configuration' => ['ignoreErrorsOnExit' => true,]
            ],
        ];
    }

    /**
     * @test
     * @dataProvider toolIsAllDataProvider
     */
    function it_only_overrides_execution_processes_and_ignoreErrorsOnExit_when_the_tool_is_all(
        $cliArgumentsParameters,
        $expectedOptions,
        $expectedToolConfiguration
    ) {
        $fileReaderFake = new FileReaderFake();
        $originalConfigurationFile = $this->configurationFileBuilder->setOptions([
            'execution' => $this->faker->randomElement(['full', 'fast']),
            'processes' => $this->faker->numberBetween(1, 100),
        ])->buildArray();
        $fileReaderFake->mockConfigurationFile(
            $originalConfigurationFile
        );

        $action = new ReadConfigurationFileAction($fileReaderFake);

        $cliArguments = new CliArguments(
            'all',
            $cliArgumentsParameters['execution'],
            $cliArgumentsParameters['ignoreErrorsOnExit'],
            $cliArgumentsParameters['otherArguments'],
            $cliArgumentsParameters['executablePath'],
            $cliArgumentsParameters['paths'],
            $cliArgumentsParameters['processes']
        );

        $configurationFile = $action($cliArguments);

        $originalConfigurationFile['Options'] = array_replace($originalConfigurationFile['Options'], $expectedOptions);
        $expectedConfFileArray = $this->overrideIgnoreErrorsOnExitInAllTools(
            $originalConfigurationFile,
            $expectedToolConfiguration
        );
        $expectedConfigurationFile = new ConfigurationFile($expectedConfFileArray, 'all');

        $this->assertEquals($expectedConfigurationFile, $configurationFile);
    }

    protected function overrideIgnoreErrorsOnExitInAllTools(
        array $originalConfigurationFile,
        array $expectedToolConfiguration
    ): array {
        $tools = ['phpcs', 'phpcbf', 'security-checker', 'phpmd', 'phpstan', 'phpcpd', 'parallel-lint'];
        foreach ($tools as $tool) {
            $originalConfigurationFile[$tool] = array_replace($originalConfigurationFile[$tool], $expectedToolConfiguration);
        }
        return $originalConfigurationFile;
    }

    /** @test */
    function raise_exception_when_tool_is_not_valid()
    {
        $fileReaderFake = new FileReaderFake();
        $originalConfigurationFile = $this->configurationFileBuilder->buildArray();
        $fileReaderFake->mockConfigurationFile(
            $originalConfigurationFile
        );

        $action = new ReadConfigurationFileAction($fileReaderFake);

        $invalidTool = 'invalid tool';
        $cliArguments = new CliArguments(
            $invalidTool,
            'not matters',
            'not matters',
            'not matters',
            'not matters',
            'not matters',
            0
        );

        $this->expectException(ToolIsNotSupportedException::class);
        $this->expectExceptionMessage("The tool $invalidTool is not supported by GiHooks. Tools: phpcs, phpcbf, security-checker, parallel-lint, phpmd, phpcpd, phpstan");

        $action($cliArguments);
    }

    /** @test */
    function raise_exception_when_execution_is_not_valid()
    {
        $fileReaderFake = new FileReaderFake();
        $originalConfigurationFile = $this->configurationFileBuilder->buildArray();
        $fileReaderFake->mockConfigurationFile(
            $originalConfigurationFile
        );

        $action = new ReadConfigurationFileAction($fileReaderFake);

        $cliArguments = new CliArguments(
            'all',
            'invalid execution',
            'not matters',
            'not matters',
            'not matters',
            'not matters',
            0
        );

        $this->expectException(ConfigurationFileException::class);

        $action($cliArguments);
    }


    public function mixArrayDataProvider()
    {
        return [
            'Case 1' => [
                'Original ConfigurationFile' => [['execution' => 'fast'], ['processes' => 4]],
                'Expected ConfigurationFile' => ['execution' => '', 'processes' => 2,]
            ],
            'Case 2' => [
                'Original ConfigurationFile' => ['execution' => 'fast', ['processes' => 4]],
                'Expected ConfigurationFile' => ['execution' => 'full', 'processes' => 2,]
            ],
            'Case 3' => [
                'Original ConfigurationFile' => ['execution' => '', ['processes' => 4]],
                'Expected ConfigurationFile' => ['execution' => '', 'processes' => 0,]
            ],
            'Case 4' => [
                'Original ConfigurationFile' => [['execution' => 'fast'],],
                'Expected ConfigurationFile' => ['execution' => '', 'processes' => 4,]
            ],
            'Case 5' => [
                'Original ConfigurationFile' => [['execution' => 'fast'], 'processes' => 0],
                'Expected ConfigurationFile' => ['execution' => 'full', 'processes' => 0,]
            ],
            'Case 6' => [
                'Original ConfigurationFile' => [['processes' => 4]],
                'Expected ConfigurationFile' => ['execution' => 'full', 'processes' => 0,]
            ],
            'Case 7' => [
                'Original ConfigurationFile' => [['execution' => 'fast'], 'processes' => 4],
                'Expected ConfigurationFile' => ['execution' => '', 'processes' => 0,]
            ],
            'Case 8' => [
                'Original ConfigurationFile' => [['processes' => 4]],
                'Expected ConfigurationFile' => ['execution' => '', 'processes' => 0,]
            ],
        ];
    }

    /**
     * @test
     * @dataProvider mixArrayDataProvider
     */
    function it_raise_exception_when_Options_array_is_not_associative_even_though_some_values_have_been_overwritten_correctly(
        $originalConfigurationFile,
        $expectedOptions
    ) {
        $fileReaderFake = new FileReaderFake();
        $originalConfigurationFile = $this->configurationFileBuilder
            ->setOptions($originalConfigurationFile)
            ->buildArray(true);

        $fileReaderFake->mockConfigurationFile(
            $originalConfigurationFile
        );

        $action = new ReadConfigurationFileAction($fileReaderFake);

        $cliArguments = new CliArguments(
            'all',
            $expectedOptions['execution'],
            null,
            '',
            '',
            '',
            $expectedOptions['processes']
        );

        $this->expectException(WrongOptionsFormatException::class);
        $this->expectExceptionMessage('The Options label has an invalid format. It must be an associative array with pair of key: value.');

        $action($cliArguments);
    }
}
