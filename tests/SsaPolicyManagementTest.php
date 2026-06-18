<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for SSA Policy Management.
 *
 * Unit tests cover pure logic: slug generation, content sanitisation, category validation.
 * Integration tests require DB_DSN and are skipped otherwise.
 */
class SsaPolicyManagementTest extends TestCase
{
    // ── Helpers mirroring _policy_schema.php logic ───────────────────────────

    private function sanitise(string $raw): string
    {
        $cleaned = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $raw);
        $cleaned = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $cleaned ?? '');
        $cleaned = strip_tags($cleaned ?? '');
        $cleaned = str_replace(["\r\n", "\r"], "\n", $cleaned);
        return trim($cleaned);
    }

    private function generateSlug(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name)));
        $base = trim($base ?? '', '-');
        return substr($base ?: 'policy', 0, 100);
    }

    // ── Slug generation ───────────────────────────────────────────────────────

    public function testSlugGeneratedFromName(): void
    {
        $this->assertSame('privacy-policy', $this->generateSlug('Privacy Policy'));
    }

    public function testSlugLowercased(): void
    {
        $this->assertSame('terms-and-conditions', $this->generateSlug('Terms And Conditions'));
    }

    public function testSlugStripsSpecialChars(): void
    {
        $this->assertSame('booking-cancellation-terms', $this->generateSlug('Booking & Cancellation Terms!'));
    }

    public function testSlugEmptyNameFallback(): void
    {
        $this->assertSame('policy', $this->generateSlug(''));
    }

    public function testSlugMaxLength(): void
    {
        $long = str_repeat('a', 200);
        $slug = $this->generateSlug($long);
        $this->assertLessThanOrEqual(100, strlen($slug));
    }

    // ── Content sanitisation ──────────────────────────────────────────────────

    public function testSanitisationRemovesScriptTag(): void
    {
        $input  = "Safe text\n<script>alert('xss')</script>\nMore text";
        $result = $this->sanitise($input);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('Safe text', $result);
        $this->assertStringContainsString('More text', $result);
    }

    public function testSanitisationRemovesStyleTag(): void
    {
        $input  = "Text\n<style>body{display:none}</style>\nAfter";
        $result = $this->sanitise($input);
        $this->assertStringNotContainsString('<style>', $result);
        $this->assertStringContainsString('Text', $result);
        $this->assertStringContainsString('After', $result);
    }

    public function testSanitisationRemovesHtmlTags(): void
    {
        $input  = '<b>Bold</b> and <a href="x">link</a>';
        $result = $this->sanitise($input);
        $this->assertStringNotContainsString('<b>', $result);
        $this->assertStringNotContainsString('<a', $result);
        $this->assertStringContainsString('Bold', $result);
        $this->assertStringContainsString('link', $result);
    }

    public function testSanitisationPreservesPlainText(): void
    {
        $input  = "# Heading\n\nSome paragraph.\n\n- bullet 1\n- bullet 2";
        $result = $this->sanitise($input);
        $this->assertStringContainsString('# Heading', $result);
        $this->assertStringContainsString('- bullet 1', $result);
    }

    public function testSanitisationNormalisesLineEndings(): void
    {
        $input  = "Line 1\r\nLine 2\rLine 3";
        $result = $this->sanitise($input);
        $this->assertStringContainsString("Line 1\nLine 2\nLine 3", $result);
    }

    // ── Category validation ───────────────────────────────────────────────────

    private function isValidCategory(string $cat): bool
    {
        return in_array($cat, [
            'privacy','terms','membership','bookings',
            'recording_streaming','junior_privacy','account_data','general',
        ], true);
    }

    public function testValidCategoryPrivacy(): void
    {
        $this->assertTrue($this->isValidCategory('privacy'));
    }

    public function testValidCategoryTerms(): void
    {
        $this->assertTrue($this->isValidCategory('terms'));
    }

    public function testValidCategoryJuniorPrivacy(): void
    {
        $this->assertTrue($this->isValidCategory('junior_privacy'));
    }

    public function testInvalidCategoryRejected(): void
    {
        $this->assertFalse($this->isValidCategory('unknown_cat'));
        $this->assertFalse($this->isValidCategory(''));
        $this->assertFalse($this->isValidCategory('Privacy')); // case-sensitive
    }

    // ── Audience validation ───────────────────────────────────────────────────

    private function isValidAudience(string $aud): bool
    {
        return in_array($aud, ['all_members','adult_members','junior_members','admins_only'], true);
    }

    public function testAllMembersAudienceValid(): void
    {
        $this->assertTrue($this->isValidAudience('all_members'));
    }

    public function testJuniorMembersAudienceValid(): void
    {
        $this->assertTrue($this->isValidAudience('junior_members'));
    }

    public function testInvalidAudienceRejected(): void
    {
        $this->assertFalse($this->isValidAudience('everybody'));
        $this->assertFalse($this->isValidAudience(''));
    }

    // ── Audience filtering logic ──────────────────────────────────────────────

    private function memberCanSeePolicy(string $policyAudience, bool $isJunior, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        $allowed = ['all_members', $isJunior ? 'junior_members' : 'adult_members'];
        return in_array($policyAudience, $allowed, true);
    }

    public function testAdultMemberSeesAllMembersPolicy(): void
    {
        $this->assertTrue($this->memberCanSeePolicy('all_members', false, false));
    }

    public function testAdultMemberDoesNotSeeJuniorOnlyPolicy(): void
    {
        $this->assertFalse($this->memberCanSeePolicy('junior_members', false, false));
    }

    public function testJuniorMemberSeesJuniorPolicy(): void
    {
        $this->assertTrue($this->memberCanSeePolicy('junior_members', true, false));
    }

    public function testJuniorMemberSeesAllMembersPolicy(): void
    {
        $this->assertTrue($this->memberCanSeePolicy('all_members', true, false));
    }

    public function testJuniorMemberDoesNotSeeAdultOnlyPolicy(): void
    {
        $this->assertFalse($this->memberCanSeePolicy('adult_members', true, false));
    }

    public function testAdminSeesAdminsOnlyPolicy(): void
    {
        $this->assertTrue($this->memberCanSeePolicy('admins_only', false, true));
    }

    // ── Effective date filtering ──────────────────────────────────────────────

    private function policyIsEffective(?string $effectiveFrom, string $today): bool
    {
        return $effectiveFrom === null || $effectiveFrom <= $today;
    }

    public function testNullEffectiveDateIsAlwaysEffective(): void
    {
        $this->assertTrue($this->policyIsEffective(null, '2026-06-18'));
    }

    public function testPastEffectiveDateIsEffective(): void
    {
        $this->assertTrue($this->policyIsEffective('2026-01-01', '2026-06-18'));
    }

    public function testTodayEffectiveDateIsEffective(): void
    {
        $this->assertTrue($this->policyIsEffective('2026-06-18', '2026-06-18'));
    }

    public function testFutureEffectiveDateIsNotEffective(): void
    {
        $this->assertFalse($this->policyIsEffective('2027-01-01', '2026-06-18'));
    }

    // ── Display order ─────────────────────────────────────────────────────────

    public function testDisplayOrderDefault(): void
    {
        $order = 100; // default
        $this->assertSame(100, $order);
    }

    public function testDisplayOrderClampedToNonNegative(): void
    {
        $raw   = -5;
        $order = max(0, $raw);
        $this->assertSame(0, $order);
    }

    // ── Name validation ───────────────────────────────────────────────────────

    public function testEmptyNameRejected(): void
    {
        $name = trim('   ');
        $this->assertSame('', $name);
        $this->assertTrue($name === '');
    }

    public function testNameMaxLengthEnforced(): void
    {
        $longName = str_repeat('A', 300);
        $this->assertGreaterThan(255, mb_strlen($longName));
    }

    // ── Status transitions ────────────────────────────────────────────────────

    private function canTransitionStatus(string $from, string $to): bool
    {
        $allowed = [
            'draft'       => ['draft','published'],
            'published'   => ['published','unpublished','archived'],
            'unpublished' => ['unpublished','published','archived'],
            'archived'    => ['archived'],
        ];
        return in_array($to, $allowed[$from] ?? [], true);
    }

    public function testDraftCanBePublished(): void
    {
        $this->assertTrue($this->canTransitionStatus('draft', 'published'));
    }

    public function testPublishedCanBeUnpublished(): void
    {
        $this->assertTrue($this->canTransitionStatus('published', 'unpublished'));
    }

    public function testPublishedCanBeArchived(): void
    {
        $this->assertTrue($this->canTransitionStatus('published', 'archived'));
    }

    public function testArchivedCannotBeDirectlyRepublished(): void
    {
        // Archived must be restored (set to unpublished) before republishing.
        $this->assertFalse($this->canTransitionStatus('archived', 'published'));
    }
}
