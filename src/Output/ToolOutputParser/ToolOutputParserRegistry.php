<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\ToolOutputParser;

class ToolOutputParserRegistry
{
    /** @var array<string, class-string<ToolOutputParserInterface>> */
    private const PARSER_MAP = [
        'phpstan'       => PhpstanOutputParser::class,
        'phpcs'         => PhpcsOutputParser::class,
        'psalm'         => PsalmOutputParser::class,
        'phpmd'         => PhpmdOutputParser::class,
        'parallel-lint' => ParallelLintOutputParser::class,
    ];

    public function getParser(string $jobType): ?ToolOutputParserInterface
    {
        if (!array_key_exists($jobType, self::PARSER_MAP)) {
            return null;
        }

        $class = self::PARSER_MAP[$jobType];
        return new $class();
    }

    public function hasParser(string $jobType): bool
    {
        return array_key_exists($jobType, self::PARSER_MAP);
    }
}
