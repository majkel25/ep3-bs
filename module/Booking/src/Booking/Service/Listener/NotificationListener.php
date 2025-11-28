<?php
//michael 2
namespace Booking\Service\Listener;

use Backend\Service\MailService as BackendMailService;
use Base\Manager\OptionManager;
use Base\View\Helper\DateRange;
use Base\View\Helper\PriceFormatPlain;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\ReservationManager;
use Service\Service\BookingInterestService;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use User\Service\MailService as UserMailService;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\Event;
use Zend\EventManager\EventManagerInterface;
use Zend\I18n\View\Helper\DateFormat;
use Zend\Mvc\I18n\Translator;
use Zend\Mvc\I18n\TranslatorInterface;

class NotificationListener extends AbstractListenerAggregate
{
    /**
     * @var OptionManager
     */
    protected $optionManager;

    /**
     * @var ReservationManager
     */
    protected $reservationManager;

    /**
     * @var SquareManager
     */
    protected $squareManager;

    /**
     * @var UserManager
     */
    protected $userManager;

    /**
     * @var UserMailService
     */
    protected $userMailService;

    /**
     * @var BackendMailService
     */
    protected $backendMailService;

    /**
     * @var DateFormat
     */
    protected $dateFormatHelper;

    /**
     * @var DateRange
     */
    protected $dateRangeHelper;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var BillManager
     */
    protected $bookingBillManager;

    /**
     * @var PriceFormatPlain
     */
    protected $priceFormatHelper;

    /**
     * @var BookingInterestService|null
     */
    protected $bookingInterestService;

    public function __construct(
        OptionManager $optionManager,
        ReservationManager $reservationManager,
        SquareManager $squareManager,
        UserManager $userManager,
        UserMailService $userMailService,
        BackendMailService $backendMailService,
        DateFormat $dateFormatHelper,
        DateRange $dateRangeHelper,
        TranslatorInterface $translator,
        BillManager $bookingBillManager,
        PriceFormatPlain $priceFormatHelper,
        BookingInterestService $bookingInterestService = null
    ) {
        $this->optionManager          = $optionManager;
        $this->reservationManager     = $reservationManager;
        $this->squareManager          = $squareManager;
        $this->userManager            = $userManager;
        $this->userMailService        = $userMailService;
        $this->backendMailService     = $backendMailService;
        $this->bookingBillManager     = $bookingBillManager;
        $this->bookingInterestService = $bookingInterestService;
        $this->dateFormatHelper       = $dateFormatHelper;
        $this->dateRangeHelper        = $dateRangeHelper;
        $this->translator             = $translator;
        $this->priceFormatHelper      = $priceFormatHelper;
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach('create.single', array($this, 'onCreateSingle'));
        $events->attach('cancel.single', array($this, 'onCancelSingle'));
    }

    public function onCreateSingle(Event $event)
    {
        $booking     = $event->getTarget();
        $reservation = current($booking->getExtra('reservations'));
        $square      = $this->squareManager->get($booking->need('sid'));
        $user        = $this->userManager->get($booking->need('uid'));

        $dateFormatHelper  = $this->dateFormatHelper;
        $dateRangerHelper  = $this->dateRangeHelper;
        $priceFormatHelper = $this->priceFormatHelper;

        $reservationTimeStart = explode(':', $reservation->need('time_start'));
        $reservationTimeEnd   = explode(':', $reservation->need('time_end'));

        $reservationStart = new \DateTime($reservation->need('date'));
        $reservationStart->setTime($reservationTimeStart[0], $reservationTimeStart[1]);

        $reservationEnd = new \DateTime($reservation->need('date'));
        $reservationEnd->setTime($reservationTimeEnd[0], $reservationTimeEnd[1]);

        $vCalendar = new \Eluceo\iCal\Component\Calendar($this->optionManager->get('client.website'));
        $vEvent    = new \Eluceo\iCal\Component\Event();
        $vEvent
            ->setDtStart($reservationStart)
            ->setDtEnd($reservationEnd)
            ->setNoTime(false)
            ->setSummary(
                $this->optionManager->get('client.name.full')
                . ' - Snooker Booking - Table '
                . $square->need('name')
            );

        $vCalendar->addComponent($vEvent);

        $subject = sprintf(
            $this->t('Your %s-booking for %s'),
            $this->optionManager->get('subject.square.type'),
            $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT)
        );

        $message = sprintf(
            $this->t('We have reserved %s %s, %s for you. The reserved period is %s.'),
            $this->optionManager->get('subject.square.type'),
            $square->need('name'),
            $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE),
            $dateRangerHelper($reservationStart, $reservationEnd),
            $this->optionManager->need('subject.square.type')
        );

        if ($booking->get('price') > 0) {
            $message .= ' ' . sprintf(
                $this->t('The total price is %s.'),
                $priceFormatHelper($booking->get('price'))
            );
        }

        if ($square->get('additional_email_text')) {
            $message .= "\n\n" . $square->get('additional_email_text');
        }

        $message .= "\n\n" . $this->t('With kind regards') . ",\n"
            . $this->optionManager->get('client.name.full') . "\n\n"
            . $this->t('Contact phone') . ': ' . $this->optionManager->get('client.phone') . "\n"
            . $this->t('Contact e-mail') . ': ' . $this->optionManager->get('client.email');

        $this->userMailService->sendMessage(
            $user,
            $subject,
            $message,
            $vCalendar->render()
        );

        // Notify backend
        $backendSubject = sprintf(
            $this->t('New %s-booking by %s'),
            $this->optionManager->get('subject.square.type'),
            $user->need('alias')
        );

        $backendMessage = sprintf(
            $this->t('%s has booked %s %s, %s (%s).'),
            $user->need('alias'),
            $this->optionManager->get('subject.square.type'),
            $square->need('name'),
            $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE),
            $dateRangerHelper($reservationStart, $reservationEnd)
        );

        if ($booking->get('price') > 0) {
            $backendMessage .= ' ' . sprintf(
                $this->t('The total price is %s.'),
                $priceFormatHelper($booking->get('price'))
            );
        }

        $this->backendMailService->send(
            $backendSubject,
            $backendMessage
        );
    }

    public function onCancelSingle(Event $event)
    {
        $booking      = $event->getTarget();
        $reservations = $this->reservationManager->getBy(
            ['bid' => $booking->need('bid')],
            'date ASC',
            1
        );
        $reservation  = current($reservations);
        $square       = $this->squareManager->get($booking->need('sid'));
        $user         = $this->userManager->get($booking->need('uid'));

        $dateFormatHelper  = $this->dateFormatHelper;
        $dateRangerHelper  = $this->dateRangeHelper;
        $priceFormatHelper = $this->priceFormatHelper;

        $reservationTimeStart = explode(':', $reservation->need('time_start'));
        $reservationTimeEnd   = explode(':', $reservation->need('time_end'));

        $reservationStart = new \DateTime($reservation->need('date'));
        $reservationStart->setTime($reservationTimeStart[0], $reservationTimeStart[1]);

        $reservationEnd = new \DateTime($reservation->need('date'));
        $reservationEnd->setTime($reservationTimeEnd[0], $reservationTimeEnd[1]);

        // Notify users who registered interest for this day/slot
        if ($this->bookingInterestService instanceof BookingInterestService) {
            try {
                $this->bookingInterestService->notifyCancellation([
                    'id'          => $booking->need('bid'),
                    'start'       => $reservationStart,
                    'end'         => $reservationEnd,
                    'square_name' => $square->need('name'),
                ]);
            } catch (\Throwable $e) {
                error_log(
                    'SSA NotificationListener::onCancelSingle notifyCancellation failed: ' .
                    $e->getMessage()
                );
            }
        }

        $subject = sprintf(
            $this->t('Your %s-booking has been cancelled'),
            $this->optionManager->get('subject.square.type')
        );

        $message = sprintf(
            $this->t('we have just cancelled %s %s, %s for you.'),
            $this->optionManager->get('subject.square.type'),
            $square->need('name'),
            $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE)
        );

        if ($square->get('additional_email_text')) {
            $message .= "\n\n" . $square->get('additional_email_text');
        }

        $message .= "\n\n" . $this->t('With kind regards') . ",\n"
            . $this->optionManager->get('client.name.full') . "\n\n"
            . $this->t('Contact phone') . ': ' . $this->optionManager->get('client.phone') . "\n"
            . $this->t('Contact e-mail') . ': ' . $this->optionManager->get('client.email');

        $this->userMailService->sendMessage(
            $user,
            $subject,
            $message
        );

        // Notify backend
        $backendSubject = sprintf(
            $this->t('%s-booking cancelled by %s'),
            ucfirst($this->optionManager->get('subject.square.type')),
            $user->need('alias')
        );

        $backendMessage = sprintf(
            $this->t('%s has cancelled their %s %s, %s (%s).'),
            $user->need('alias'),
            $this->optionManager->get('subject.square.type'),
            $square->need('name'),
            $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE),
            $dateRangerHelper($reservationStart, $reservationEnd)
        );

        $bills = $this->bookingBillManager->getByBooking($booking);

        if (count($bills) > 0) {
            $backendMessage .= "\n\n" . $this->t('Bill') . ":\n";

            $total = 0;

            foreach ($bills as $bill) {
                $total += $bill->get('price');

                $items      = 'x';
                $squareUnit = '';

                if ($bill->get('quantity') == 1) {
                    $items      = '';
                    $squareUnit = $this->optionManager->get('subject.square.type.unit');
                }

                $backendMessage .= sprintf(
                    "%s %s %s (%s)\n",
                    $bill->get('quantity') . $items,
                    $squareUnit,
                    $bill->needExtra('label'),
                    $priceFormatHelper($bill->get('price'))
                );
            }

            $backendMessage .= sprintf(
                "\n" . $this->t('Total') . ": %s",
                $priceFormatHelper($total)
            );
        }

        $this->backendMailService->send(
            $backendSubject,
            $backendMessage
        );
    }

    protected function t($message, $textDomain = 'default', $locale = null)
    {
        return $this->translator->translate($message, $textDomain, $locale);
    }
}
