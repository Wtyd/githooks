<?php

namespace Tests;

use GitHooks\Configuration;
use Tests\System\ExecutableFinderTest;
use Tests\System\Utils\ConfigurationFake;
use Illuminate\Container\Container;

trait MockConfigurationFileTrait
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * For system tests I need to read the configuration file 'githooks.yml' but the SUT looks for it in the root or qa/ directories.
     * In order to use a configuration file created expressly for each test, I mock the 'findConfigurationFile' method so that
     * return the root directory where I create the file structure for the tests ($this->path)
     *
     * For ExecutableFinderTest I can't use Mockery in two of three stages (only when I install the application with dev dependencies).
     * For this, I have created ConfigurationFake.
     *
     * @return void
     */
    protected function mockPathGitHooksConfigurationFile(): void
    {
        if ($this instanceof ExecutableFinderTest) {
            $this->container->bind(Configuration::class, ConfigurationFake::class);
        } else {
            $mockConfiguration = Mock::mock(Configuration::class)->shouldAllowMockingProtectedMethods()->makePartial();
            $mockConfiguration->shouldReceive('findConfigurationFile')->andReturn($this->path . '/githooks.yml');
            $this->container->instance(Configuration::class, $mockConfiguration);
        }
    }

    /**
     * It's the same method as 'mockPathGitHooksConfigurationFile' for command tests. In these tests the Container it's implicit in $this->app
     *
     * @return void
     */
    protected function mockConfigurationFileForCommandsTests(): void
    {
        $mockConfiguration = Mock::mock(Configuration::class)->shouldAllowMockingProtectedMethods()->makePartial();
        $mockConfiguration->shouldReceive('findConfigurationFile')->andReturn($this->path . '/githooks.yml');

        $this->app->instance(Configuration::class, $mockConfiguration);
    }
}
