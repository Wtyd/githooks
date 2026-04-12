<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Tools\Errors;

class ErrorsTest extends TestCase
{
    /** @test */
    function it_starts_empty()
    {
        $errors = new Errors();

        $this->assertTrue($errors->isEmpty());
        $this->assertEmpty($errors->getErrors());
    }

    /** @test */
    function it_stores_error_for_a_tool()
    {
        $errors = new Errors();
        $errors->setError('phpstan', 'Found 3 errors');

        $this->assertFalse($errors->isEmpty());
        $this->assertArrayHasKey('phpstan', $errors->getErrors());
        $this->assertSame('Found 3 errors', $errors->getErrors()['phpstan']);
    }

    /** @test */
    function it_does_not_store_error_when_tool_name_is_empty()
    {
        $errors = new Errors();
        $errors->setError('', 'some error');

        $this->assertTrue($errors->isEmpty());
        $this->assertEmpty($errors->getErrors());
    }

    /** @test */
    function it_uses_default_message_when_error_string_is_empty()
    {
        $errors = new Errors();
        $errors->setError('phpcs', '');

        $this->assertFalse($errors->isEmpty());
        $this->assertSame('register error in live output execution', $errors->getErrors()['phpcs']);
    }

    /** @test */
    function it_overwrites_previous_error_for_same_tool()
    {
        $errors = new Errors();
        $errors->setError('phpstan', 'first error');
        $errors->setError('phpstan', 'second error');

        $this->assertSame('second error', $errors->getErrors()['phpstan']);
        $this->assertCount(1, $errors->getErrors());
    }

    /** @test */
    function it_stores_multiple_tool_errors()
    {
        $errors = new Errors();
        $errors->setError('phpstan', 'type error');
        $errors->setError('phpmd', 'complexity');

        $this->assertCount(2, $errors->getErrors());
        $this->assertArrayHasKey('phpstan', $errors->getErrors());
        $this->assertArrayHasKey('phpmd', $errors->getErrors());
    }

    /** @test */
    function toString_returns_no_errors_message_when_empty()
    {
        $errors = new Errors();

        $this->assertSame('There are no errors.', (string) $errors);
    }

    /** @test */
    function toString_formats_errors_with_tool_names()
    {
        $errors = new Errors();
        $errors->setError('phpstan', 'Found 3 errors');
        $errors->setError('phpmd', 'CyclomaticComplexity');

        $output = (string) $errors;

        $this->assertStringContainsString('The following errors have occurred:', $output);
        $this->assertStringContainsString('For phpstan:', $output);
        $this->assertStringContainsString('Found 3 errors', $output);
        $this->assertStringContainsString('For phpmd:', $output);
        $this->assertStringContainsString('CyclomaticComplexity', $output);
    }
}
