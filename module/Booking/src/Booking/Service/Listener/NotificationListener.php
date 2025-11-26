<?php

namespace Booking\Service\Listener;

use Backend\Service\MailService as BackendMailService;
use Base\Manager\OptionManager;
use Base\View\Helper\DateRange;
use Base\View\Helper\PriceFormatPlain;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\ReservationManager;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use User\Service\MailService as UserMailService;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\Event;
use Zend\EventManager\EventManagerInterface;
use Zend\I18n\Translator\TranslatorInterface;
use Zend\I18n\View\Helper\DateFormat;
use Service\Service\BookingInterestService;

/**
 * Sends booking / cancellation notifications to users and back-office,
 * and (on cancellation) triggers BookingInterestService so that users
 * who “watch” a day can be notified about freed slots.
 */
class NotificationListener extends AbstractListenerAggregate
{
    /** @var OptionManager */
    protected $optionManager;

    /** @var ReservationManager */
    protected $reservationManager;

    /** @var SquareManager */
    protected $squareManager;

    /** @var UserManager */
    protected $userManager;

    /** @var UserMailService */
    protected $userMailService;

    /** @var BackendMailService */
    protected $backendMailService;

    /** @var DateFormat */
    protected $dateFormatHelper;

    /** @var DateRange */
    protected $dateRangeHelper;

    /** @var TranslatorInterface */
    protected $translator;

    /** @var BookingInterestService */
    protected $bookingInterestService;

    /** @var BillManager */
    protected $bookingBillManager;

    /** @var PriceFormatPlain */
    protected $priceFormatHelper;

    public function __construct(
        OptionManager          $optionManager,
        ReservationManager     $reservationManager,
        SquareManager          $squareManager,
        UserManager            $userManager,
        UserMailService        $userMailService,
        BackendMailService     $backendMailService,
        DateFormat             $dateFormatHelper,
        DateRange              $dateRangeHelper,
        TranslatorInterface    $translator,
        BookingInterestService $bookingInterestService,
        BillManager            $bookingBillManager,
        PriceFormatPlain       $priceFormatHelper
    ) {
        $this->optionManager          = $optionManager;
        $this->reservationManager     = $reservationManager;
        $this->squareManager          = $squareManager;
        $this->userManager            = $userManager;
        $this->userMailService        = $userMailService;
        $this->backendMailService     = $backendMailService;
        $this->dateFormatHelper       = $dateFormatHelper;
        $this->dateRangeHelper        = $dateRangeHelper;
        $this->translator             = $translator;
        $this->bookingInterestService = $bookingInterestService;
        $this->bookingBillManager     = $bookingBillManager;
        $this->priceFormatHelper      = $priceFormatHelper;
    }

    /**
     * Attach listeners to booking events.
     */
    public function attach(EventManagerInterface $events)
    {
        // IMPORTANT: these event names must match BookingService!
        $this->listeners[] = $events->attach('create.single', [$this, 'onCreateSingle']);
        $this->listeners[] = $events->attach('cancel.single',  [$this, 'onCancelSingle']);
    }

    /**
     * Booking has been created -> send confirmation.
     *
     * @param Event $event
     */
    public function onCreateSingle(Event $event)
    {
        $booking     = $event->getTarget();
        $reservation = current($booking->getExtra('reservations'));

        if (! $reservation) {
            return;
        }

        $square = $this->squareManager->get($booking->need('sid'));
        $user   = $this->userManager->get($booking->need('uid'));

        /** @var DateFormat $dateFormatHelper */
        $dateFormatHelper = $this->dateFormatHelper;
        /** @var DateRange $dateRangeHelper */
        $dateRangeHelper  = $this->dateRangeHelper;

        $reservationTimeStart = explode(':', $reservation->need('time_start'));
        $reservationTimeEnd   = explode(':', $reservation->need('time_end'));

        $reservationStart = new \DateTime($reservation->need('date'));
        $reservationStart->setTime($reservationTimeStart[0], $reservationTimeStart[1]);

        $reservationEnd = new \DateTime($reservation->need('date'));
        $reservationEnd->setTime($reservationTimeEnd[0], $reservationTimeEnd[1]);

        $subject = sprintf(
            $this->t('Your %s-booking for %s'),
            $this->optionManager->get('subject.square.type'),
            $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT)
        );

        $message = sprintf(
            $this->t('we have reserved %s %s, %s for you.'),
            $this->optionManager->get('subject.square.type'),
            $square->need('name'),
            $dateRangeHelper($reservationStart, $reservationEnd)
        );

        // Optional player names
        $playerNames = $booking->getMeta('player-names');
        if ($playerNames) {
            $playerNamesUnserialized = @unserialize($playerNames);

            if (is_iterable($playerNamesUnserialized)) {
                $message .= "\n\n" . $this->t('Named fellow players:');

                foreach ($playerNamesUnserialized as $i => $playerName) {
                    $message .= sprintf("\n%s. %s", $i + 1, $playerName['value']);
                }
            }
        }

        // Optional notes
        if ($square->get('allow_notes') && $booking->getMeta('notes')) {
            $message .= "\n\n" . $this->t('Notes:');
            $message .= "\n" . $booking->getMeta('notes');
        }

        // Send to user (this meta key is what the original app uses)
        if ($user->getMeta('notification.bookings', 'true') === 'true') {
            $this->userMailService->send($user, $subject, $message);
        }

        // Send copy to back-office
        if ($this->optionManager->get('client.contact.email.user-notifications')) {
            $backendSubject = sprintf(
                $this->t("%s's %s-booking for %s"),
                $user->need('alias'),
                $this->optionManager->get('subject.square.type'),
                $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT)
            );

            $addendum = sprintf(
                $this->t('Originally sent to %s (%s).'),
                $user->need('alias'),
                $user->need('email')
            );

            $this->backendMailService->send($backendSubject, $message, [], $addendum);
        }
    }

    /**
     * Booking has been cancelled -> send cancellation mail
     * AND trigger BookingInterestService so that “watchers” of this
     * day can be notified.
     *
     * @param Event $event
     */
    public function onCancelSingle(Event $event)
    {
        $booking = $event->getTarget();

        // Load reservation again (we only need one, typical for SSA tables)
        $reservations = $this->reservationManager->getBy(
            ['bid' => $booking->need('bid')],
            'date ASC',
            1
        );
        $reservation = current($reservations);

        if (! $reservation) {
            return;
        }

        $square = $this->squareManager->get($booking->need('sid'));
        $user   = $this->userManager->get($booking->need('uid'));

        /** @var DateRange $dateRangeHelper */
        $dateRangeHelper = $this->dateRangeHelper;

        $reservationTimeStart = explode(':', $reservation->need('time_start'));
        $reservationTimeEnd   = explode(':', $reservation->need('time_end'));

        $reservationStart = new \DateTime($reservation->need('date'));
        $reservationStart->setTime($reservationTimeStart[0], $reservationTimeStart[1]);

        $reservationEnd = new \DateTime($reservation->need('date'));
        $reservationEnd->setTime($reservationTimeEnd[0], $reservationTimeEnd[1]);

        // --- existing e-mail notifications ---

        $subject = sprintf(
            $this->t('Your %s-booking has been cancelled'),
            $this->optionManager->get('subject.square.type')
        );

        $message = sprintf(
            $this->t('we have just cancelled %s %s, %s for you.'),
            $this->optionManager->get('subject.square.type'),
            $square->need('name'),
            $dateRangeHelper($reservationStart, $reservationEnd)
        );

        if ($user->getMeta('notification.bookings', 'true') === 'true') {
            $this->userMailService->send($user, $subject, $message);
        }

        if ($this->optionManager->get('client.contact.email.user-notifications')) {
            $backendSubject = sprintf(
                $this->t("%s's %s-booking has been cancelled"),
                $user->need('alias'),
                $this->optionManager->get('subject.square.type')
            );

            $addendum = sprintf(
                $this->t('Originally sent to %s (%s).'),
                $user->need('alias'),
                $user->need('email')
            );

            $this->backendMailService->send($backendSubject, $message, [], $addendum);
        }

        // --- NEW: notify users who registered interest in this day ---

        try {
            $bookingData = [
                'id'          => $booking->need('bid'),
                'start'       => $reservationStart,
                'end'         => $reservationEnd,
                'square_name' => $square->need('name'),
            ];

            $this->bookingInterestService->notifyCancellation($bookingData);
        } catch (\Throwable $e) {
            // Do not break cancellation flow if interest notification fails.
            // You can add logging here later if needed.
        }
    }

    /**
     * Small helper for translated strings.
     *
     * @param string $message
     * @return string
     */
    protected function t($message)
    {
        return $this->translator->translate($message);
    }
}
