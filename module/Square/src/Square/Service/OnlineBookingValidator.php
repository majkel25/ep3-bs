<?php

namespace Square\Service;

use DateTime;

/**
 * Validator used by the public online booking calendar.
 *
 * The legacy SquareValidator limits the number of future reservation records.
 * For SSA, members are instead limited by booked time: a maximum of 32
 * half-hour periods (16 hours) across all tables.
 */
class OnlineBookingValidator extends SquareValidator
{
    const MAX_ACTIVE_BOOKING_SLOTS = 32;
    const BOOKING_SLOT_SECONDS = 1800;

    /**
     * Checks if the passed datetime range can be booked by the current user.
     *
     * This retains the existing availability/capacity/event validation and
     * replaces only the legacy active-booking-count limit with a duration
     * based limit across all tables.
     *
     * @param string $dateStart
     * @param string $dateEnd
     * @param string $timeStart
     * @param string $timeEnd
     * @param int $square
     * @return array
     */
    public function isBookable($dateStart, $dateEnd, $timeStart, $timeEnd, $square)
    {
        $byproducts = $this->isValid($dateStart, $dateEnd, $timeStart, $timeEnd, $square);

        $dateStart = $byproducts['dateStart'];
        $dateEnd = $byproducts['dateEnd'];
        $square = $byproducts['square'];
        $user = $byproducts['user'];

        $notBookableReason = null;

        /* Check for other reservations */

        $possibleReservations = $this->reservationManager->getInRange($dateStart, $dateEnd);
        $possibleBookings = $this->bookingManager->getByReservations($possibleReservations);

        $reservations = array();
        $bookings = array();

        $quantity = 0;
        $bookingsFromUser = array();

        foreach ($possibleBookings as $bid => $booking) {
            if ($booking->need('sid') == $square->need('sid')) {
                if ($booking->need('visibility') == 'public') {
                    if ($booking->need('status') != 'cancelled') {
                        $bookings[$bid] = $booking;
                        $quantity += $booking->need('quantity');

                        if ($user && $user->need('uid') == $booking->need('uid')) {
                            $bookingsFromUser[$bid] = $booking;
                        }
                    }
                }
            }
        }

        if ($bookings) {
            foreach ($possibleReservations as $rid => $reservation) {
                if (isset($bookings[$reservation->need('bid')])) {
                    $reservations[$rid] = $reservation;
                }
            }
        }

        $capacity = $square->need('capacity');
        $capacityHeterogenic = $square->need('capacity_heterogenic');

        if ($capacity > $quantity) {
            if ($quantity && ! $capacityHeterogenic) {
                $bookable = false;
            } else {
                $bookable = true;
            }
        } else {
            $bookable = false;
        }

        /* Check the SSA 32 x 30-minute active booking limit across all tables. */

        if ($user) {
            $activeBookings = $this->bookingManager->getByValidity(array(
                'uid' => $user->need('uid'),
            ));

            $this->reservationManager->getByBookings($activeBookings);

            $now = new DateTime();
            $activeBookingSlots = 0;

            foreach ($activeBookings as $activeBooking) {
                $activeReservations = $activeBooking->getExtra('reservations');

                if (! is_array($activeReservations)) {
                    continue;
                }

                foreach ($activeReservations as $activeReservation) {
                    $activeReservationStart = new DateTime(
                        $activeReservation->get('date') . ' ' . $activeReservation->get('time_start')
                    );

                    /* Past and already-started reservations do not use future allowance. */
                    if ($activeReservationStart <= $now) {
                        continue;
                    }

                    $activeReservationEnd = new DateTime(
                        $activeReservation->get('date') . ' ' . $activeReservation->get('time_end')
                    );

                    $reservationSeconds = $activeReservationEnd->getTimestamp() - $activeReservationStart->getTimestamp();

                    if ($reservationSeconds > 0) {
                        $activeBookingSlots += (int) ceil($reservationSeconds / self::BOOKING_SLOT_SECONDS);
                    }
                }
            }

            $requestedSeconds = $dateEnd->getTimestamp() - $dateStart->getTimestamp();
            $requestedSlots = (int) ceil($requestedSeconds / self::BOOKING_SLOT_SECONDS);

            if ($requestedSlots > 0 &&
                ($activeBookingSlots + $requestedSlots) > self::MAX_ACTIVE_BOOKING_SLOTS) {

                $bookable = false;
                $remainingSlots = max(0, self::MAX_ACTIVE_BOOKING_SLOTS - $activeBookingSlots);

                $notBookableReason = sprintf(
                    'You can have a maximum of <b>%d active 30-minute booking periods</b> across all tables. '
                    . 'You currently have %d period(s) booked and %d period(s) remaining.',
                    self::MAX_ACTIVE_BOOKING_SLOTS,
                    $activeBookingSlots,
                    $remainingSlots
                );
            }
        }

        /* Check for blocking events */

        $events = $this->eventManager->getInRange($dateStart, $dateEnd);

        foreach ($events as $event) {
            if (is_null($event->get('sid')) || $event->get('sid') == $square->need('sid')) {
                $bookable = false;
            }
        }

        /* Gather byproducts */

        $byproducts['bookings'] = $bookings;
        $byproducts['bookingsFromUser'] = $bookingsFromUser;
        $byproducts['reservations'] = $reservations;
        $byproducts['bookable'] = $bookable;
        $byproducts['notBookableReason'] = $notBookableReason;
        $byproducts['quantity'] = $quantity;
        $byproducts['events'] = $events;

        return $byproducts;
    }
}
