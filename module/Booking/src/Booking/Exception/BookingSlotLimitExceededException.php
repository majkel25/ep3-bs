<?php

namespace Booking\Exception;

use RuntimeException;

class BookingSlotLimitExceededException extends RuntimeException
{
    const ERROR_CODE = 'BOOKING_SLOT_LIMIT_EXCEEDED';

    public function __construct($currentSlots, $requestedSlots, $limit)
    {
        $message = sprintf(
            'You can have a maximum of %d half-hour booking slots reserved at any one time. This booking would take you over your %d-slot limit. To make this booking, please cancel or reduce one of your existing bookings first.',
            $limit,
            $limit
        );

        parent::__construct($message);

        $this->currentSlots = (int) $currentSlots;
        $this->requestedSlots = (int) $requestedSlots;
        $this->limit = (int) $limit;
    }

    protected $currentSlots;
    protected $requestedSlots;
    protected $limit;

    public function getErrorCode()
    {
        return self::ERROR_CODE;
    }

    public function getCurrentSlots()
    {
        return $this->currentSlots;
    }

    public function getRequestedSlots()
    {
        return $this->requestedSlots;
    }

    public function getLimit()
    {
        return $this->limit;
    }
}
