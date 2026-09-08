<?php

namespace Booking\Service;

use Booking\Exception\BookingSlotLimitExceededException;
use Booking\Table\BookingTable;
use Booking\Table\ReservationTable;
use InvalidArgumentException;
use User\Table\UserTable;
use Zend\Db\Adapter\Adapter;

class BookingSlotLimitService
{
    const MAX_ACTIVE_HALF_HOUR_SLOTS = 32;
    const SLOT_MINUTES = 30;

    protected $db;

    public function __construct(Adapter $db)
    {
        $this->db = $db;
    }

    public function assertCanCreate($uid, $dateStart, $dateEnd, $timeStart, $timeEnd, $repeat = 0)
    {
        $uid = $this->normalizePositiveInteger($uid, 'User id');
        $requestedSlots = $this->calculateRequestedSlots($dateStart, $dateEnd, $timeStart, $timeEnd, $repeat);

        $this->assertCanAddSlots($uid, $requestedSlots);
    }

    public function assertCanAddSlots($uid, $requestedSlots, $excludeBid = null, $excludeRid = null)
    {
        $uid = $this->normalizePositiveInteger($uid, 'User id');
        $requestedSlots = (int) $requestedSlots;

        if ($requestedSlots < 0) {
            throw new InvalidArgumentException('Requested booking slot count cannot be negative');
        }

        if ($excludeBid !== null) {
            $excludeBid = $this->normalizePositiveInteger($excludeBid, 'Booking id');
        }

        if ($excludeRid !== null) {
            $excludeRid = $this->normalizePositiveInteger($excludeRid, 'Reservation id');
        }

        $this->lockUser($uid);

        $currentSlots = $this->getActiveSlotCount($uid, $excludeBid, $excludeRid);

        if ($currentSlots + $requestedSlots > self::MAX_ACTIVE_HALF_HOUR_SLOTS) {
            throw new BookingSlotLimitExceededException(
                $currentSlots,
                $requestedSlots,
                self::MAX_ACTIVE_HALF_HOUR_SLOTS
            );
        }
    }

    public function getActiveSlotCount($uid, $excludeBid = null, $excludeRid = null)
    {
        $uid = $this->normalizePositiveInteger($uid, 'User id');

        $where = array(
            sprintf('b.uid = %d', $uid),
            'b.status <> "cancelled"',
            sprintf(
                '(r.date > CURDATE() OR (r.date = CURDATE() AND (TIME_TO_SEC(r.time_end) <= TIME_TO_SEC(r.time_start) OR r.time_end > CURTIME())))'
            ),
        );

        if ($excludeBid !== null) {
            $where[] = sprintf('r.bid <> %d', $this->normalizePositiveInteger($excludeBid, 'Booking id'));
        }

        if ($excludeRid !== null) {
            $where[] = sprintf('r.rid <> %d', $this->normalizePositiveInteger($excludeRid, 'Reservation id'));
        }

        $sql = sprintf(
            'SELECT COALESCE(SUM(CEIL(((CASE WHEN TIME_TO_SEC(r.time_end) <= TIME_TO_SEC(r.time_start) THEN TIME_TO_SEC(r.time_end) + 86400 ELSE TIME_TO_SEC(r.time_end) END) - TIME_TO_SEC(r.time_start)) / %d)), 0) AS slot_count FROM %s r INNER JOIN %s b ON b.bid = r.bid WHERE %s',
            self::SLOT_MINUTES * 60,
            ReservationTable::NAME,
            BookingTable::NAME,
            implode(' AND ', $where)
        );

        $result = $this->db->query($sql, Adapter::QUERY_MODE_EXECUTE);
        $row = $result->current();

        if (! $row) {
            return 0;
        }

        if (is_array($row)) {
            return (int) $row['slot_count'];
        }

        return (int) $row->slot_count;
    }

    public function calculateRequestedSlots($dateStart, $dateEnd, $timeStart, $timeEnd, $repeat = 0)
    {
        $dateStart = $this->normalizeDate($dateStart);
        $dateEnd = $this->normalizeDate($dateEnd);
        $repeat = (int) $repeat;

        if ($repeat < 0) {
            throw new InvalidArgumentException('Repeat interval cannot be negative');
        }

        $slotsPerReservation = $this->calculateSlotsForTimeRange($timeStart, $timeEnd);

        if ($repeat === 0) {
            return $this->reservationIsStillActive($dateStart, $timeStart, $timeEnd)
                ? $slotsPerReservation
                : 0;
        }

        if ($repeat < 1) {
            throw new InvalidArgumentException('Repeat interval must be at least one day');
        }

        if ($dateStart > $dateEnd) {
            throw new InvalidArgumentException('Booking end date cannot be before start date');
        }

        $requestedSlots = 0;
        $walkingDate = clone $dateStart;

        while ($walkingDate <= $dateEnd) {
            if ($this->reservationIsStillActive($walkingDate, $timeStart, $timeEnd)) {
                $requestedSlots += $slotsPerReservation;
            }

            $walkingDate->modify('+' . $repeat . ' day');
        }

        return $requestedSlots;
    }

    public function calculateSlotsForTimeRange($timeStart, $timeEnd)
    {
        $startMinutes = $this->timeToMinutes($timeStart, false);
        $endMinutes = $this->timeToMinutes($timeEnd, true);

        if ($endMinutes <= $startMinutes) {
            $endMinutes += 24 * 60;
        }

        $durationMinutes = $endMinutes - $startMinutes;

        if ($durationMinutes <= 0) {
            throw new InvalidArgumentException('Booking duration must be greater than zero');
        }

        return (int) ceil($durationMinutes / self::SLOT_MINUTES);
    }

    protected function lockUser($uid)
    {
        $sql = sprintf(
            'SELECT uid FROM %s WHERE uid = %d FOR UPDATE',
            UserTable::NAME,
            $uid
        );

        $result = $this->db->query($sql, Adapter::QUERY_MODE_EXECUTE);

        if (! $result->current()) {
            throw new InvalidArgumentException('User does not exist');
        }
    }

    protected function normalizePositiveInteger($value, $label)
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException($label . ' must be a positive integer');
        }

        return (int) $value;
    }

    protected function normalizeDate($value)
    {
        if ($value instanceof \DateTime) {
            return clone $value;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Invalid booking date');
        }
    }

    protected function timeToMinutes($value, $allowEndOfDay)
    {
        if ($value instanceof \DateTime) {
            $value = $value->format('H:i');
        }

        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $matches)) {
            throw new InvalidArgumentException('Invalid booking time');
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($minutes > 59 || $hours > 24 || ($hours === 24 && ($minutes !== 0 || ! $allowEndOfDay))) {
            throw new InvalidArgumentException('Invalid booking time');
        }

        return ($hours * 60) + $minutes;
    }

    protected function reservationIsStillActive(\DateTime $date, $timeStart, $timeEnd)
    {
        $startMinutes = $this->timeToMinutes($timeStart, false);
        $endMinutes = $this->timeToMinutes($timeEnd, true);

        if ($endMinutes <= $startMinutes) {
            $endMinutes += 24 * 60;
        }

        $endDateTime = clone $date;
        $endDateTime->setTime(0, 0, 0);
        $endDateTime->modify('+' . $endMinutes . ' minutes');

        return $endDateTime > new \DateTime();
    }
}
