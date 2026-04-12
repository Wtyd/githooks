<?php

namespace Tests\Unit\Utils;

use Illuminate\Support\Facades\Storage;
use Tests\Zero\ZeroTestCase;
use Wtyd\GitHooks\Utils\FileUtils;

class FileUtilsTest extends ZeroTestCase
{

    /** @test */
    function directory_does_not_contains_a_deleted_file()
    {
        $file = 'src/Hooks.php';
        Storage::shouldReceive('exists')
            ->twice()
            ->with($file)
            ->andReturn(false, false);

        $fileUtils = new FileUtils();

        $directory = 'src';

        $this->assertFalse($fileUtils->directoryContainsFile($directory, $file), 'The file Exists');

        $directory = './';
        $this->assertFalse($fileUtils->directoryContainsFile($directory, $file), 'The file Exists');
    }

    /** @test */
    function directory_contains_any_file_that_exist_if_directory_is_root_directory()
    {
        $file = 'src/Hooks.php';
        Storage::shouldReceive('exists')
            ->with($file)
            ->andReturn(true);

        $fileUtils = new FileUtils();

        $directory = './';

        $this->assertTrue($fileUtils->directoryContainsFile($directory, $file));
    }

    public function directoryContainsFileDataProvider()
    {
        return [
            'File is in root directory' => [
                'src',
                'src/Hooks.php'
            ],
            'File is in subdirectory' => [
                'src',
                'src/SubDir/Hooks.php'
            ],
            'File is in sub subdirectory' => [
                'src',
                'src/SubDir/AnotherDir/Hooks.php'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider directoryContainsFileDataProvider
     */
    function directoy_contains_file($directory, $file)
    {
        Storage::shouldReceive('exists')
            ->with($file)
            ->andReturn(true);

        Storage::shouldReceive('allFiles')
            ->once()
            ->with($directory)
            ->andReturn([
                'src/Hooks.php',
                'src/SubDir/Hooks.php',
                'src/SubDir/AnotherDir/Hooks.php',
            ]);

        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->directoryContainsFile($directory, $file));
    }

    /**
     * @test
     * @dataProvider directoryContainsFileDataProvider
     */
    function directoy_DOESNT_contains_file($directory, $file)
    {
        Storage::shouldReceive('exists')
            ->with($file)
            ->andReturn(true);

        Storage::shouldReceive('allFiles')
            ->once()
            ->with($directory)
            ->andReturn([
                'src/OtherFile.php',
                'src/SubDir/OtherFile.php',
                'src/SubDir/AnotherDir/OtherFile.php',
            ]);

        $fileUtils = new FileUtils();

        $this->assertFalse($fileUtils->directoryContainsFile($directory, $file));
    }

    // ========================================================================
    // isSameFile tests
    // ========================================================================

    /** @test */
    function isSameFile_returns_true_for_identical_paths()
    {
        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->isSameFile('src/Foo.php', 'src/Foo.php'));
    }

    /** @test */
    function isSameFile_returns_false_for_different_paths()
    {
        $fileUtils = new FileUtils();

        $this->assertFalse($fileUtils->isSameFile('src/Foo.php', 'src/Bar.php'));
    }

    /** @test */
    function isSameFile_strips_root_path_prefix_from_first_file()
    {
        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->isSameFile('./src/Foo.php', 'src/Foo.php'));
    }

    /** @test */
    function isSameFile_strips_root_path_prefix_from_second_file()
    {
        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->isSameFile('src/Foo.php', './src/Foo.php'));
    }

    /** @test */
    function isSameFile_strips_root_path_prefix_from_both_files()
    {
        $fileUtils = new FileUtils();

        $this->assertTrue($fileUtils->isSameFile('./src/Foo.php', './src/Foo.php'));
    }

    /** @test */
    function isSameFile_returns_false_when_base_paths_differ_with_root_prefix()
    {
        $fileUtils = new FileUtils();

        $this->assertFalse($fileUtils->isSameFile('./src/Foo.php', './tests/Foo.php'));
    }
}
