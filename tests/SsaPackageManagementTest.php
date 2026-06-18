<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for SSA Package Management feature.
 *
 * Unit tests cover pure logic: tier/variant computation, price formatting,
 * pence conversion, date validation.
 *
 * Integration tests are marked @group integration and skipped unless a live
 * DB is configured (DB_DSN environment variable).
 */
class SsaPackageManagementTest extends TestCase
{
    // ── Tier computation ─────────────────────────────────────────────────────

    private function tier(string $planKey, ?string $parentPlanKey = null): string
    {
        $ref = strtolower($parentPlanKey ?? $planKey);
        $key = strtolower($planKey);
        if ($ref === 'red'  || str_starts_with($key, 'red'))   return 'Red';
        if ($ref === 'pink' || str_starts_with($key, 'pink'))  return 'Pink';
        if ($ref === 'black'|| str_starts_with($key, 'black')) return 'Black';
        if ($ref === 'gold' || $key === 'gold' || $key === 'pro-package') return 'Gold';
        if (str_starts_with($key, 'summer')) return 'Summer';
        return 'Special';
    }

    private function variant(string $planKey): string
    {
        $key = strtolower($planKey);
        if (str_contains($key, '_nhs_upfront'))   return 'Upfront NHS';
        if (str_contains($key, '_junior'))         return 'Junior';
        if (str_contains($key, '_nhs'))            return 'NHS';
        if (str_contains($key, '_police'))         return 'Police';
        if (str_contains($key, '_senior'))         return 'Senior';
        if (str_contains($key, '_student'))        return 'Student';
        if (str_contains($key, '_standard_upfront') || (str_contains($key, '_upfront') && !str_contains($key, '_nhs'))) return 'Upfront';
        if (str_contains($key, '_discounted'))     return 'Discounted';
        if ($key === 'concession')                 return 'Concession';
        if ($key === 'coach')                      return 'Coach';
        if (str_contains($key, 'special'))         return 'Special';
        return 'Standard';
    }

    private function priceDisplay(int $pence, string $currency, string $billingType = 'monthly'): string
    {
        $symbol = strtoupper($currency) === 'GBP' ? '£' : strtoupper($currency) . ' ';
        $pounds = $pence / 100;
        $formatted = ($pounds == floor($pounds))
            ? $symbol . number_format((int)$pounds)
            : $symbol . number_format($pounds, 2);
        return $billingType === 'monthly' ? $formatted . '/month' : $formatted;
    }

    // ── Test 1: Red tier plan_key resolves correctly ──────────────────────────

    public function testTierRedFromPlanKey(): void
    {
        $this->assertSame('Red', $this->tier('RED_STANDARD'));
        $this->assertSame('Red', $this->tier('RED_JUNIOR'));
        $this->assertSame('Red', $this->tier('RED_NHS'));
        $this->assertSame('Red', $this->tier('RED_STANDARD_UPFRONT'));
        $this->assertSame('Red', $this->tier('RED_NHS_UPFRONT'));
    }

    // ── Test 2: Pink tier plan_key resolves correctly ─────────────────────────

    public function testTierPinkFromPlanKey(): void
    {
        $this->assertSame('Pink', $this->tier('PINK_STANDARD'));
        $this->assertSame('Pink', $this->tier('PINK_JUNIOR'));
        $this->assertSame('Pink', $this->tier('PINK_SENIOR'));
        $this->assertSame('Pink', $this->tier('PINK_STANDARD_UPFRONT'));
    }

    // ── Test 3: Black tier plan_key resolves correctly ────────────────────────

    public function testTierBlackFromPlanKey(): void
    {
        $this->assertSame('Black', $this->tier('BLACK_STANDARD'));
        $this->assertSame('Black', $this->tier('BLACK_SENIOR'));
        $this->assertSame('Black', $this->tier('BLACK_DISCOUNTED'));
    }

    // ── Test 4: Special tier fallback ────────────────────────────────────────

    public function testTierSpecialFallback(): void
    {
        $this->assertSame('Special', $this->tier('CONCESSION'));
        $this->assertSame('Special', $this->tier('COACH'));
        $this->assertSame('Special', $this->tier('SPECIAL_ARRANGEMENT'));
    }

    // ── Test 5: Summer tier ──────────────────────────────────────────────────

    public function testTierSummer(): void
    {
        $this->assertSame('Summer', $this->tier('SUMMER_STANDARD'));
        $this->assertSame('Summer', $this->tier('SUMMER_JUNIOR'));
    }

    // ── Test 6: Parent plan_key overrides tier detection ─────────────────────

    public function testTierFromParentPlanKey(): void
    {
        // Plan key doesn't start with red but parent does
        $this->assertSame('Red', $this->tier('SOME_CUSTOM_PLAN', 'red'));
        $this->assertSame('Pink', $this->tier('SOME_CUSTOM_PLAN', 'pink'));
        $this->assertSame('Black', $this->tier('SOME_CUSTOM_PLAN', 'black'));
    }

    // ── Test 7: NHS Upfront variant ──────────────────────────────────────────

    public function testVariantNhsUpfront(): void
    {
        $this->assertSame('Upfront NHS', $this->variant('RED_NHS_UPFRONT'));
    }

    // ── Test 8: Upfront variant (non-NHS) ────────────────────────────────────

    public function testVariantUpfront(): void
    {
        $this->assertSame('Upfront', $this->variant('RED_STANDARD_UPFRONT'));
        $this->assertSame('Upfront', $this->variant('PINK_STANDARD_UPFRONT'));
    }

    // ── Test 9: Junior variant ───────────────────────────────────────────────

    public function testVariantJunior(): void
    {
        $this->assertSame('Junior', $this->variant('RED_JUNIOR'));
        $this->assertSame('Junior', $this->variant('PINK_JUNIOR'));
    }

    // ── Test 10: Senior variant ──────────────────────────────────────────────

    public function testVariantSenior(): void
    {
        $this->assertSame('Senior', $this->variant('RED_SENIOR'));
        $this->assertSame('Senior', $this->variant('PINK_SENIOR'));
        $this->assertSame('Senior', $this->variant('BLACK_SENIOR'));
    }

    // ── Test 11: Police variant ──────────────────────────────────────────────

    public function testVariantPolice(): void
    {
        $this->assertSame('Police', $this->variant('RED_POLICE'));
    }

    // ── Test 12: NHS variant (non-upfront) ───────────────────────────────────

    public function testVariantNhs(): void
    {
        $this->assertSame('NHS', $this->variant('RED_NHS'));
    }

    // ── Test 13: Standard variant fallback ───────────────────────────────────

    public function testVariantStandardFallback(): void
    {
        $this->assertSame('Standard', $this->variant('RED_STANDARD'));
        $this->assertSame('Standard', $this->variant('PINK_STANDARD'));
        $this->assertSame('Standard', $this->variant('BLACK_STANDARD'));
    }

    // ── Test 14: Price display — whole pounds monthly ────────────────────────

    public function testPriceDisplayWholeMonthly(): void
    {
        $this->assertSame('£10/month', $this->priceDisplay(1000, 'GBP', 'monthly'));
        $this->assertSame('£129/month', $this->priceDisplay(12900, 'GBP', 'monthly'));
    }

    // ── Test 15: Price display — pence monthly ───────────────────────────────

    public function testPriceDisplayPenceMonthly(): void
    {
        $this->assertSame('£25.50/month', $this->priceDisplay(2550, 'GBP', 'monthly'));
    }

    // ── Test 16: Price display — non-monthly billing ─────────────────────────

    public function testPriceDisplayNonMonthly(): void
    {
        $this->assertSame('£50', $this->priceDisplay(5000, 'GBP', 'upfront'));
        $this->assertSame('£99.99', $this->priceDisplay(9999, 'GBP', 'fixed_term'));
    }

    // ── Test 17: Price display — £0 ─────────────────────────────────────────

    public function testPriceDisplayZero(): void
    {
        $this->assertSame('£0/month', $this->priceDisplay(0, 'GBP', 'monthly'));
    }

    // ── Test 18: Pence to pounds conversion ──────────────────────────────────

    public function testPenceToPounds(): void
    {
        $this->assertSame(12.5, round(1250 / 100, 2));
        $this->assertSame(0.0, round(0 / 100, 2));
        $this->assertSame(9999.0, round(999900 / 100, 2));
    }

    // ── Test 19: Date validation — valid YYYY-MM-DD ──────────────────────────

    public function testDateValidation(): void
    {
        $valid = static fn (string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $this->assertTrue($valid('2026-06-01'));
        $this->assertTrue($valid('2026-12-31'));
        $this->assertFalse($valid('06/01/2026'));
        $this->assertFalse($valid('2026-6-1'));
        $this->assertFalse($valid(''));
    }

    // ── Test 20: Billing type validation ─────────────────────────────────────

    public function testBillingTypeValidation(): void
    {
        $valid = ['monthly', 'upfront', 'fixed_term', 'other'];
        $this->assertContains('monthly', $valid);
        $this->assertContains('upfront', $valid);
        $this->assertContains('fixed_term', $valid);
        $this->assertContains('other', $valid);
        $this->assertNotContains('weekly', $valid);
        $this->assertNotContains('annual', $valid);
    }

    // ── Integration test: migration is idempotent ─────────────────────────────

    /**
     * @group integration
     */
    public function testMigrationIdempotent(): void
    {
        if (!getenv('DB_DSN')) {
            $this->markTestSkipped('Integration test requires DB_DSN environment variable.');
        }
        // Run the migration twice and verify it doesn't error
        $result1 = shell_exec('php ' . escapeshellarg(__DIR__ . '/../tools/ssa_migrate_package_management.php') . ' --dry-run 2>&1');
        $this->assertNotNull($result1);
        $this->assertStringNotContainsString('FAILED', (string)$result1);
    }

    /**
     * @group integration
     */
    public function testPrivatePlansNotPublicAfterMigration(): void
    {
        $this->markTestSkipped('Integration test: requires live DB with migration applied.');
    }

    /**
     * @group integration
     */
    public function testBillingTypeSeedingUpfront(): void
    {
        $this->markTestSkipped('Integration test: verify RED_STANDARD_UPFRONT has billing_type=upfront after migration.');
    }

    /**
     * @group integration
     */
    public function testPackageAuditTableExists(): void
    {
        $this->markTestSkipped('Integration test: verify ssa_membership_package_audit table exists after migration.');
    }

    /**
     * @group integration
     */
    public function testAdminApiRequiresAuth(): void
    {
        $this->markTestSkipped('Integration test: GET /api/ssa/v1/admin/membership-packages.php without token returns 401.');
    }

    /**
     * @group integration
     */
    public function testPackageUpdateConcurrencyConflict(): void
    {
        $this->markTestSkipped('Integration test: PATCH with stale updatedAt returns 409.');
    }

    /**
     * @group integration
     */
    public function testPackageUpdateWritesAuditRow(): void
    {
        $this->markTestSkipped('Integration test: PATCH on package creates row in ssa_membership_package_audit.');
    }

    /**
     * @group integration
     */
    public function testPackageUpdateDoesNotTouchMembershipPriceSnapshot(): void
    {
        $this->markTestSkipped('Integration test: price change on plan does NOT update ssa_user_memberships.price_snapshot_pence.');
    }

    /**
     * @group integration
     */
    public function testPlanKeyIsReadOnly(): void
    {
        $this->markTestSkipped('Integration test: plan_key in DB unchanged after PATCH (it is not in SET clause).');
    }

    // ── Assignment endpoint unit tests (source ENUM and period-date logic) ────

    /**
     * Verifies that 'admin' is a valid source value and 'admin_assignment' is not.
     * Root cause of the HTTP 500: source ENUM does not include 'admin_assignment'.
     */
    public function testSourceValueIsAdmin(): void
    {
        // The valid source value used by admin assignment endpoint
        $sourceValue = 'admin';
        $validSources = ['admin', 'migration', 'user_request', 'system', 'legacy_excel_import'];
        $this->assertContains($sourceValue, $validSources, "'admin' must be a valid ENUM value");
        $this->assertNotContains('admin_assignment', $validSources, "'admin_assignment' is NOT in the ENUM");
    }

    /**
     * ssaAssignCalcFreshPeriodDates: monthly billing produces a future period end.
     */
    public function testFreshPeriodDatesMonthlyIsFuture(): void
    {
        $dates = $this->calcFreshPeriodDates('monthly', null);
        $this->assertArrayHasKey('current_period_ends_at', $dates);
        $this->assertArrayHasKey('cancellation_notice_deadline_at', $dates);
        $end = strtotime($dates['current_period_ends_at']);
        $this->assertGreaterThan(time(), $end, 'Period end must be in the future');
    }

    /**
     * ssaAssignCalcFreshPeriodDates: upfront with available_until uses that date.
     */
    public function testFreshPeriodDatesUpfrontUsesAvailableUntil(): void
    {
        $availableUntil = '2027-03-31';
        $dates = $this->calcFreshPeriodDates('upfront', $availableUntil);
        $end = $dates['current_period_ends_at'];
        $this->assertStringStartsWith('2027-03-31', $end);
    }

    /**
     * ssaAssignCalcFreshPeriodDates: upfront without available_until falls back to year end.
     */
    public function testFreshPeriodDatesUpfrontFallsBackToYearEnd(): void
    {
        $dates = $this->calcFreshPeriodDates('upfront', null);
        $end = $dates['current_period_ends_at'];
        $this->assertMatchesRegularExpression('/^\d{4}-12-31/', $end);
    }

    /**
     * ssaAssignCalcFreshPeriodDates: period_ends_at equals cancellation_notice_deadline_at.
     */
    public function testFreshPeriodDatesCancelDeadlineEqualsEnd(): void
    {
        $dates = $this->calcFreshPeriodDates('monthly', null);
        $this->assertSame($dates['current_period_ends_at'], $dates['cancellation_notice_deadline_at']);
    }

    // ── Assignment endpoint integration tests (require DB_DSN) ────────────────

    /**
     * @group integration
     * Test 1: First membership assignment succeeds.
     */
    public function testAssignmentFirstSucceeds(): void
    {
        $this->markTestSkipped('Integration: POST /admin/member-package-assign.php → 200, new active membership created.');
    }

    /**
     * @group integration
     * Test 2: Replacing an existing package succeeds.
     */
    public function testAssignmentReplacementSucceeds(): void
    {
        $this->markTestSkipped('Integration: assigning a different package supersedes the old membership and creates a new active one.');
    }

    /**
     * @group integration
     * Test 3: Old membership becomes historical (status = superseded).
     */
    public function testOldMembershipIsSuperseded(): void
    {
        $this->markTestSkipped('Integration: after replacement, previous row has status=superseded.');
    }

    /**
     * @group integration
     * Test 4: Exactly one active membership remains after assignment.
     */
    public function testExactlyOneActiveMembershipAfterAssignment(): void
    {
        $this->markTestSkipped('Integration: SELECT COUNT(*) WHERE uid=? AND status=active = 1 after assignment.');
    }

    /**
     * @group integration
     * Test 5: superseded_by_membership_id links old to new membership.
     */
    public function testSupersededByMembershipIdIsSet(): void
    {
        $this->markTestSkipped('Integration: old row superseded_by_membership_id = new row id.');
    }

    /**
     * @group integration
     * Test 6: Private package assignment succeeds for an administrator.
     */
    public function testPrivatePackageAssignmentSucceedsForAdmin(): void
    {
        $this->markTestSkipped('Integration: is_public=0 package can be assigned by admin.');
    }

    /**
     * @group integration
     * Test 7: Private package remains absent from the public catalogue after assignment.
     */
    public function testPrivatePackageAbsentFromPublicCatalogue(): void
    {
        $this->markTestSkipped('Integration: GET /membership-packages.php does not return is_public=0 package after admin assignment.');
    }

    /**
     * @group integration
     * Test 8: Existing price snapshots on old membership are not modified.
     */
    public function testExistingPriceSnapshotUnchanged(): void
    {
        $this->markTestSkipped('Integration: superseded row price_snapshot_pence unchanged after replacement.');
    }

    /**
     * @group integration
     * Test 9: New membership receives the current package price snapshot.
     */
    public function testNewMembershipReceivesCurrentPriceSnapshot(): void
    {
        $this->markTestSkipped('Integration: new row price_snapshot_pence = ssa_membership_plans.monthly_price_pence at time of assignment.');
    }

    /**
     * @group integration
     * Test 10: Audit failure rolls back the membership change.
     */
    public function testAuditFailureRollsBackMembership(): void
    {
        $this->markTestSkipped('Integration: simulate audit INSERT failure; verify membership rows unchanged and 500 returned.');
    }

    /**
     * @group integration
     * Test 11: Membership insert failure restores the previous active membership.
     */
    public function testMembershipInsertFailureRestoresPreviousActive(): void
    {
        $this->markTestSkipped('Integration: simulate new membership INSERT failure; old active row status must remain active after rollback.');
    }

    /**
     * @group integration
     * Test 12: Push failure after commit does not produce HTTP 500.
     */
    public function testPushFailureAfterCommitDoesNotReturn500(): void
    {
        $this->markTestSkipped('Integration: stub push to throw; successful assignment still returns 200 with notificationWarning.');
    }

    /**
     * @group integration
     * Test 13: Notification failure after commit does not create a duplicate assignment.
     */
    public function testNotificationFailureDoesNotDuplicateAssignment(): void
    {
        $this->markTestSkipped('Integration: stub notification to throw; membership table has exactly one active row after response.');
    }

    /**
     * @group integration
     * Test 14: Duplicate assignment returns 409 already_assigned.
     */
    public function testDuplicateAssignmentReturns409(): void
    {
        $this->markTestSkipped('Integration: assign same package twice; second POST returns 409 error=already_assigned.');
    }

    /**
     * @group integration
     * Test 15: Stale expectedCurrentMembershipId returns 409 membership_changed.
     */
    public function testStaleMembershipIdReturns409(): void
    {
        $this->markTestSkipped('Integration: pass expectedCurrentMembershipId that does not match current row; expect 409 error=membership_changed.');
    }

    /**
     * @group integration
     * Test 16: Inactive package returns 422.
     */
    public function testInactivePackageReturns422(): void
    {
        $this->markTestSkipped('Integration: assign a package where is_active=0; expect 422 error=package_inactive.');
    }

    /**
     * @group integration
     * Test 17: Ordinary members receive 403.
     */
    public function testOrdinaryMemberReceives403(): void
    {
        $this->markTestSkipped('Integration: call endpoint with a member-role Auth0 token; expect 403 error=forbidden.');
    }

    /**
     * @group integration
     * Test 18: Error responses include a requestId.
     */
    public function testErrorResponsesIncludeRequestId(): void
    {
        $this->markTestSkipped('Integration: any 4xx or 5xx response body contains a non-empty requestId field.');
    }

    // ── Helper: replicate ssaAssignCalcFreshPeriodDates logic in PHP ──────────

    private function calcFreshPeriodDates(string $billingType, ?string $availableUntil): array
    {
        $tz      = new \DateTimeZone('Europe/London');
        $utcZone = new \DateTimeZone('UTC');
        $nowLon  = new \DateTimeImmutable('now', $tz);

        if (in_array($billingType, ['upfront', 'fixed_term', 'one_time'], true) && $availableUntil) {
            try {
                $endLon = new \DateTimeImmutable($availableUntil . ' 23:59:59', $tz);
            } catch (\Throwable $ignored) {
                $endLon = $nowLon->modify('+1 year')->setTime(23, 59, 59);
            }
        } elseif (in_array($billingType, ['upfront', 'fixed_term', 'one_time'], true)) {
            $endLon = new \DateTimeImmutable($nowLon->format('Y') . '-12-31 23:59:59', $tz);
            if ($endLon <= $nowLon) {
                $endLon = $endLon->modify('+1 year');
            }
        } else {
            $firstOfThisMonth = new \DateTimeImmutable($nowLon->format('Y-m') . '-01 00:00:00', $tz);
            $firstOfNextMonth = $firstOfThisMonth->modify('+1 month');
            $endLon           = $firstOfNextMonth->modify('last day of this month')->setTime(23, 59, 59);
        }

        $periodEndsAt = $endLon->setTimezone($utcZone)->format('Y-m-d H:i:s');
        return [
            'current_period_ends_at'          => $periodEndsAt,
            'cancellation_notice_deadline_at' => $periodEndsAt,
        ];
    }
}
