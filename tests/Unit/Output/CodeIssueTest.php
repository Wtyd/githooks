<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\CodeIssue;

class CodeIssueTest extends TestCase
{
    /** @test */
    public function it_stores_all_fields()
    {
        $issue = new CodeIssue(
            'src/User.php',
            14,
            20,
            5,
            'Method getRole() not found',
            'phpstan.method.notFound',
            'error',
            'phpstan'
        );

        $this->assertSame('src/User.php', $issue->getFile());
        $this->assertSame(14, $issue->getLine());
        $this->assertSame(20, $issue->getEndLine());
        $this->assertSame(5, $issue->getColumn());
        $this->assertSame('Method getRole() not found', $issue->getMessage());
        $this->assertSame('phpstan.method.notFound', $issue->getRuleId());
        $this->assertSame('error', $issue->getSeverity());
        $this->assertSame('phpstan', $issue->getToolName());
    }

    /** @test */
    public function nullable_fields_default_to_null()
    {
        $issue = new CodeIssue('src/Foo.php', 1, null, null, 'msg', 'rule', 'warning', 'phpcs');

        $this->assertNull($issue->getEndLine());
        $this->assertNull($issue->getColumn());
    }

    /** @test */
    public function fingerprint_is_deterministic()
    {
        $issue1 = new CodeIssue('src/User.php', 14, null, null, 'msg', 'rule.id', 'error', 'phpstan');
        $issue2 = new CodeIssue('src/User.php', 14, null, null, 'different msg', 'rule.id', 'warning', 'phpcs');

        $this->assertSame($issue1->getFingerprint(), $issue2->getFingerprint());
        $this->assertSame(md5('src/User.php:14:rule.id'), $issue1->getFingerprint());
    }

    /** @test */
    public function fingerprint_differs_for_different_locations()
    {
        $issue1 = new CodeIssue('src/User.php', 14, null, null, 'msg', 'rule', 'error', 'phpstan');
        $issue2 = new CodeIssue('src/User.php', 15, null, null, 'msg', 'rule', 'error', 'phpstan');
        $issue3 = new CodeIssue('src/Order.php', 14, null, null, 'msg', 'rule', 'error', 'phpstan');

        $this->assertNotSame($issue1->getFingerprint(), $issue2->getFingerprint());
        $this->assertNotSame($issue1->getFingerprint(), $issue3->getFingerprint());
    }
}
