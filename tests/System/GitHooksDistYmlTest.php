<?php

namespace Tests\System;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Tests\Utils\TestCase\SystemTestCase;

class GitHooksDistYmlTest extends SystemTestCase
{
    protected function readDistFile(): array
    {
        $distFile = Storage::get('qa/githooks.dist.yml');
        $distFile = preg_replace('/^\# /m', '', $distFile);
        $distFile = str_replace("Configuration of each tool", "# Configuration of each tool", $distFile);
        return Yaml::parse($distFile);
    }

    /** @test */
    function it_checks_dist_configuration_file_has_Options_tags_with_all_options()
    {
        $control = $this->configurationFileBuilder->buildArray();

        $distFile = $this->readDistFile();

        $this->assertArrayHasKey('Options', $distFile);

        $this->assertEmpty(array_diff_key($control['Options'], $distFile['Options']));
    }

    /** @test */
    function it_checks_dist_configuration_file_has_Tool_tag_with_all_tools()
    {
        $control = $this->configurationFileBuilder->buildArray();

        $distFile = $this->readDistFile();

        $this->assertArrayHasKey('Tools', $distFile);

        $this->assertEmpty(array_diff_key($control['Tools'], $distFile['Tools']));
    }

    public function toolsDataProvider()
    {
        return [
            'security-checker' => ['security-checker'],
            'parallel-lint' => ['parallel-lint'],
            'phpstan' => ['phpstan'],
            'phpcbf' => ['phpcbf'],
            'phpcs' => ['phpcs'],
            'phpmd' => ['phpmd'],
            'phpcpd' => ['phpcpd'],
        ];
    }

    /**
     * @test
     * @dataProvider toolsDataProvider
     */
    function it_checks_dist_configuration_file_has_each_tool_configuration_with_all_arguments($tool)
    {
        $control = $this->configurationFileBuilder->buildArray();

        $distFile = $this->readDistFile();

        $this->assertArrayHasKey($tool, $distFile);
        $this->assertEmpty(array_diff_key($control[$tool], $distFile[$tool]));
    }
}
