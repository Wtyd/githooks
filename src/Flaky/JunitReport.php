<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Flaky;

use DOMDocument;
use DOMElement;

/**
 * One junit file as read after one pass of `flow --repeat`: whether it could
 * be read at all and, when it could, the outcome of every testcase in it.
 *
 * A file that is missing, does not parse or could not be cleared before the
 * pass is never read as "the tests passed" — the pass is reported as an
 * anomaly instead (see tests/Unit/Flaky/factors.md).
 */
final class JunitReport
{
    public const FILE_OK = 'ok';

    public const FILE_MISSING = 'missing';

    public const FILE_CORRUPT = 'corrupt';

    public const FILE_STALE = 'stale';

    private string $fileState;

    /** @var array<string, string> test id => TestStatus */
    private array $tests;

    /** @var string[] test ids reported more than once in this file */
    private array $collisions;

    /**
     * @param array<string, string> $tests
     * @param string[] $collisions
     */
    private function __construct(string $fileState, array $tests = [], array $collisions = [])
    {
        $this->fileState = $fileState;
        $this->tests = $tests;
        $this->collisions = $collisions;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return self::missing();
        }

        try {
            $content = file_get_contents($path);
        } catch (\Throwable $e) {
            $content = false;
        }

        return $content === false ? new self(self::FILE_CORRUPT) : self::fromString($content);
    }

    public static function missing(): self
    {
        return new self(self::FILE_MISSING);
    }

    public static function stale(): self
    {
        return new self(self::FILE_STALE);
    }

    public static function fromString(string $xml): self
    {
        if (trim($xml) === '') {
            return new self(self::FILE_CORRUPT);
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;
        if (!$loaded || $root === null || !in_array($root->nodeName, ['testsuites', 'testsuite'], true)) {
            return new self(self::FILE_CORRUPT);
        }

        $tests = [];
        $collisions = [];
        foreach ($document->getElementsByTagName('testcase') as $testcase) {
            $id = self::identity($testcase);
            $status = self::status($testcase);
            if (array_key_exists($id, $tests)) {
                $collisions[] = $id;
                $status = TestStatus::worst($tests[$id], $status);
            }
            $tests[$id] = $status;
        }

        return new self(self::FILE_OK, $tests, array_values(array_unique($collisions)));
    }

    public function isReadable(): bool
    {
        return $this->fileState === self::FILE_OK;
    }

    public function getFileState(): string
    {
        return $this->fileState;
    }

    /**
     * @return array<string, string> test id => TestStatus
     */
    public function getTests(): array
    {
        return $this->tests;
    }

    /**
     * @return string[]
     */
    public function getCollisions(): array
    {
        return $this->collisions;
    }

    /**
     * `classname` (PHPUnit, paratest), else `class` (Pest), else `file`, plus
     * the testcase name verbatim: the dataset suffix PHPUnit appends
     * (`with data set #0`) is already part of it.
     */
    private static function identity(DOMElement $testcase): string
    {
        $owner = '';
        foreach (['classname', 'class', 'file'] as $attribute) {
            if ($testcase->getAttribute($attribute) !== '') {
                $owner = $testcase->getAttribute($attribute);
                break;
            }
        }
        $name = $testcase->getAttribute('name');

        return $owner === '' ? $name : "$owner::$name";
    }

    /**
     * PHPUnit writes incomplete tests as `<skipped>` too, so skipped covers both.
     */
    private static function status(DOMElement $testcase): string
    {
        $children = [];
        foreach ($testcase->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child->nodeName;
            }
        }

        if (array_intersect(['failure', 'error'], $children) !== []) {
            return TestStatus::NONGREEN;
        }
        if (array_intersect(['skipped', 'warning'], $children) !== []) {
            return TestStatus::NEUTRAL;
        }

        return TestStatus::GREEN;
    }
}
