<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\CommitMessage;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\Alfil\Jobs\CommitMessage\MessageFileResolver;

/**
 * Factor table of factors-message-file.md (FEAT-16, 4.0 ALFIL_COMMIT_MSG_FILE):
 * source priority (hook/cli > inline > env > fallback), the null-on-no-source
 * case (REQ-005) and raw reading (REQ-006). Disk is stubbed via protected seams.
 */
class MessageFileResolverTest extends UnitTestCase
{
    private function resolver(bool $fallbackExists = false, ?string $fileContents = null): MessageFileResolver
    {
        return new class ($fallbackExists, $fileContents) extends MessageFileResolver {
            private bool $fallbackExists;
            private ?string $fileContents;
            public array $tempWrites = [];

            public function __construct(bool $fallbackExists, ?string $fileContents)
            {
                $this->fallbackExists = $fallbackExists;
                $this->fileContents = $fileContents;
            }

            protected function fileExists(string $path): bool
            {
                if (strpos($path, '.git/COMMIT_EDITMSG') !== false) {
                    return $this->fallbackExists;
                }
                return $this->fileContents !== null;
            }

            protected function readContents(string $path)
            {
                return $this->fileContents ?? false;
            }

            protected function writeTemp(string $content): string
            {
                $this->tempWrites[] = $content;
                return '/tmp/fake-inline-' . count($this->tempWrites) . '.txt';
            }
        };
    }

    /**
     * @test
     * @dataProvider sourcePriorityTable
     *
     * Decision table of tests/Unit/Jobs/CommitMessage/factors-message-file.md:
     * the highest-priority PRESENT source wins and is returned as-is (a path to
     * a missing file included — only the fallback is existence-checked), null
     * only when nothing is present and the fallback does not exist.
     *
     * @param string|null $expected 'temp' = a materialised inline file
     */
    public function resolve_follows_the_source_priority_table(
        ?string $explicit,
        ?string $inline,
        ?string $env,
        bool $fallbackExists,
        ?string $expected
    ): void {
        $resolver = $this->resolver($fallbackExists);

        $path = $resolver->resolve($explicit, $inline, $env, '/root');

        if ($expected === 'temp') {
            $this->assertSame('/tmp/fake-inline-1.txt', $path);
            $this->assertSame([$inline], $resolver->tempWrites, 'the inline content is what gets materialised');
            return;
        }
        $this->assertSame($expected, $path);
        $this->assertSame([], $resolver->tempWrites, 'nothing is materialised unless inline is the winner');
    }

    /** @return array<string, array{?string, ?string, ?string, bool, ?string}> */
    public function sourcePriorityTable(): array
    {
        // The stub's fileExists() is false for every non-fallback path, so each
        // explicit/env path below is a "missing file" as far as the resolver knows.
        return [
            // explicit, inline, env, fallback exists, expected
            'explicit wins over inline, env and fallback'      => ['/hook/MSG', 'inline', '/env/MSG', true, '/hook/MSG'],
            'explicit missing file is returned, not env'       => ['/hook/missing', null, '/env/MSG', true, '/hook/missing'],
            'inline wins over env and fallback'                => [null, 'feat: inline', '/env/MSG', true, 'temp'],
            'empty explicit is absent, empty inline is content' => ['', '', '/env/MSG', true, 'temp'],
            'env wins over fallback'                            => [null, null, '/env/MSG', true, '/env/MSG'],
            'env missing file is returned, not fallback'        => [null, null, '/env/missing', true, '/env/missing'],
            'env with no fallback'                              => [null, null, '/env/MSG', false, '/env/MSG'],
            'empty explicit and empty env fall through'         => ['', null, '', true, '/root/.git/COMMIT_EDITMSG'],
            'empty env and no fallback is null'                 => [null, null, '', false, null],
            'no source, fallback exists'                        => [null, null, null, true, '/root/.git/COMMIT_EDITMSG'],
            'no source, no fallback is null'                    => [null, null, null, false, null],
        ];
    }

    /** @test */
    public function read_raw_returns_contents_when_readable(): void
    {
        $resolver = $this->resolver(false, "feat: x\n\nbody\n");

        $this->assertSame("feat: x\n\nbody\n", $resolver->readRaw('/any/path'));
    }

    /** @test */
    public function read_raw_returns_null_when_missing(): void
    {
        $resolver = $this->resolver(false, null);

        $this->assertNull($resolver->readRaw('/missing/path'));
    }
}
