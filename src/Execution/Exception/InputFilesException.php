<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution\Exception;

use RuntimeException;
use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;

/**
 * Thrown by InputFilesResolver when the user-provided file list cannot be resolved.
 * The error code identifies the failure family so the caller can produce the exact
 * message the spec mandates (see §9.5 of spec-design-files-flag.md).
 */
class InputFilesException extends RuntimeException implements GitHooksExceptionInterface
{
    public const MUTUALLY_EXCLUSIVE          = 'mutually-exclusive';
    public const FILES_FROM_MISSING          = 'files-from-missing';
    public const EMPTY_INPUT                 = 'empty-input';
    public const ALL_INVALID                 = 'all-invalid';
    public const EXCLUDE_PATTERN_REQUIRES_INPUT = 'exclude-pattern-requires-input';
    public const EXCLUDE_ELIMINATED_ALL      = 'exclude-eliminated-all';

    private string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function mutuallyExclusive(): self
    {
        return new self(
            self::MUTUALLY_EXCLUSIVE,
            '--files and --files-from are mutually exclusive'
        );
    }

    public static function filesFromMissing(string $path): self
    {
        return new self(
            self::FILES_FROM_MISSING,
            "--files-from: file '$path' does not exist"
        );
    }

    public static function emptyInput(): self
    {
        return new self(
            self::EMPTY_INPUT,
            'no input files provided'
        );
    }

    public static function allInvalid(): self
    {
        return new self(
            self::ALL_INVALID,
            'all input files are invalid'
        );
    }

    public static function excludePatternRequiresInput(): self
    {
        return new self(
            self::EXCLUDE_PATTERN_REQUIRES_INPUT,
            '--exclude-pattern requires --files or --files-from'
        );
    }

    public static function excludeEliminatedAll(): self
    {
        return new self(
            self::EXCLUDE_ELIMINATED_ALL,
            '--exclude-pattern eliminated all input files'
        );
    }
}
