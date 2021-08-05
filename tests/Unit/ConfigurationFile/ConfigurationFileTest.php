<?php

declare(strict_types=1);

namespace Tests\Unit\ConfigurationFile;

use Tests\UnitTestCase;
use Tests\Utils\ConfigurationFileBuilder;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileException;
use Wtyd\GitHooks\ConfigurationFile\Exception\ToolIsNotSupportedException;
use Wtyd\GitHooks\ConfigurationFile\Exception\WrongExecutionValueException;

class ConfigurationFileTest extends UnitTestCase
{
    protected function setUp(): void
    {
        $this->configurationFileBuilder = new ConfigurationFileBuilder('');
    }

    /** @test */
    function it_creates_a_configuration_file_with_valid_format_for_all_supported_tools()
    {
        $configurationFileArray = $this->configurationFileBuilder->buildArray();
        $this->configurationFile = new ConfigurationFile($configurationFileArray, 'all');

        $this->assertEquals('full', $this->configurationFile->getExecution());
        $this->assertFalse($this->configurationFile->hasErrors());
        $this->assertEmpty($this->configurationFile->getWarnings());
        $this->assertEquals($configurationFileArray['phpcs'], $this->configurationFile->getToolsConfiguration()['phpcs']->getToolConfiguration());
        $this->assertEquals($configurationFileArray['phpmd'], $this->configurationFile->getToolsConfiguration()['phpmd']->getToolConfiguration());
        $this->assertEquals($configurationFileArray['phpcpd'], $this->configurationFile->getToolsConfiguration()['phpcpd']->getToolConfiguration());
        $this->assertEquals($configurationFileArray['parallel-lint'], $this->configurationFile->getToolsConfiguration()['parallel-lint']->getToolConfiguration());
        $this->assertEquals($configurationFileArray['phpstan'], $this->configurationFile->getToolsConfiguration()['phpstan']->getToolConfiguration());
        $this->assertArrayHasKey('check-security', $this->configurationFile->getToolsConfiguration());
    }

    public function suportedToolsDataProvider()
    {
        return [
            'phpcs' => ['phpcs'],
            'phpmd' => ['phpmd'],
            'phpcpd' => ['phpcpd'],
            'parallel-lint' => ['parallel-lint'],
            'phpstan' => ['phpstan'],
            'check-security' => ['check-security'],
        ];
    }

    /**
     * @test
     * @dataProvider suportedToolsDataProvider
     */
    function it_creates_a_configuration_file_for_supported_tool($tool)
    {
        $this->configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), $tool);

        $this->assertEquals('full', $this->configurationFile->getExecution());
        $this->assertFalse($this->configurationFile->hasErrors());
        $this->assertEmpty($this->configurationFile->getWarnings());
        $this->assertArrayHasKey($tool, $this->configurationFile->getToolsConfiguration());
        $this->assertCount(1, $this->configurationFile->getToolsConfiguration());
    }

    /** @test */
    function it_raise_exception_when_the_tool_is_not_supported()
    {
        $this->expectException(ToolIsNotSupportedException::class);
        $this->expectExceptionMessage(
            'The tool tool-not-supported is not supported by GiHooks. Tools: phpcs, check-security, parallel-lint, phpmd, phpcpd, phpstan'
        );
        $this->configurationFile = new ConfigurationFile($this->configurationFileBuilder->buildArray(), 'tool-not-supported');
    }

    public function toolsThatNeedConfigurationDataProvider()
    {
        return [
            'phpcs' => ['phpcs'],
            'phpmd' => ['phpmd'],
            'phpcpd' => ['phpcpd'],
            'parallel-lint' => ['parallel-lint'],
            'phpstan' => ['phpstan'],
        ];
    }

    /**
     * @test
     * @dataProvider toolsThatNeedConfigurationDataProvider
     */
    function it_raises_exception_when_the_tool_is_supported_but_has_not_configuration($tool)
    {
        try {
            $this->configurationFile = new ConfigurationFile($this->configurationFileBuilder->setConfigurationTools([])->buildArray(), $tool);
            $this->fail('ConfigurationFileException was not thrown');
        } catch (ConfigurationFileException $exception) {
            $this->assertTrue($exception->getConfigurationFile()->hasErrors());
            $this->assertCount(1, $exception->getConfigurationFile()->getErrors());
            $this->assertEquals("The tag '$tool' is missing.", $exception->getConfigurationFile()->getErrors()[0]);
        }
    }

    /** @test */
    function it_raises_exception_when_is_not_Tools_tag_in_configuration_file()
    {
        $configurationFile =  [
            'Options' => [
                'execution' => 'full',
            ],
        ];
        try {
            $this->configurationFile = new ConfigurationFile($configurationFile, 'all');
            $this->fail('ConfigurationFileException was not thrown');
        } catch (ConfigurationFileException $exception) {
            $this->assertTrue($exception->getConfigurationFile()->hasErrors());
            $this->assertCount(1, $exception->getConfigurationFile()->getErrors());
            $this->assertEquals("There is no 'Tools' tag in the configuration file.", $exception->getConfigurationFile()->getErrors()[0]);
        }
    }

    function tagToolsIsEmptyDataProvider()
    {
        return [
            'The Tool key is null' => [
                [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => null
                ],
            ],
            'The Tool key is empty' => [
                [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => []
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider tagToolsIsEmptyDataProvider
     */
    function it_raises_exception_when_Tools_tag_is_empty($configurationFile)
    {
        try {
            $this->configurationFile = new ConfigurationFile($configurationFile, 'all');
            $this->fail('ConfigurationFileException was not thrown');
        } catch (ConfigurationFileException $exception) {
            $this->assertTrue($exception->getConfigurationFile()->hasErrors());
            $this->assertCount(1, $exception->getConfigurationFile()->getErrors());
            $this->assertEquals("The 'Tools' tag from configuration file is empty.", $exception->getConfigurationFile()->getErrors()[0]);
        }
    }

    function tagToolsNotContainsAnySupportedToolDataProvider()
    {
        return [
            'The Tool key only have one no valid tool' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => [
                        'no-valid-tool'
                    ]
                ],
            ],
            'The Tool key only invalid tools' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => [
                        'no-valid-tool-1',
                        'no-valid-tool-2',
                    ]
                ],
            ],
        ];
    }
    /**
     * @test
     * @dataProvider tagToolsNotContainsAnySupportedToolDataProvider
     */
    function it_raises_exception_when_Tools_tag_not_contains_any_valid_tool($configurationFile)
    {
        try {
            $this->configurationFile = new ConfigurationFile($configurationFile, 'all');
            $this->fail('ConfigurationFileException was not thrown');
        } catch (ConfigurationFileException $exception) {
            $this->assertTrue($exception->getConfigurationFile()->hasErrors());
            $this->assertCount(1, $exception->getConfigurationFile()->getErrors());
            $this->assertEquals("There must be at least one tool configured.", $exception->getConfigurationFile()->getErrors()[0]);
        }
    }

    function tagOptionNoValidValuesDataProvider()
    {
        return [
            "If 'Options' exist, can't be empty" => [
                'Configuration File' => [
                    'Options' => '',
                    'Tools' => ['check-security']
                ],
            ],
            "If 'Options' exist, can't be null" => [
                'Configuration File' => [
                    'Options' => null,
                    'Tools' => ['check-security']
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider tagOptionNoValidValuesDataProvider
     */
    function it_sets_warning_when_Options_is_empty($configurationFile)
    {
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $this->assertCount(1, $this->configurationFile->getWarnings());
        $this->assertEquals("The tag 'Options' is empty", $this->configurationFile->getWarnings()[0]);
    }

    function tagExecutionWithNoValidValuesDataProvider()
    {
        return [
            'No valid string' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'no valid string'
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected Error' => ["The value 'no valid string' is not allowed for the tag 'execution'. Accept: full, fast"],
            ],
            'Integer' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 0
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected Error' => ["The value '0' is not allowed for the tag 'execution'. Accept: full, fast"],
            ],
            'Boolean True' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => true
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected Error' => ["The value 'true' is not allowed for the tag 'execution'. Accept: full, fast"],
            ],
            'Boolean False' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => false
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected Error' => ["The value 'false' is not allowed for the tag 'execution'. Accept: full, fast"],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider tagExecutionWithNoValidValuesDataProvider
     */
    function it_throws_exception_when_Options_have_valid_keys_with_invalid_values($configurationFile, $expectedError)
    {
        try {
            $this->configurationFile = new ConfigurationFile($configurationFile, 'all');
            $this->fail('ConfigurationFileException was not thrown');
        } catch (ConfigurationFileException $exception) {
            $this->assertTrue($exception->getConfigurationFile()->hasErrors());
            $this->assertCount(1, $exception->getConfigurationFile()->getErrors());
            $this->assertEquals($expectedError, $exception->getConfigurationFile()->getErrors());
        }
    }

    function tagOpitonsHaveInvalidKeysDataProvider()
    {
        return [
            'Only one invalid key' => [
                'Configuration File' => [
                    'Options' => [
                        'invalid-key' => 'ooops',
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected warnings' => [
                    "The key 'invalid-key' is not a valid option"
                ],
            ],
            'Only invalid keys' => [
                'Configuration File' => [
                    'Options' => [
                        'invalid-key' => 'ooops',
                        'invalid-key2' => 'ooops',
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected warnings' => [
                    "The key 'invalid-key' is not a valid option",
                    "The key 'invalid-key2' is not a valid option"
                ],
            ],
            'One invalid key and one valid key' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                        'invalid-key' => 10,
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected warnings' => [
                    "The key 'invalid-key' is not a valid option",
                ],
            ],
            'Many invalid keys and one valid key' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'fast',
                        'invalid-key' => 'ooops',
                        'invalid-key2' => 3.5555,
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected warnings' => [
                    "The key 'invalid-key' is not a valid option",
                    "The key 'invalid-key2' is not a valid option"
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider tagOpitonsHaveInvalidKeysDataProvider
     */
    function it_sets_warning_when_Options_have_at_least_a_valid_key_and_invalid_keys($configurationFile, $expectedWarnings)
    {
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $this->assertEquals($expectedWarnings, $this->configurationFile->getWarnings());
    }

    function tagExecutionHasWrongValues()
    {
        return [
            'Full to fast' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['check-security']
                ],
                'New execution' => 'fast',
            ],
            'Fast to full' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'fast',
                    ],
                    'Tools' => ['check-security']
                ],
                'New execution' => 'full',
            ],
            'Full to full' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['check-security']
                ],
                'New execution' => 'full',
            ],
            'Fast to fast' => [
                'Configuration File' =>  [
                    'Options' => [
                        'execution' => 'fast',
                    ],
                    'Tools' => ['check-security']
                ],
                'New execution' => 'fast',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider tagExecutionHasWrongValues
     */
    function it_can_change_Execution_tag($configurationFile, $newExecutionValue)
    {
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $this->configurationFile->setExecution($newExecutionValue);

        $this->assertEquals($newExecutionValue, $this->configurationFile->getExecution());
    }

    /** @test */
    function it_throws_exception_when_change_Execution_tag_with_wrong_values()
    {
        $configurationFile =  [
            'Options' => [
                'execution' => 'fast',
            ],
            'Tools' => ['check-security']
        ];
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $this->expectException(WrongExecutionValueException::class);
        $this->expectExceptionMessage("The value 'no valid string' is not allowed for the tag 'execution'. Accept: full, fast");

        $this->configurationFile->setExecution('no valid string');
    }

    // Warnings: Warnings[] = "The tool $tool is not supported by GitHooks.";
    // Warnings: $warnings[] = "$key argument is invalid for tool $this->tool. It will be ignored.";

    function tagToolsWithNotValidToolsDataProvider()
    {
        return [
            'Only one warning' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['check-security', 'no-supported-tool']
                ],
                'Expected Warnings' => ['The tool no-supported-tool is not supported by GitHooks.'],
            ],
            'Multiple warnings' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['check-security', 'no-supported-tool1', 'no-supported-tool2']
                ],
                'Expected Warnings' => [
                    'The tool no-supported-tool1 is not supported by GitHooks.',
                    'The tool no-supported-tool2 is not supported by GitHooks.',
                ],
            ],

        ];
    }

    /**
     * @test
     * @dataProvider tagToolsWithNotValidToolsDataProvider
     */
    function it_sets_warning_when_Tools_have_at_least_a_valid_key_and_invalid_keys($configurationFile, $expectedWarnings)
    {
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $this->assertEquals($expectedWarnings, $this->configurationFile->getWarnings());
    }
}
