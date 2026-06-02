<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\CommitMessage;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Jobs\CommitMessage\MessageFileResolver;

/**
 * Factor table B (FEAT-16): source priority (hook/cli > inline > env > fallback),
 * the null-on-no-source case (REQ-005) and raw reading (REQ-006). Disk is
 * stubbed via protected seams.
 */
class MessageFileResolverTest extends TestCase
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

    /** @test */
    public function explicit_file_wins_over_everything(): void
    {
        $resolver = $this->resolver(true);

        $path = $resolver->resolve('/hook/COMMIT_EDITMSG', 'inline text', '/env/file', '/root');

        $this->assertSame('/hook/COMMIT_EDITMSG', $path);
    }

    /** @test */
    public function inline_wins_over_env_and_fallback(): void
    {
        $resolver = $this->resolver(true);

        $path = $resolver->resolve(null, 'feat: inline message', '/env/file', '/root');

        $this->assertStringStartsWith('/tmp/fake-inline-', $path);
        $this->assertSame(['feat: inline message'], $resolver->tempWrites);
    }

    /** @test */
    public function env_wins_over_fallback(): void
    {
        $resolver = $this->resolver(true);

        $path = $resolver->resolve(null, null, '/env/COMMIT_MSG', '/root');

        $this->assertSame('/env/COMMIT_MSG', $path);
    }

    /** @test */
    public function fallback_used_when_it_exists_and_no_other_source(): void
    {
        $resolver = $this->resolver(true);

        $path = $resolver->resolve(null, null, null, '/root');

        $this->assertSame('/root/.git/COMMIT_EDITMSG', $path);
    }

    /** @test */
    public function returns_null_when_no_source_and_no_fallback(): void
    {
        $resolver = $this->resolver(false);

        $path = $resolver->resolve(null, null, null, '/root');

        $this->assertNull($path);
    }

    /** @test */
    public function empty_explicit_and_env_are_treated_as_absent(): void
    {
        $resolver = $this->resolver(true);

        $path = $resolver->resolve('', null, '', '/root');

        $this->assertSame('/root/.git/COMMIT_EDITMSG', $path);
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
