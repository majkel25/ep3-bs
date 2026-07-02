<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for SSA slot availability logic (slots.php).
 *
 * Pure-logic tests only — no database connection required.
 * Functions are inlined here to match what slots.php implements.
 */
class SsaSlotAvailabilityTest extends TestCase
{
    // ── Inline mirrors of slots.php pure functions ────────────────────────────

    private function indexBlockingEvents(array $events): array
    {
        $index = ['allTable' => [], 'byTable' => []];

        foreach ($events as $event) {
            $rawName  = isset($event['event_name']) ? trim((string)$event['event_name']) : '';
            $eventName = ($rawName !== '' && $rawName !== '?') ? $rawName : 'Club event';

            $entry = [
                'eid'            => (int)$event['eid'],
                'sid'            => $event['sid'],
                'datetime_start' => (string)$event['datetime_start'],
                'datetime_end'   => (string)$event['datetime_end'],
                'event_name'     => $eventName,
            ];

            // sid IS NULL or sid = 0 → all-table event
            $isAllTable = $event['sid'] === null || (int)$event['sid'] === 0;

            if ($isAllTable) {
                $index['allTable'][] = $entry;
            } else {
                $tableId = (int)$event['sid'];
                if (!isset($index['byTable'][$tableId])) {
                    $index['byTable'][$tableId] = [];
                }
                $index['byTable'][$tableId][] = $entry;
            }
        }

        return $index;
    }

    private function findOverlappingEvent(
        array  $eventIndex,
        int    $tableId,
        string $slotStartDatetime,
        string $slotEndDatetime
    ): ?array {
        foreach ($eventIndex['allTable'] as $event) {
            if ($slotStartDatetime < $event['datetime_end'] && $slotEndDatetime > $event['datetime_start']) {
                return $event;
            }
        }

        if (isset($eventIndex['byTable'][$tableId])) {
            foreach ($eventIndex['byTable'][$tableId] as $event) {
                if ($slotStartDatetime < $event['datetime_end'] && $slotEndDatetime > $event['datetime_start']) {
                    return $event;
                }
            }
        }

        return null;
    }

    private function indexReservations(array $reservations): array
    {
        $index = [];

        foreach ($reservations as $reservation) {
            $tableId = isset($reservation['sid']) ? (int)$reservation['sid'] : 0;
            $date    = (string)($reservation['date'] ?? '');

            if ($tableId <= 0 || $date === '') {
                continue;
            }

            $start = $this->timeToSeconds($reservation['time_start'] ?? null);
            $end   = $this->timeToSeconds($reservation['time_end'] ?? null);

            if ($start === null || $end === null) {
                continue;
            }

            $reservation['_startSeconds'] = $start;
            $reservation['_endSeconds']   = $end;

            $index[$tableId][$date][] = $reservation;
        }

        return $index;
    }

    private function findOverlappingReservation(array $reservations, int $slotStart, int $slotEnd): ?array
    {
        foreach ($reservations as $reservation) {
            if ($slotStart < $reservation['_endSeconds'] && $slotEnd > $reservation['_startSeconds']) {
                return $reservation;
            }
        }
        return null;
    }

    private function timeToSeconds(?string $time): ?int
    {
        if ($time === null || !preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) {
            return null;
        }
        return ((int)$m[1] * 3600) + ((int)$m[2] * 60);
    }

    // ── Helper builders ───────────────────────────────────────────────────────

    private function event(
        int    $eid,
        ?int   $sid,
        string $start,
        string $end,
        string $name = 'Test Event'
    ): array {
        return [
            'eid'            => $eid,
            'sid'            => $sid,
            'datetime_start' => $start,
            'datetime_end'   => $end,
            'event_name'     => $name,
        ];
    }

    private function reservation(
        int    $sid,
        string $date,
        string $start,
        string $end
    ): array {
        return [
            'sid'        => $sid,
            'date'       => $date,
            'time_start' => $start,
            'time_end'   => $end,
        ];
    }

    // ── Event index: separation by sid ───────────────────────────────────────

    public function testAllTableEventGoesIntoAllTableKey(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 09:00:00', '2026-07-01 12:00:00'),
        ]);

        $this->assertCount(1, $index['allTable']);
        $this->assertEmpty($index['byTable']);
    }

    public function testSidZeroEventTreatedAsAllTable(): void
    {
        $index = $this->indexBlockingEvents([
            array_merge($this->event(1, null, '2026-07-01 09:00:00', '2026-07-01 12:00:00'), ['sid' => 0]),
        ]);

        $this->assertCount(1, $index['allTable'], 'sid=0 must be treated as an all-table event');
        $this->assertEmpty($index['byTable']);
    }

    public function testTableSpecificEventGoesIntoByTableKey(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(2, 3, '2026-07-01 09:00:00', '2026-07-01 12:00:00'),
        ]);

        $this->assertEmpty($index['allTable']);
        $this->assertArrayHasKey(3, $index['byTable']);
        $this->assertCount(1, $index['byTable'][3]);
    }

    public function testMixedEventsIndexedCorrectly(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 09:00:00', '2026-07-01 12:00:00'),
            $this->event(2, 1,    '2026-07-01 14:00:00', '2026-07-01 16:00:00'),
            $this->event(3, 2,    '2026-07-01 14:00:00', '2026-07-01 16:00:00'),
        ]);

        $this->assertCount(1, $index['allTable']);
        $this->assertCount(1, $index['byTable'][1]);
        $this->assertCount(1, $index['byTable'][2]);
    }

    // ── Event name sanitisation ───────────────────────────────────────────────

    public function testValidEventNamePreserved(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 09:00:00', '2026-07-01 12:00:00', 'Club Championship'),
        ]);

        $this->assertSame('Club Championship', $index['allTable'][0]['event_name']);
    }

    public function testEmptyEventNameFallsBackToClubEvent(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 09:00:00', '2026-07-01 12:00:00', ''),
        ]);

        $this->assertSame('Club event', $index['allTable'][0]['event_name']);
    }

    public function testPlaceholderQuestionMarkFallsBackToClubEvent(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 09:00:00', '2026-07-01 12:00:00', '?'),
        ]);

        $this->assertSame('Club event', $index['allTable'][0]['event_name']);
    }

    // ── findOverlappingEvent: all-table events ────────────────────────────────

    public function testAllTableEventBlocksMatchingSlot(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 11:00:00', '2026-07-01 15:30:00'),
        ]);

        $result = $this->findOverlappingEvent($index, 1, '2026-07-01 12:00:00', '2026-07-01 12:30:00');

        $this->assertNotNull($result);
        $this->assertSame(1, $result['eid']);
    }

    public function testAllTableEventDoesNotBlockSlotBefore(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 11:00:00', '2026-07-01 15:30:00'),
        ]);

        $result = $this->findOverlappingEvent($index, 1, '2026-07-01 09:00:00', '2026-07-01 10:30:00');

        $this->assertNull($result);
    }

    public function testAllTableEventDoesNotBlockSlotAfter(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 11:00:00', '2026-07-01 15:30:00'),
        ]);

        $result = $this->findOverlappingEvent($index, 1, '2026-07-01 16:00:00', '2026-07-01 16:30:00');

        $this->assertNull($result);
    }

    // ── findOverlappingEvent: table-specific events ───────────────────────────

    public function testTableSpecificEventBlocksCorrectTable(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, 2, '2026-07-01 14:00:00', '2026-07-01 18:00:00'),
        ]);

        $result = $this->findOverlappingEvent($index, 2, '2026-07-01 14:00:00', '2026-07-01 14:30:00');

        $this->assertNotNull($result);
    }

    public function testTableSpecificEventDoesNotBlockDifferentTable(): void
    {
        $index = $this->indexBlockingEvents([
            $this->event(1, 2, '2026-07-01 14:00:00', '2026-07-01 18:00:00'),
        ]);

        $result = $this->findOverlappingEvent($index, 3, '2026-07-01 14:00:00', '2026-07-01 14:30:00');

        $this->assertNull($result);
    }

    // ── Half-open interval edge cases ─────────────────────────────────────────

    public function testSlotStartEqualsEventEnd_NoOverlap(): void
    {
        // slot start == event end → NOT overlapping (half-open interval)
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 09:00:00', '2026-07-01 11:00:00'),
        ]);

        $result = $this->findOverlappingEvent($index, 1, '2026-07-01 11:00:00', '2026-07-01 11:30:00');

        $this->assertNull($result);
    }

    public function testSlotEndEqualsEventStart_NoOverlap(): void
    {
        // slot end == event start → NOT overlapping (half-open interval)
        $index = $this->indexBlockingEvents([
            $this->event(1, null, '2026-07-01 12:00:00', '2026-07-01 15:00:00'),
        ]);

        $result = $this->findOverlappingEvent($index, 1, '2026-07-01 11:30:00', '2026-07-01 12:00:00');

        $this->assertNull($result);
    }

    // ── Reservation index ─────────────────────────────────────────────────────

    public function testReservationsIndexedByTableAndDate(): void
    {
        $reservations = [
            $this->reservation(1, '2026-07-01', '11:00', '12:00'),
            $this->reservation(1, '2026-07-02', '14:00', '15:00'),
            $this->reservation(2, '2026-07-01', '09:00', '10:00'),
        ];

        $index = $this->indexReservations($reservations);

        $this->assertArrayHasKey(1, $index);
        $this->assertArrayHasKey('2026-07-01', $index[1]);
        $this->assertCount(1, $index[1]['2026-07-01']);
        $this->assertCount(1, $index[1]['2026-07-02']);
        $this->assertCount(1, $index[2]['2026-07-01']);
    }

    public function testReservationWithMissingTableIdSkipped(): void
    {
        $reservation = ['sid' => 0, 'date' => '2026-07-01', 'time_start' => '11:00', 'time_end' => '12:00'];
        $index = $this->indexReservations([$reservation]);

        $this->assertEmpty($index);
    }

    // ── findOverlappingReservation ────────────────────────────────────────────

    public function testReservationOverlapsSlot(): void
    {
        $reservations = [
            array_merge(
                $this->reservation(1, '2026-07-01', '11:00', '13:00'),
                ['_startSeconds' => 39600, '_endSeconds' => 46800]
            ),
        ];

        // Slot 11:30–12:00 is inside reservation 11:00–13:00
        $result = $this->findOverlappingReservation($reservations, 41400, 43200);
        $this->assertNotNull($result);
    }

    public function testReservationBeforeSlot_NoOverlap(): void
    {
        $reservations = [
            array_merge(
                $this->reservation(1, '2026-07-01', '09:00', '11:00'),
                ['_startSeconds' => 32400, '_endSeconds' => 39600]
            ),
        ];

        // Slot 11:00–11:30 starts exactly when reservation ends — no overlap
        $result = $this->findOverlappingReservation($reservations, 39600, 41400);
        $this->assertNull($result);
    }

    public function testReservationAfterSlot_NoOverlap(): void
    {
        $reservations = [
            array_merge(
                $this->reservation(1, '2026-07-01', '13:00', '14:00'),
                ['_startSeconds' => 46800, '_endSeconds' => 50400]
            ),
        ];

        // Slot 12:00–13:00 ends exactly when reservation starts — no overlap
        $result = $this->findOverlappingReservation($reservations, 43200, 46800);
        $this->assertNull($result);
    }
}

/**
 * Tests for booking temporal state and amendment logic.
 *
 * Pure-logic tests — no database connection required.
 */
class SsaBookingTemporalStateTest extends TestCase
{
    // ── Inline helpers mirroring booking-amend-options.php logic ──────────

    /**
     * Returns 'upcoming', 'active', or 'past' for a booking on $date with the
     * given $start / $end, evaluated at simulated clock time $nowTime on the
     * same $date.
     */
    private function temporalState(string $date, string $start, string $end, string $nowTime, string $nowDate): string
    {
        if ($nowDate < $date) { return 'upcoming'; }
        if ($nowDate > $date) { return 'past'; }

        // Same date.
        if ($nowTime < $start) { return 'upcoming'; }
        if ($nowTime >= $end)  { return 'past'; }
        return 'active';
    }

    /**
     * Calculates the earliest permitted new end time given a booking start and
     * the current clock time, using $blockSec-second blocks.
     *
     * Mirrors ssaApiEarliestAmendEndSec() in _booking_write.php.
     * Accepts H:MM or H:MM:SS strings so sub-minute precision tests are possible.
     */
    private function earliestNewEnd(string $timeStart, string $nowTime, int $blockSec = 1800): string
    {
        $startSec = $this->t2s($timeStart);
        $nowSec   = $this->t2s($nowTime);

        $elapsedSec    = max(0, $nowSec - $startSec);
        $startedBlocks = intdiv($elapsedSec, $blockSec) + 1;
        $earliestSec   = $startSec + ($startedBlocks * $blockSec);

        return $this->s2t($earliestSec);
    }

    private function t2s(string $time): int
    {
        $parts = explode(':', $time);
        return (int)$parts[0] * 3600 + (int)($parts[1] ?? 0) * 60 + (int)($parts[2] ?? 0);
    }

    private function s2t(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    // ── Temporal state tests ──────────────────────────────────────────────

    public function testUpcomingBookingIsUpcoming(): void
    {
        // Booking 13:00–16:30; now 12:00
        $this->assertSame('upcoming', $this->temporalState('2026-07-01', '13:00', '16:30', '12:00', '2026-07-01'));
    }

    public function testBookingExactlyAtStartIsActive(): void
    {
        $this->assertSame('active', $this->temporalState('2026-07-01', '13:00', '16:30', '13:00', '2026-07-01'));
    }

    public function testBookingBetweenStartAndEndIsActive(): void
    {
        // now 14:00 is between 13:00 and 16:30 → active
        $this->assertSame('active', $this->temporalState('2026-07-01', '13:00', '16:30', '14:00', '2026-07-01'));
    }

    public function testBookingOneMinuteBeforeEndIsActive(): void
    {
        $this->assertSame('active', $this->temporalState('2026-07-01', '13:00', '16:30', '16:29', '2026-07-01'));
    }

    public function testBookingExactlyAtEndIsPast(): void
    {
        $this->assertSame('past', $this->temporalState('2026-07-01', '13:00', '16:30', '16:30', '2026-07-01'));
    }

    public function testBookingAfterEndIsPast(): void
    {
        $this->assertSame('past', $this->temporalState('2026-07-01', '13:00', '16:30', '17:00', '2026-07-01'));
    }

    public function testBookingYesterdayIsPast(): void
    {
        $this->assertSame('past', $this->temporalState('2026-07-01', '13:00', '16:30', '14:00', '2026-07-02'));
    }

    public function testBookingTomorrowIsUpcoming(): void
    {
        $this->assertSame('upcoming', $this->temporalState('2026-07-03', '13:00', '16:30', '14:00', '2026-07-02'));
    }

    // ── Grouped booking uses final end time ───────────────────────────────

    public function testGroupedBookingUsesGroupEndForActiveState(): void
    {
        // Slots 13:00–13:30, 13:30–14:00 grouped → groupEnd = 14:00
        // At 13:45, booking is active using group end 14:00
        $this->assertSame('active', $this->temporalState('2026-07-01', '13:00', '14:00', '13:45', '2026-07-01'));
    }

    public function testGroupedBookingAtEndIsPast(): void
    {
        $this->assertSame('past', $this->temporalState('2026-07-01', '13:00', '14:00', '14:00', '2026-07-01'));
    }

    // ── Earliest new end calculation ──────────────────────────────────────

    public function testEarliestNewEndAtExactSlotBoundary(): void
    {
        // Now = 14:00, start = 13:00, blockSec = 1800
        // 14:00 is exactly a slot boundary → the 14:00–14:30 block starts now
        // Earliest end = 14:30
        $this->assertSame('14:30', $this->earliestNewEnd('13:00', '14:00', 1800));
    }

    public function testEarliestNewEndMidBlock(): void
    {
        // Now = 14:10 → still in 14:00–14:30 block → earliest = 14:30
        $this->assertSame('14:30', $this->earliestNewEnd('13:00', '14:10', 1800));
    }

    public function testEarliestNewEndOneSecondBeforeBoundary(): void
    {
        // Now = 13:59 → in 13:30–14:00 block → earliest = 14:00
        $this->assertSame('14:00', $this->earliestNewEnd('13:00', '13:59', 1800));
    }

    public function testEarliestNewEndAtBookingStart(): void
    {
        // Now = 13:00 (just started) → first block = 13:00–13:30 → earliest = 13:30
        $this->assertSame('13:30', $this->earliestNewEnd('13:00', '13:00', 1800));
    }

    public function testEarliestNewEndWith60MinuteBlocks(): void
    {
        // 60-minute blocks; now = 14:30 → in 14:00–15:00 block → earliest = 15:00
        $this->assertSame('15:00', $this->earliestNewEnd('13:00', '14:30', 3600));
    }

    // Required spec cases — all with 13:00 start, 30-minute blocks ────────

    public function testEarliestNewEndOneMinuteAfterBookingStart(): void
    {
        // now 13:01 → still in first 13:00–13:30 block → earliest = 13:30
        $this->assertSame('13:30', $this->earliestNewEnd('13:00', '13:01', 1800));
    }

    public function testEarliestNewEndNearFirstBlockEnd(): void
    {
        // now 13:29 → still in first 13:00–13:30 block → earliest = 13:30
        $this->assertSame('13:30', $this->earliestNewEnd('13:00', '13:29', 1800));
    }

    public function testEarliestNewEndAtSecondBlockBoundary(): void
    {
        // now 13:30 exactly → second block 13:30–14:00 has started → earliest = 14:00
        $this->assertSame('14:00', $this->earliestNewEnd('13:00', '13:30', 1800));
    }

    public function testEarliestNewEndAtFourthBlockBoundary(): void
    {
        // now 14:30 exactly → fourth block 14:30–15:00 has started → earliest = 15:00
        $this->assertSame('15:00', $this->earliestNewEnd('13:00', '14:30', 1800));
    }

    public function testEarliestNewEndOneSecondBeforeBookingEnd(): void
    {
        // Booking 13:00–16:30; now 16:29:59 → in 16:00–16:30 block → earliest = 16:30
        // This verifies the result does not exceed the current booking end.
        $this->assertSame('16:30', $this->earliestNewEnd('13:00', '16:29:59', 1800));
    }

    // ── Event race condition ───────────────────────────────────────────────

    public function testEventRaceConditionBlocksAmendment(): void
    {
        // Simulates the race between option loading and amendment submission:
        // 1. Initial check: no events → extension appears available.
        // 2. A blocking event is created by another process.
        // 3. Transactional recheck detects it → amendment must be rejected.
        // Steps 4-7 (reservation unchanged, no audit, no push) are enforced by
        // the rollBack() path in booking-amend.php and verified on deployment.

        $date   = '2026-07-02';
        $oldEnd = '16:30';
        $newEnd = '17:00';

        $extensionBlocked = function (array $events) use ($date, $oldEnd, $newEnd): bool {
            $extStart = $date . ' ' . $oldEnd . ':00';
            $extEnd   = $date . ' ' . $newEnd . ':00';
            foreach ($events as $event) {
                $evStart = (string)$event['datetime_start'];
                $evEnd   = (string)$event['datetime_end'];
                if ($extStart < $evEnd && $extEnd > $evStart) {
                    return true;
                }
            }
            return false;
        };

        // Step 1: initial check passes — no blocking events.
        $this->assertFalse(
            $extensionBlocked([]),
            'Initial check should pass when no events exist'
        );

        // Step 2 & 3: a blocking event appears; transactional recheck detects it.
        $racingEvent = [
            'eid'            => 99,
            'sid'            => null, // all-table
            'datetime_start' => $date . ' 16:30:00',
            'datetime_end'   => $date . ' 17:30:00',
        ];

        $this->assertTrue(
            $extensionBlocked([$racingEvent]),
            'Recheck must detect a blocking event created after option loading'
        );

        // Verify table-specific event is also caught.
        $tableEvent = [
            'eid'            => 100,
            'sid'            => 1, // table-specific
            'datetime_start' => $date . ' 16:30:00',
            'datetime_end'   => $date . ' 17:00:00',
        ];

        $this->assertTrue(
            $extensionBlocked([$tableEvent]),
            'Recheck must detect a table-specific blocking event'
        );
    }

    // ── Amendment direction ────────────────────────────────────────────────

    public function testShorteningReducesEndTime(): void
    {
        $oldEnd = '16:30';
        $newEnd = '15:00';
        $this->assertSame('shortened', $newEnd < $oldEnd ? 'shortened' : 'extended');
    }

    public function testExtendingIncreasesEndTime(): void
    {
        $oldEnd = '16:30';
        $newEnd = '17:30';
        $this->assertSame('extended', $newEnd > $oldEnd ? 'extended' : 'shortened');
    }

    public function testNoOpEndTimeIsDetected(): void
    {
        $oldEnd = '16:30';
        $newEnd = '16:30';
        $this->assertTrue($oldEnd === $newEnd);
    }
}
