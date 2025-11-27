<?php
//michael 1
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
use Zend\I18n\Translator\TranslatorInterface;
use Zend\I18n\View\Helper\DateFormat;

class NotificationListener extends AbstractListenerAggregate
{

    protected $optionManager;
    protected $reservationManager;
    protected $squareManager;
    protected $userManager;
    protected $bookingBillManager;
    protected $bookingInterestService;
    protected $userMailService;
	protected $backendMailService;
    protected $dateFormatHelper;
    protected $dateRangeHelper;
    protected $translator;
    protected $priceFormatHelper;

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
            $this->t('We have reserved %s %s, %s for you. The reservation will be cancelled automatically if you are not present within 10 minutes of the chosen time.'),
            $this->optionManager->get('subject.square.type'),
            $square->need('name'),
            $dateRangerHelper($reservationStart, $reservationEnd)
        );

        $bills = $this->bookingBillManager->getBy(array('bid' => $booking->get('bid')), 'bbid ASC');

        if (count($bills) > 0) {
            $message .= "\n\n" . $this->t('Bill') . ":\n";

            $total = 0;

            foreach ($bills as $bill) {

                $total += $bill->get('price');

                $items      = 'x';
                $squareUnit = '';

                if ($bill->get('quantity') == 1) {
                    $items      = '';
                    $squareUnit = $this->optionManager->get('subject.square.unit.singular');
                } else {
                    $squareUnit = $this->optionManager->get('subject.square.unit.plural');
                }

                $message .= sprintf("    %s %.2f %s\n", $items, $bill->get('quantity'), $squareUnit);
            }

            $message .= "\n";
            $message .= sprintf($this->t('Total: %s'), $priceFormatHelper($total));
        }

        if ($booking->get('comment')) {
            $message .= "\n\n" . $this->t('Your comment') . ":\n";
            $message .= $booking->get('comment');
        }

        if ($user->getMeta('notification.bookings', 'true') == 'true') {
            $icsData = $vCalendar->render();
            $icsFile = tempnam(sys_get_temp_dir(), 'booking');
            file_put_contents($icsFile, $icsData);

            $this->userMailService->send(
                $user,
                $subject,
                $message,
                array($icsFile => 'booking.ics')
            );

            @unlink($icsFile);
        }

	    if ($this->optionManager->get('client.contact.email.user-notifications')) {

		    $backendSubject = sprintf(
                $this->t('%s\'s %s-booking for %s'),
		        $user->need('alias'),
                $this->optionManager->get('subject.square.type'),
                $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT)
            );

		    $addendum = sprintf(
                $this->t('Originally sent to %s (%s).'),
	            $user->need('alias'),
                $user->need('email')
            );

	        $this->backendMailService->send($backendSubject, $message, array(), $addendum);
        }
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

        $dateRangerHelper = $this->dateRangeHelper;

	    $reservationTimeStart = explode(':', $reservation->need('time_start'));
        $reservationTimeEnd   = explode(':', $reservation->need('time_end'));

        $reservationStart = new \DateTime($reservation->need('date'));
        $reservationStart->setTime($reservationTimeStart[0], $reservationTimeStart[1]);

        $reservationEnd = new \DateTime($reservation->need('date'));
        $reservationEnd->setTime($reservationTimeEnd[0], $reservationTimeEnd[1]);

        // NEW: notify users who registered interest for this day/slot
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
            $dateRangerHelper($reservationStart, $reservationEnd)
        );

        if ($user->getMeta('notification.bookings', 'true') == 'true') {
            $this->userMailService->send($user, $subject, $message);
        }

	    if ($this->optionManager->get('client.contact.email.user-notifications')) {

		    $backendSubject = sprintf(
                $this->t('%s\'s %s-booking has been cancelled'),
		        $user->need('alias'),
                $this->optionManager->get('subject.square.type')
            );

		    $addendum = sprintf(
                $this->t('Originally sent to %s (%s).'),
	            $user->need('alias'),
                $user->need('email')
            );

	        $this->backendMailService->send($backendSubject, $message, array(), $addendum);
        }
    }

    protected function t($message)
    {
        return $this->translator->translate($message);
    }

}
