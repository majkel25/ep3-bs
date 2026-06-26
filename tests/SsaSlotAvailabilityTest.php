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

            if ($event['sid'] === null) {
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
