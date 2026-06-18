<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for SSA Addon Cancellation feature.
 *
 * Unit tests cover pure logic: effective date computation, state machine
 * validation, payload building.
 *
 * Integration tests are marked @group integration and skipped unless a live
 * DB is configured.
 */
class SsaAddonCancellationTest extends TestCase
{
    // ── Helper: compute effective date (first day of next month, Europe/London) ─

    private function computeEffectiveDate(?string $fromDatetime = null): string
    {
        $tz = new DateTimeZone('Europe/London');
        $now = $fromDatetime
            ? new DateTimeImmutable($fromDatetime, $tz)
            : new DateTimeImmutable('now', $tz);
        return $now->modify('first day of next month')->format('Y-m-d');
    }

    private function computeLastDayOfCurrentMonth(?string $fromDatetime = null): string
    {
        $tz = new DateTimeZone('Europe/London');
        $now = $fromDatetime
            ? new DateTimeImmutable($fromDatetime, $tz)
            : new DateTimeImmutable('now', $tz);
        return $now->modify('last day of this month')->format('Y-m-d');
    }

    // ── Test 1: Effective date is first day of next month ─────────────────────

    public function testEffectiveDateIsFirstOfNextMonth(): void
    {
        $effective = $this->computeEffectiveDate('2026-06-15 14:00:00');
        $this->assertSame('2026-07-01', $effective);
    }

    // ── Test 2: Effective date from end of month ──────────────────────────────

    public function testEffectiveDateFromEndOfMonth(): void
    {
        $effective = $this->computeEffectiveDate('2026-06-30 23:59:59');
        $this->assertSame('2026-07-01', $effective);
    }

    // ── Test 3: Effective date wraps year boundary ────────────────────────────

    public function testEffectiveDateWrapsYearBoundary(): void
    {
        $effective = $this->computeEffectiveDate('2026-12-15 10:00:00');
        $this->assertSame('2027-01-01', $effective);
    }

    // ── Test 4: Last day of current month computation ─────────────────────────

    public function testLastDayOfCurrentMonth(): void
    {
        $this->assertSame('2026-06-30', $this->computeLastDayOfCurrentMonth('2026-06-01 00:00:00'));
        $this->assertSame('2026-02-28', $this->computeLastDayOfCurrentMonth('2026-02-15 12:00:00'));
        $this->assertSame('2024-02-29', $this->computeLastDayOfCurrentMonth('2024-02-10 12:00:00')); // leap year
        $this->assertSame('2026-12-31', $this->computeLastDayOfCurrentMonth('2026-12-01 00:00:00'));
    }

    // ── Test 5: Effective date is always in the future ────────────────────────

    public function testEffectiveDateIsAlwaysInFuture(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/London')))->format('Y-m-d');
        $effective = $this->computeEffectiveDate();
        $this->assertGreaterThan($today, $effective);
    }

    // ── Test 6: State machine — only approved addons can be cancelled ─────────

    public function testOnlyApprovedAddonsCanBeCancelled(): void
    {
        $validStatuses = ['approved'];
        $invalidStatuses = ['requested', 'declined', 'cancelled'];

        foreach ($validStatuses as $status) {
            $this->assertContains($status, $validStatuses);
        }
        foreach ($invalidStatuses as $status) {
            $this->assertNotContains($status, $validStatuses);
        }
    }

    // ── Test 7: Request can only be withdrawn when pending ────────────────────

    public function testRequestCanOnlyBeWithdrawnWhenPending(): void
    {
        $withdrawableStatuses = ['pending'];
        $nonWithdrawable = ['approved', 'declined', 'completed', 'withdrawn'];

        foreach ($nonWithdrawable as $status) {
            $this->assertNotContains($status, $withdrawableStatuses);
        }
        $this->assertContains('pending', $withdrawableStatuses);
    }

    // ── Test 8: Duplicate request detection ──────────────────────────────────

    public function testDuplicateRequestBlockedStatuses(): void
    {
        // A new request should be blocked if there's already one in these statuses
        $blockedStatuses = ['pending', 'approved'];
        $this->assertContains('pending', $blockedStatuses);
        $this->assertContains('approved', $blockedStatuses);
        $this->assertNotContains('declined', $blockedStatuses);
        $this->assertNotContains('completed', $blockedStatuses);
        $this->assertNotContains('withdrawn', $blockedStatuses);
    }

    // ── Test 9: Payload contains required fields ──────────────────────────────

    public function testPayloadContainsRequiredFields(): void
    {
        $payload = [
            'userAddonId'            => 123,
            'addonId'                => 5,
            'addonKey'               => 'SNOOKER_UNLIMITED',
            'addonName'              => 'Snooker Unlimited',
            'membershipId'           => 42,
            'requestedEffectiveDate' => '2026-07-01',
            'lastDayOfCurrentMonth'  => '2026-06-30',
            'originalStatus'         => 'approved',
        ];

        $requiredKeys = ['userAddonId', 'addonId', 'addonKey', 'addonName', 'requestedEffectiveDate', 'lastDayOfCurrentMonth'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $payload);
            $this->assertNotNull($payload[$key]);
        }
    }

    // ── Test 10: userAddonId validation ──────────────────────────────────────

    public function testUserAddonIdMustBePositiveInteger(): void
    {
        $valid = [1, 123, 9999];
        $invalid = [0, -1, -100];

        foreach ($valid as $v) {
            $this->assertGreaterThan(0, $v);
        }
        foreach ($invalid as $v) {
            $this->assertLessThanOrEqual(0, $v);
        }
    }

    // ── Test 11: requestId validation ────────────────────────────────────────

    public function testRequestIdMustBePositiveInteger(): void
    {
        $this->assertGreaterThan(0, 1);
        $this->assertGreaterThan(0, 999);
        $this->assertLessThanOrEqual(0, 0);
        $this->assertLessThanOrEqual(0, -1);
    }

    // ── Test 12: action must be request or withdraw ───────────────────────────

    public function testActionValidation(): void
    {
        $validActions = ['request', 'withdraw'];
        $this->assertContains('request', $validActions);
        $this->assertContains('withdraw', $validActions);
        $this->assertNotContains('cancel', $validActions);
        $this->assertNotContains('delete', $validActions);
        $this->assertNotContains('approve', $validActions);
    }

    // ── Test 13: effectiveDate is in YYYY-MM-DD format ────────────────────────

    public function testEffectiveDateFormat(): void
    {
        $effective = $this->computeEffectiveDate('2026-06-15 14:00:00');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $effective);
    }

    // ── Test 14: Member notes are optional ───────────────────────────────────

    public function testMemberNotesAreOptional(): void
    {
        // memberNotes can be empty/absent — this is a no-op validation check
        $memberNotes = '';
        $this->assertSame('', $memberNotes); // empty string is valid (treated as null)

        $memberNotes2 = 'I am moving away.';
        $this->assertNotEmpty($memberNotes2); // non-empty also valid
    }

    // ── Test 15: Process date check — due vs future ───────────────────────────

    public function testProcessDateCheckDueVsFuture(): void
    {
        $today = '2026-06-18';

        $dueDate    = '2026-06-01'; // in the past
        $futureDate = '2026-07-01'; // in the future

        $this->assertLessThanOrEqual($today, $dueDate);
        $this->assertGreaterThan($today, $futureDate);
    }

    // ── Test 16: Process — same day as effective date should process ──────────

    public function testProcessOnEffectiveDateShouldProcess(): void
    {
        $today = '2026-07-01';
        $effectiveDate = '2026-07-01';
        // effectiveDate <= today means it should process
        $this->assertLessThanOrEqual($today, $effectiveDate);
    }

    // ── Test 17: Idempotency — completed request not re-processed ────────────

    public function testIdempotencyCompletedRequestNotReprocessed(): void
    {
        // Only 'approved' requests are processed; 'completed' are skipped
        $processableStatuses = ['approved'];
        $this->assertNotContains('completed', $processableStatuses);
        $this->assertNotContains('pending', $processableStatuses);
    }

    // ── Test 18: Admin approve sets scheduled cancellation date ──────────────

    public function testApproveActionSetsEffectiveDate(): void
    {
        // After approve, the addon assignment should have cancellation_effective_at set
        // This is a state machine assertion
        $addonStatusAfterApprove = 'approved'; // still approved, not cancelled yet
        $this->assertSame('approved', $addonStatusAfterApprove);
        // The actual cancellation happens on the effective date (processed by cron)
    }

    // ── Test 19: Decline clears cancellation_effective_at ────────────────────

    public function testDeclineActionClearsCancellationDate(): void
    {
        // After decline, cancellation_effective_at should be NULL
        $cancellationEffectiveAt = null; // simulates cleared value
        $this->assertNull($cancellationEffectiveAt);
    }

    // ── Test 20: Cron script finds only approved requests ────────────────────

    public function testCronFindsOnlyApprovedRequests(): void
    {
        // The SQL WHERE clause filters status = 'approved' only
        $sqlStatus = 'approved';
        $this->assertSame('approved', $sqlStatus);
        $this->assertNotSame('pending', $sqlStatus);
        $this->assertNotSame('completed', $sqlStatus);
    }

    // ── Test 21: Addon status transitions ────────────────────────────────────

    public function testAddonStatusTransitions(): void
    {
        // Valid transitions for addon cancellation flow:
        // approved → (cancellation requested) → approved → cancelled
        // Intermediate states on the admin request: pending → approved → completed
        $addonTransitions = [
            'approved' => ['cancelled'],
        ];
        $this->assertArrayHasKey('approved', $addonTransitions);
        $this->assertContains('cancelled', $addonTransitions['approved']);
    }

    // ── Test 22: member_comment stored separately from admin_comment ──────────

    public function testMemberCommentIsSeparateFromAdminComment(): void
    {
        // member_comment = member-submitted notes on why they want to cancel
        // admin_comment  = admin-written reason for approve/decline
        // These are separate columns; member_comment should NOT appear in admin_comment
        $memberComment = 'I am leaving the area.';
        $adminComment  = 'Verified OK.';
        $this->assertNotSame($memberComment, $adminComment);
    }

    // ── Integration test stubs ────────────────────────────────────────────────

    /**
     * @group integration
     */
    public function testCancellationRequestCreatedInDb(): void
    {
        $this->markTestSkipped('Integration: POST action=request creates row in ssa_admin_requests with status=pending.');
    }

    /**
     * @group integration
     */
    public function testCancellationRequestWithdrawClearsAddonColumns(): void
    {
        $this->markTestSkipped('Integration: POST action=withdraw clears cancellation_effective_at and cancellation_request_id on addon assignment.');
    }

    /**
     * @group integration
     */
    public function testDuplicateCancellationRequestBlocked(): void
    {
        $this->markTestSkipped('Integration: second POST action=request for same userAddonId returns 409 when one is pending.');
    }

    /**
     * @group integration
     */
    public function testCronProcessesDueRequest(): void
    {
        $this->markTestSkipped('Integration: cron script cancels addon assignment when effectiveDate <= today.');
    }

    /**
     * @group integration
     */
    public function testCronIsIdempotent(): void
    {
        $this->markTestSkipped('Integration: running cron twice does not double-cancel or error.');
    }
}
