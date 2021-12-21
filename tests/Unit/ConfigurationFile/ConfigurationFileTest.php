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
        $this->assertArrayHasKey('security-checker', $this->configurationFile->getToolsConfiguration());
    }

    public function suportedToolsDataProvider()
    {
        return [
            'phpcs' => ['phpcs'],
            'phpmd' => ['phpmd'],
            'phpcpd' => ['phpcpd'],
            'parallel-lint' => ['parallel-lint'],
            'phpstan' => ['phpstan'],
            'security-checker' => ['security-checker'],
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
            'The tool tool-not-supported is not supported by GiHooks. Tools: phpcs, phpcbf, security-checker, parallel-lint, phpmd, phpcpd, phpstan'
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
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
                ],
            ],
            "If 'Options' exist, can't be null" => [
                'Configuration File' => [
                    'Options' => null,
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
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
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
                ],
                'Expected Error' => ["The value 'no valid string' is not allowed for the tag 'execution'. Accept: full, fast"],
            ],
            'Integer' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 0
                    ],
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
                ],
                'Expected Error' => ["The value '0' is not allowed for the tag 'execution'. Accept: full, fast"],
            ],
            'Boolean True' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => true
                    ],
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
                ],
                'Expected Error' => ["The value 'true' is not allowed for the tag 'execution'. Accept: full, fast"],
            ],
            'Boolean False' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => false
                    ],
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
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
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
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
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
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
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
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
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
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
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
                ],
                'New execution' => 'fast',
            ],
            'Fast to full' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'fast',
                    ],
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
                ],
                'New execution' => 'full',
            ],
            'Full to full' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
                ],
                'New execution' => 'full',
            ],
            'Fast to fast' => [
                'Configuration File' =>  [
                    'Options' => [
                        'execution' => 'fast',
                    ],
                    'Tools' => ['security-checker'],
                    'security-checker' => ['executablePath' => 'mipath']
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
            'Tools' => ['security-checker'],
            'security-checker' => ['executablePath' => 'mipath']
        ];
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $this->expectException(WrongExecutionValueException::class);
        $this->expectExceptionMessage("The value 'no valid string' is not allowed for the tag 'execution'. Accept: full, fast");

        $this->configurationFile->setExecution('no valid string');
    }

    function tagToolsWithNotValidToolsDataProvider()
    {
        return [
            'Only one warning' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['security-checker', 'no-supported-tool'],
                    'security-checker' => ['executablePath' => 'mipath']
                ],
                'Expected Warnings' => ['The tool no-supported-tool is not supported by GitHooks.'],
            ],
            'Multiple warnings' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['security-checker', 'no-supported-tool1', 'no-supported-tool2'],
                    'security-checker' => ['executablePath' => 'mipath']
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



    /*
    |-------------------------------------------------------------------------------------------------------
    | Phpcbf can get configuration from Phpcs feature
    |-------------------------------------------------------------------------------------------------------
    |
    | Phpcbf has 'usePhpcsConfiguration' key. When it is true, all arguments that are not explicitly defined
    | in the Phpcbf tag will be taken from Phpcs.
    | Variables defined in Phpcbf that are also in Phpcs will keep their value.
    |
    */

    function usePhpcsConfigurationDoesNotTrueDataProvider()
    {
        return [
            'It is false' => [false],
            'It is null' => [null],
            'It is null' => [''],
            'It is null' => [0],
        ];
    }

    /**
     * @test
     * @dataProvider usePhpcsConfigurationDoesNotTrueDataProvider
     */
    function it_keeps_Phpcbf_values_when_usePhpcsConfiguration_is_not_true($usePhpcsConfiguration)
    {
        $configurationFile = [
            'Tools' => ['phpcbf', 'phpcs'],
            'phpcs' => [
                'paths' => ['app'],
                'standard' => 'PSR12',
                'ignore' => ['tests'],
                'error-severity' => 2,
                'warning-severity' => 5
            ],
            'phpcbf' => [
                'paths' => ['src', 'tests'],
                'standard' => 'PERL',
                'ignore' => ['vendor'],
                'error-severity' => 1,
                'warning-severity' => 6
            ]
        ];
        $configurationFile['phpcbf']['usePhpcsConfiguration'] = $usePhpcsConfiguration;
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $phpcbfConfigurationFile = $this->configurationFile->getToolsConfiguration()['phpcbf'];
        $phpcsConfigurationFile = $this->configurationFile->getToolsConfiguration()['phpcs'];

        $this->assertNotEquals(
            $phpcsConfigurationFile->getToolConfiguration()['paths'],
            $phpcbfConfigurationFile->getToolConfiguration()['paths']
        );
        $this->assertNotEquals(
            $phpcsConfigurationFile->getToolConfiguration()['standard'],
            $phpcbfConfigurationFile->getToolConfiguration()['standard']
        );
        $this->assertNotEquals(
            $phpcsConfigurationFile->getToolConfiguration()['ignore'],
            $phpcbfConfigurationFile->getToolConfiguration()['ignore']
        );
        $this->assertNotEquals(
            $phpcsConfigurationFile->getToolConfiguration()['error-severity'],
            $phpcbfConfigurationFile->getToolConfiguration()['error-severity']
        );
        $this->assertNotEquals(
            $phpcsConfigurationFile->getToolConfiguration()['warning-severity'],
            $phpcbfConfigurationFile->getToolConfiguration()['warning-severity']
        );
    }

    /** @test */
    function it_keeps_Phpcbf_values_when_usePhpcsConfiguration_not_exits()
    {
        $configurationFile = [
            'Tools' => ['phpcbf', 'phpcs'],
            'phpcs' => [
                'paths' => ['app'],
                'standard' => 'PSR12',
                'ignore' => ['tests'],
                'error-severity' => 2,
                'warning-severity' => 5
            ],
            'phpcbf' => [
                'paths' => ['src', 'tests'],
                'standard' => 'PERL',
                'ignore' => ['vendor'],
                'error-severity' => 1,
                'warning-severity' => 6
            ]
        ];
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $phpcbfConfigurationFile = $this->configurationFile->getToolsConfiguration()['phpcbf'];
        $phpcsConfigurationFile = $this->configurationFile->getToolsConfiguration()['phpcs'];

        $this->assertNotEquals(
            $phpcsConfigurationFile->getToolConfiguration(),
            $phpcbfConfigurationFile->getToolConfiguration()
        );
    }

    function overrideAllArgumentsOfPhpcbfConfigurationDataProvider()
    {
        return [
            'Overrides all arguments' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['phpcbf'],
                    'phpcbf' => ['usePhpcsConfiguration' => true],
                    'phpcs' => [
                        'paths' => ['src', 'tests'],
                        'standard' => 'PERL',
                        'ignore' => 'vendor',
                        'error-severity' => 2,
                        'warning-severity' => 5
                    ]
                ],
                'Expected phpcbf configuration' =>  [
                    'paths' => ['src', 'tests'],
                    'standard' => 'PERL',
                    'ignore' => 'vendor',
                    'error-severity' => 2,
                    'warning-severity' => 5,
                    'usePhpcsConfiguration' => true
                ]
            ],
        ];
    }

    /**
     * @test
     * @dataProvider overrideAllArgumentsOfPhpcbfConfigurationDataProvider
     */
    function it_overrides_all_Phpcbf_arguments_when_usePhpcsConfiguration_key_is_true_and_any_other_argument_is_setted($configurationFile, $expectedPhpcbfConfiguration)
    {
        $this->configurationFile = new ConfigurationFile($configurationFile, 'phpcbf');

        $configurationFile = $this->configurationFile->getToolsConfiguration()['phpcbf'];

        $this->assertEquals($expectedPhpcbfConfiguration, $configurationFile->getToolConfiguration());
    }

    function overridePhpcbfConfigurationDataProvider()
    {
        return [
            "Overrides 'executablePath' argument" => ['executablePath'],
            "Overrides 'paths' argument" => ['paths'],
            "Overrides 'standard' argument" => ['standard'],
            "Overrides 'ignore' argument" => ['ignore'],
            "Overrides 'error-severity' argument" => ['error-severity'],
            "Overrides 'warning-severity' argument" => ['warning-severity'],
        ];
    }

    /**
     * @test
     * @dataProvider overridePhpcbfConfigurationDataProvider
     */
    function it_overrides_just_one_argument_of_Phpcbf_configuration_when_usePhpcsConfiguration_key_is_true_and_the_argument_is_not_setted(
        $argument
    ) {
        $originalConfigurationFile = $configurationFile = [
            'Tools' => ['phpcbf', 'phpcs'],
            'phpcbf' => [
                'usePhpcsConfiguration' => true,
                'executablePath' => 'phpcbf',
                'paths' => ['app'],
                'standard' => 'PERL',
                'ignore' => ['vendor'],
                'error-severity' => 1,
                'warning-severity' => 6
            ],
            'phpcs' => [
                'executablePath' => 'phpcs',
                'paths' => ['src', 'tests'],
                'standard' => 'PSR12',
                'ignore' => ['vendor', 'tests'],
                'error-severity' => 2,
                'warning-severity' => 5
            ]
        ];
        unset($configurationFile['phpcbf'][$argument]);
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $phpcbfConfigurationFile = $this->configurationFile->getToolsConfiguration()['phpcbf'];
        $phpcsConfigurationFile = $this->configurationFile->getToolsConfiguration()['phpcs'];

        $this->assertEquals(
            $phpcsConfigurationFile->getToolConfiguration()[$argument],
            $phpcbfConfigurationFile->getToolConfiguration()[$argument]
        );
        $this->assertNotEquals(
            $originalConfigurationFile['phpcbf'][$argument],
            $phpcbfConfigurationFile->getToolConfiguration()[$argument]
        );
    }

    public function toolsThatArentInToolsKeyDataProvider()
    {
        return [
            'Tier 1' => [
                'Configuration File' => [
                    'Tools' => ['phpcbf', 'phpcs', 'phpmd'],
                    'phpcs' => [
                        'paths' => ['app'],
                        'standard' => 'PSR12',
                        'fake' => 'warning',
                    ],
                    'phpcbf' => [
                        'paths' => ['src', 'tests'],
                        'standard' => 'PERL',
                        'fake' => 'warning',
                    ],
                    'phpmd' => [
                        'paths' => ['src', 'tests'],
                        'rules' => 'controversial',
                        'fake' => 'warning',
                    ],
                    'parallel-lint' => [
                        'paths' => ['src', 'tests'],
                        'fake' => 'warning',
                    ],
                    'phpcpd' => [
                        'paths' => ['src', 'tests'],
                        'fake' => 'warning',
                    ],
                    'security-checker' => [
                        'executablePath' => 'local-php-security-checker',
                        'fake' => 'warning',
                    ],
                    'phpstan' => [
                        'paths' => ['src', 'tests'],
                        'config' => 'phpstan.neon',
                        'fake' => 'warning',
                    ],
                ],
            ],
            'Tier 2' => [
                'Configuration File' => [
                    'Tools' => ['parallel-lint', 'phpcpd', 'security-checker', 'phpstan'],
                    'phpcs' => [
                        'paths' => ['app'],
                        'standard' => 'PSR12',
                        'fake' => 'warning',
                    ],
                    'phpcbf' => [
                        'paths' => ['src', 'tests'],
                        'standard' => 'PERL',
                        'fake' => 'warning',
                    ],
                    'phpmd' => [
                        'paths' => ['src', 'tests'],
                        'rules' => 'controversial',
                        'fake' => 'warning',
                    ],
                    'parallel-lint' => [
                        'paths' => ['src', 'tests'],
                        'fake' => 'warning',
                    ],
                    'phpcpd' => [
                        'paths' => ['src', 'tests'],
                        'fake' => 'warning',
                    ],
                    'security-checker' => [
                        'executablePath' => 'local-php-security-checker',
                        'fake' => 'warning',
                    ],
                    'phpstan' => [
                        'paths' => ['src', 'tests'],
                        'config' => 'phpstan.neon',
                        'fake' => 'warning',
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider toolsThatArentInToolsKeyDataProvider
     */
    function it_checks_all_tools_even_if_they_are_not_in_the_Tools_key($configurationFile)
    {
        $this->configurationFile = new ConfigurationFile($configurationFile, 'all');

        $this->assertCount(7, $this->configurationFile->getWarnings());
    }


    function overridePhpcbfConfigurationToolsKeyDataProvider()
    {
        return [
            'Phpcbf is in Tools key and Phpcs not' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['phpcbf'],
                    'phpcbf' => ['usePhpcsConfiguration' => true],
                    'phpcs' => [
                        'paths' => ['src', 'tests'],
                        'standard' => 'PERL',
                        'ignore' => 'vendor',
                        'error-severity' => 2,
                        'warning-severity' => 5
                    ]
                ],
            ],
            'Phpcs is in Tools key and Phpcbf not' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['phpcs'],
                    'phpcbf' => ['usePhpcsConfiguration' => true],
                    'phpcs' => [
                        'paths' => ['src', 'tests'],
                        'standard' => 'PERL',
                        'ignore' => 'vendor',
                        'error-severity' => 2,
                        'warning-severity' => 5
                    ]
                ],
            ],
            'Any tool is in Tools key' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => ['phpmd'],
                    'phpcbf' => ['usePhpcsConfiguration' => true],
                    'phpcs' => [
                        'paths' => ['src', 'tests'],
                        'standard' => 'PERL',
                        'ignore' => 'vendor',
                        'error-severity' => 2,
                        'warning-severity' => 5
                    ],
                    'phpmd' => [
                        'paths' => ['src', 'tests'],
                        'rules' => 'controversial',
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider overridePhpcbfConfigurationToolsKeyDataProvider
     */
    function it_overrides_Phpcbf_configuration_when_usePhpcsConfiguration_key_is_true_regardless_of_whether_if_Phpcbf_or_Phpcs_are_or_not_in_Tools_key(
        $configurationFile
    ) {
        $this->configurationFile = new ConfigurationFile($configurationFile, 'phpcbf');

        $configurationFile = $this->configurationFile->getToolsConfiguration()['phpcbf'];

        $expectedPhpcbfConfiguration =  [
            'paths' => ['src', 'tests'],
            'standard' => 'PERL',
            'ignore' => 'vendor',
            'error-severity' => 2,
            'warning-severity' => 5,
            'usePhpcsConfiguration' => true
        ];
        $this->assertEquals($expectedPhpcbfConfiguration, $configurationFile->getToolConfiguration());
    }
}
