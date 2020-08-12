<?php

namespace Tests\Unit;

use GitHooks\ConfigurationErrors;
use GitHooks\ConfigurationFileValidator;
use PHPUnit\Framework\TestCase;

class ConfigurationFileValidatorTest extends TestCase
{
    protected $expectedErrors;

    public function SetUp(): void
    {
        $this->expectedErrors = new ConfigurationErrors();
    }

    /*
    |--------------------------------------------------------------------------
    | Options key tests
    |--------------------------------------------------------------------------
    | This tests checks only the Options key in the configuration file
    |
    */

    function TagOptionNoValidValuesDataProvider()
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
     * @dataProvider TagOptionNoValidValuesDataProvider
     */
    function it_set_warning_when_Options_is_empty($configurationFile)
    {
        $validator = new ConfigurationFileValidator();

        $errors = $validator($configurationFile);

        $this->expectedErrors->setOptionsWarnings(["The tag 'Options' is empty"]);

        $this->assertEquals($this->expectedErrors, $errors);
        $this->assertFalse($this->expectedErrors->hasErrors());
    }

    function OptionsValidDataProvider()
    {
        return [
            "'execution' is equal to 'full'" => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full'
                    ],
                    'Tools' => ['check-security']
                ],
            ],
            "'execution' is equal to 'fast'" => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'fast'
                    ],
                    'Tools' => ['check-security']
                ],
            ],
            "'execution' is equal to 'smart'" => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'smart'
                    ],
                    'Tools' => ['check-security']
                ]
            ],
        ];
    }

    /**
     * @test
     * @dataProvider OptionsValidDataProvider
     */
    function it_checks_valid_tags__with_valid_values_inside_Options($configurationFile)
    {
        $validator = new ConfigurationFileValidator();

        $errors = $validator($configurationFile);

        $this->assertEquals($this->expectedErrors, $errors);
        $this->assertFalse($errors->hasErrors());
        $this->assertFalse($errors->hasWarnings());
    }

    function ExecutionKeyWithNoValidValuesDataProvider()
    {
        return [
            'No valid string' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'no valid string'
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected errors' => [
                    "The value 'no valid string' is not allowed for the tag 'execution'. Accept: full, smart, fast"
                ],
            ],
            'Integer' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 0
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected errors' => [
                    "The value '0' is not allowed for the tag 'execution'. Accept: full, smart, fast"
                ],
            ],
            'Boolean' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => true
                    ],
                    'Tools' => ['check-security']
                ],
                'Expected errors' => [
                    "The value 'true' is not allowed for the tag 'execution'. Accept: full, smart, fast"
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider ExecutionKeyWithNoValidValuesDataProvider
     */
    function it_set_error_when_Options_have_valid_keys_with_invalid_values($configurationFile, $expectedErrors)
    {
        $validator = new ConfigurationFileValidator();

        $errors = $validator($configurationFile);

        $this->expectedErrors->setOptionsErrors($expectedErrors);

        $this->assertEquals($this->expectedErrors, $errors);
        $this->assertFalse($errors->hasWarnings());
    }

    function OpitonsHaveInvalidKeysDataProvider()
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
     * @dataProvider OpitonsHaveInvalidKeysDataProvider
     */
    function it_set_warning_when_Options_have_at_least_a_valid_key_and_invalid_keys($configurationFile, $expectedWarnings)
    {
        $validator = new ConfigurationFileValidator();

        $errors = $validator($configurationFile);

        $this->expectedErrors->setOptionsWarnings($expectedWarnings);

        $this->assertEquals($this->expectedErrors, $errors);
        $this->assertFalse($errors->hasErrors());
    }

    /*
    |--------------------------------------------------------------------------
    | Tools tests
    |--------------------------------------------------------------------------
    | This tests include Tools key and the key for configuration every tool:
    |       - phpcs
    |       - parallel-lint
    |       - phpmd
    |       - phpcpd
    |       - phpstan
    | The tool check-security has no configuration so there is no key to validate.
    */

    function ToolIsNotValidDataProvider()
    {
        return [
            'The Tool key not exist' => [
                'Options' => [
                    'execution' => 'full',
                ],
            ],
            'The Tool key is null' => [[
                'Options' => [
                    'execution' => 'full',
                ],
                'Tools' => null
            ],
            ],
            'The Tool key is empty' => [[
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
     * @dataProvider ToolIsNotValidDataProvider
     */
    function set_error_when_the_Tool_key_is_not_valid($configurationFile)
    {

        $validator = new ConfigurationFileValidator();

        $errors = $validator($configurationFile);

        $this->expectedErrors->setToolsErrors(["The key 'Tools' must exists."]);

        $this->assertEquals($this->expectedErrors, $errors);
        $this->assertFalse($errors->hasWarnings());
    }

    function ToolNotContainValidToolsDataProvider()
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
                'Expected Warnings' => ['The tool no-valid-tool is not supported by GitHooks.',]
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
                'Expected Warnings' => [
                    'The tool no-valid-tool-1 is not supported by GitHooks.',
                    'The tool no-valid-tool-2 is not supported by GitHooks.',
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider ToolNotContainValidToolsDataProvider
     */
    function set_error_when_the_Tool_key_not_contain_any_valid_tool($configurationFile, $expectedWarnings)
    {
        $validator = new ConfigurationFileValidator();

        $errors = $validator($configurationFile);

        $this->expectedErrors->setToolsErrors(['There must be at least one tool configured.'])
                            ->setToolsWarnings($expectedWarnings);

        $this->assertEquals($this->expectedErrors, $errors);
    }

    function ToolNotContainValidToolsWithSettingsDataProvider()
    {
        return [
            'Only Php Code Sniffer' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => [
                        'phpcs'
                    ]
                ],
                'Tool' => 'phpcs',
                'Expected Warnings' => []
            ],
            'Only Php Copy Paste Detector' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => [
                        'phpcpd'
                    ]
                ],
                'Tool' => 'phpcpd',
                'Expected Warnings' => []
            ],
            'Only Php Mess Detector' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => [
                        'phpmd'
                    ]
                ],
                'Tool' => 'phpmd',
                'Expected Warnings' => []
            ],
            'Only Parallel-lint' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => [
                        'parallel-lint'
                    ]
                ],
                'Tool' => 'parallel-lint',
                'Expected Warnings' => []
            ],
            'Only Php Stan' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => [
                        'phpstan'
                    ]
                ],
                'Tool' => 'phpstan',
                'Expected Warnings' => []
            ],
            'Set errors and warning when there is a valid tool without settings and a invalid tool' => [
                'Configuration File' => [
                    'Options' => [
                        'execution' => 'full',
                    ],
                    'Tools' => [
                        'phpstan',
                        'fake-tool'
                    ]
                ],
                'Tool' => 'phpstan',
                'Expected Warnings' => ['The tool fake-tool is not supported by GitHooks.']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider ToolNotContainValidToolsWithSettingsDataProvider
     */
    function set_errors_when_the_Tool_key_not_contain_any_valid_tool_with_settings($configurationFile, $tool, $expectedWarnings)
    {
        $validator = new ConfigurationFileValidator();

        $errors = $validator($configurationFile);

        $this->expectedErrors
            ->setToolsErrors([
                "The tool $tool is not setting.",
                'There must be at least one tool configured.',
            ])
            ->setToolsWarnings($expectedWarnings);

        $this->assertEquals($this->expectedErrors, $errors);
    }


    function thereIsNoKeyOfAToolThatIsInToolsDataProvider()
    {
        return [
            'Phpstan is not setting' => [
                'Configuration File' => [
                    'Tools' => [
                        'phpcs',
                        'phpstan'
                    ],
                    'phpcs' => [],
                ],
                'tool' => 'phpstan',
            ],
            'Php Mess Detector is not setting' => [
                'Configuration File' => [
                    'Tools' => [
                        'phpcs',
                        'phpmd'
                    ],
                    'phpcs' => [],
                ],
                'tool' => 'phpmd',
            ],
            'Php Copy Paste Detector is not setting' => [
                'Configuration File' => [
                    'Tools' => [
                        'phpcs',
                        'phpcpd'
                    ],
                    'phpcs' => [],
                ],
                'tool' => 'phpcpd',
            ],
            'Parallel-lint is not setting' => [
                'Configuration File' => [
                    'Tools' => [
                        'phpcs',
                        'parallel-lint'
                    ],
                    'phpcs' => [],
                ],
                'tool' => 'parallel-lint',
            ],
            'Php Code Sniffer is not setting' => [
                'Configuration File' => [
                    'Tools' => [
                        'phpcs',
                        'phpstan'
                    ],
                    'phpstan' => [],
                ],
                'tool' => 'phpcs',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider thereIsNoKeyOfAToolThatIsInToolsDataProvider
     */
    function set_error_when_a_tool_in_Tools_key_does_not_have_a_key_with_its_settings($configurationFile, $tool)
    {
        $validator = new ConfigurationFileValidator();

        $errors = $validator($configurationFile);

        $this->expectedErrors->setToolsErrors(["The tool $tool is not setting."]);

        $this->assertEquals($this->expectedErrors, $errors);
        $this->assertFalse($errors->hasWarnings());
    }


    function toolsHaveInvalidArgumentsInItsSettingsDataProvider()
    {
        return [
            'Phpstan has one invalid argument' => [
                'Configuration File' => [
                    'Tools' => [
                        'phpstan'
                    ],
                    'phpstan' => [
                        'config' => 'myfile.neon',
                        'memory-limit' => '1G',
                        'level' => 0,
                        'paths' => ['myPath'],
                        'invalid-key' => false
                    ],
                ],
                'tool' => 'phpstan',
            ],
            'Php Code Sniffer has one invalid argument' => [
                'Configuration File' => [
                    'Tools' => [
                        'phpcs'
                    ],
                    'phpcs' => [
                        'standard' => 'myrules.xml',
                        'ignore' => 'pathIgnored',
                        'error-severity' => 0,
                        'warning-severity' => 8,
                        'invalid-key' => false
                    ],
                ],
                'tool' => 'phpcs',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider toolsHaveInvalidArgumentsInItsSettingsDataProvider
     */
    function set_warning_when_a_tool_have_invalid_keys_in_its_settings($configurationFile, $tool)
    {
        $validator = new ConfigurationFileValidator();

        $errors = $validator($configurationFile);

        $this->expectedErrors->setToolsWarnings(["invalid-key argument is invalid for tool $tool"]);

        $this->assertEquals($this->expectedErrors, $errors);
        $this->assertFalse($errors->hasErrors());
    }
}
