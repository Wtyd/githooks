<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\CommitMessage;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Jobs\CommitMessage\CommitMessagePresets;
use Wtyd\GitHooks\Jobs\CommitMessage\CommitMessageValidator;

/**
 * Factor table A (FEAT-16): one case per rule × both senses, boundary values
 * (AVL) for the length rules, deterministic evaluation order, subject-case
 * with/without Conventional-Commits prefix, merge/squash/fixup detection, and
 * subject extraction (BOM, CRLF, multibyte length).
 */
class CommitMessageValidatorTest extends TestCase
{
    private CommitMessageValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CommitMessageValidator();
    }

    /**
     * @test
     * @dataProvider ruleCases
     *
     * @param array<string, mixed> $rules
     */
    public function evaluates_each_rule(string $subject, array $rules, ?string $expectedFailedRule): void
    {
        $outcome = $this->validator->validate($subject, $rules);

        if ($expectedFailedRule === null) {
            $this->assertTrue($outcome->isPassed(), "Expected pass, failed on '{$outcome->getFailedRule()}'");
            $this->assertNull($outcome->getFailedRule());
        } else {
            $this->assertFalse($outcome->isPassed());
            $this->assertSame($expectedFailedRule, $outcome->getFailedRule());
            $this->assertNotNull($outcome->getReason());
        }
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: ?string}> */
    public static function ruleCases(): array
    {
        return [
            // forbid-empty (default true)
            'empty subject fails forbid-empty'          => ['', [], 'forbid-empty'],
            'whitespace-only fails forbid-empty'        => ['   ', [], 'forbid-empty'],
            'forbid-empty disabled allows empty'        => ['', ['forbid-empty' => false], null],
            'non-empty passes when no rules'            => ['anything goes', [], null],

            // min-length (AVL: ==min passes, ==min-1 fails)
            'min-length boundary equal passes'          => ['exactlyten', ['min-length' => 10], null],
            'min-length below fails'                    => ['ninechars', ['min-length' => 10], 'min-length'],

            // max-length (AVL: ==max passes, ==max+1 fails)
            'max-length boundary equal passes'          => ['abcde', ['max-length' => 5], null],
            'max-length above fails'                    => ['abcdef', ['max-length' => 5], 'max-length'],

            // pattern
            'pattern match passes'                      => ['feat: x', ['pattern' => '/^feat: /'], null],
            'pattern miss fails'                        => ['nope', ['pattern' => '/^feat: /'], 'pattern'],

            // forbid-trailing-period (rtrim before checking)
            'trailing period fails'                     => ['ends with dot.', ['forbid-trailing-period' => true], 'forbid-trailing-period'],
            'trailing period after spaces fails'        => ['dot then space. ', ['forbid-trailing-period' => true], 'forbid-trailing-period'],
            'no trailing period passes'                 => ['clean subject', ['forbid-trailing-period' => true], null],
            'trailing period allowed when rule off'     => ['ends with dot.', [], null],

            // subject-case lowercase (strips CC prefix)
            'lowercase ok without prefix'               => ['all lower words', ['subject-case' => 'lowercase'], null],
            'lowercase fails on capital'                => ['Has Capitals', ['subject-case' => 'lowercase'], 'subject-case'],
            'lowercase ok with CC prefix lower desc'    => ['feat(api): add endpoint', ['subject-case' => 'lowercase'], null],
            'lowercase fails with CC prefix cap desc'   => ['feat(api): Add Endpoint', ['subject-case' => 'lowercase'], 'subject-case'],

            // subject-case sentence
            'sentence ok capital first'                 => ['Add endpoint', ['subject-case' => 'sentence'], null],
            'sentence fails lower first'                => ['add endpoint', ['subject-case' => 'sentence'], 'subject-case'],
            'sentence ok with CC prefix cap desc'       => ['feat(api): Add endpoint', ['subject-case' => 'sentence'], null],

            // subject-case null disables
            'subject-case null disables check'          => ['MiXeD CaSe', ['subject-case' => null], null],

            // sentence-case: only the FIRST char counts (kills the /^[A-Z]/ → /[A-Z]/ mutant)
            'sentence fails when only a later char is capital' => ['add Endpoint here', ['subject-case' => 'sentence'], 'subject-case'],

            // corporate convention via custom pattern (tipo (equipo) ID título)
            'corporate pattern passes'                  => ['feat (backend) PROJ-42 add user endpoint', ['pattern' => '/^(feat|fix) \([\w-]+\) [A-Z]+-\d+ .+/'], null],
            'corporate pattern fails missing id'        => ['feat (backend) add user endpoint', ['pattern' => '/^(feat|fix) \([\w-]+\) [A-Z]+-\d+ .+/'], 'pattern'],
        ];
    }

    /**
     * @test
     * @dataProvider mergeCases
     */
    public function detects_merge_squash_fixup(string $subject, bool $expectedSkip): void
    {
        $outcome = $this->validator->validate($subject, ['pattern' => '/^feat: /']);

        $this->assertSame($expectedSkip, $outcome->isMerge());
        if ($expectedSkip) {
            $this->assertTrue($outcome->isPassed(), 'merge-skip implies pass');
        }
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function mergeCases(): array
    {
        return [
            'Merge literal'        => ["Merge branch 'feature/foo'", true],
            'Merge case-insensitive' => ['MERGE pull request #42', true],
            'squash! prefix'       => ['squash! feat: add x', true],
            'fixup! prefix'        => ['fixup! feat: add x', true],
            'Merged is not a merge' => ['Merged feature work', false],
            'regular commit'       => ['plain subject', false],
        ];
    }

    /** @test */
    public function merge_allowed_false_validates_merge_normally(): void
    {
        $outcome = $this->validator->validate(
            "Merge branch 'feature/foo'",
            ['merge-allowed' => false, 'pattern' => '/^feat: /']
        );

        $this->assertFalse($outcome->isMerge());
        $this->assertFalse($outcome->isPassed());
        $this->assertSame('pattern', $outcome->getFailedRule());
    }

    /** @test */
    public function evaluation_order_reports_first_failing_rule(): void
    {
        // Subject violates BOTH min-length (too short) and pattern. min-length
        // is evaluated first (REQ-015), so it must be the reported failure.
        $outcome = $this->validator->validate('xx', [
            'min-length' => 10,
            'pattern'    => '/^feat: /',
        ]);

        $this->assertSame('min-length', $outcome->getFailedRule());
    }

    /** @test */
    public function pattern_failure_surfaces_message_and_example_from_preset(): void
    {
        $rules = CommitMessagePresets::resolve('conventional-commits', []);

        $outcome = $this->validator->validate('Add stuff.', $rules);

        // Conventional-commits pattern fails first relevant rule for 'Add stuff.'
        // (does not match the type prefix). The preset carries a human message
        // and an example.
        $this->assertFalse($outcome->isPassed());
        $this->assertSame('pattern', $outcome->getFailedRule());
        $this->assertSame('feat(api): add user endpoint', $outcome->getExample());
    }

    /** @test */
    public function custom_pattern_has_no_example(): void
    {
        $outcome = $this->validator->validate('nope', ['pattern' => '/^feat: /']);

        $this->assertSame('pattern', $outcome->getFailedRule());
        $this->assertNull($outcome->getExample());
    }

    /**
     * @test
     * @dataProvider subjectExtractionCases
     */
    public function extracts_subject(string $raw, string $expectedSubject, int $expectedLength): void
    {
        $subject = CommitMessageValidator::extractSubject($raw);

        $this->assertSame($expectedSubject, $subject);
        $this->assertSame($expectedLength, (int) mb_strlen($subject));
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function subjectExtractionCases(): array
    {
        return [
            'first line only'      => ["feat: x\n\nbody here\n", 'feat: x', 7],
            'CRLF normalized'      => ["feat: x\r\n\r\nbody\r\n", 'feat: x', 7],
            'lone CR normalized'   => ["feat: x\rbody", 'feat: x', 7],
            'BOM stripped'         => ["\xEF\xBB\xBFfeat: x\n", 'feat: x', 7],
            'no newline whole msg' => ['feat: only subject', 'feat: only subject', 18],
            'utf8 counts codepoints' => ["feat: añadir 🚀", 'feat: añadir 🚀', 14],
        ];
    }

    /** @test */
    public function subject_length_is_reported_in_outcome(): void
    {
        $outcome = $this->validator->validate('feat: add endpoint', []);

        $this->assertSame(18, $outcome->getSubjectLength());
    }

    /**
     * @test
     *
     * Length is UTF-8 code points, not bytes (CON-005). '🚀🚀🚀' is 3 code
     * points but 12 bytes; with `strlen` the min-length check would not fire
     * and `subjectLength` would be 12 — both assertions kill the mb_strlen mutant.
     */
    public function length_is_counted_in_utf8_code_points(): void
    {
        $outcome = $this->validator->validate('🚀🚀🚀', ['min-length' => 5]);

        $this->assertSame(3, $outcome->getSubjectLength());
        $this->assertSame('min-length', $outcome->getFailedRule(), '3 code points < min-length 5 must fail');
    }

    /**
     * @test
     *
     * Counterpart on max-length: '🚀🚀' is 2 code points (8 bytes); a
     * `max-length` of 3 must pass — `strlen` (8 > 3) would wrongly fail it.
     */
    public function max_length_uses_code_points_not_bytes(): void
    {
        $outcome = $this->validator->validate('🚀🚀', ['max-length' => 3]);

        $this->assertTrue($outcome->isPassed(), '2 code points <= max-length 3 must pass');
    }
}
