<?php

declare(strict_types=1);

namespace Tests\Unit\Output\Inspection;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\App\Commands\Concerns\ResolvesDiagnosticFormat;

/**
 * Factor table A (see factors.md): the only factor is the `--format` value.
 * Absent and `text`/`json` resolve silently; any other value warns to stderr
 * and falls back to `text` (mirroring the execution commands).
 */
class ResolvesDiagnosticFormatTest extends UnitTestCase
{
    private function harness(string $format): object
    {
        return new class ($format) {
            use ResolvesDiagnosticFormat;

            /** @var string[] */
            public array $stderr = [];

            private string $format;

            public function __construct(string $format)
            {
                $this->format = $format;
            }

            public function option($key = null): string
            {
                return $this->format;
            }

            protected function emitStderr(string $message): void
            {
                $this->stderr[] = $message;
            }

            public function resolve(): string
            {
                return $this->resolveDiagnosticFormat();
            }
        };
    }

    /**
     * @test
     * @dataProvider formatCases
     */
    public function it_resolves_the_format_and_warns_only_on_invalid(string $input, string $expected, bool $warns)
    {
        $harness = $this->harness($input);

        $this->assertSame($expected, $harness->resolve());
        $this->assertSame($warns ? 1 : 0, count($harness->stderr));
    }

    public function formatCases(): array
    {
        return [
            'absent → text, no warning'  => ['', 'text', false],
            'text → text, no warning'    => ['text', 'text', false],
            'json → json, no warning'    => ['json', 'json', false],
            'csv → text + warning'       => ['csv', 'text', true],
        ];
    }

    /** @test */
    public function invalid_format_warning_message_is_exact()
    {
        $harness = $this->harness('csv');

        $harness->resolve();

        $this->assertSame(
            "Unknown format 'csv'. Using text output. Valid formats: text, json.",
            $harness->stderr[0]
        );
    }
}
