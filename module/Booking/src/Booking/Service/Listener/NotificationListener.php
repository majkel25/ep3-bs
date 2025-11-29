<?php
//michael 1
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
use Zend\Db\Adapter\Adapter;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\Event;
use Zend\EventManager\EventManagerInterface;
use Zend\I18n\View\Helper\DateFormat;
use Zend\Mvc\I18n\Translator;

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
     * @var Translator
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
     * @var Adapter
     */
    protected $dbAdapter;

    public function __construct(
        OptionManager $optionManager,
        ReservationManager $reservationManager,
        SquareManager $squareManager,
        UserManager $userManager,
        UserMailService $userMailService,
        BackendMailService $backendMailService,
        DateFormat $dateFormatHelper,
        DateRange $dateRangeHelper,
        Translator $translator,
        BillManager $bookingBillManager,
        PriceFormatPlain $priceFormatHelper,
        Adapter $dbAdapter
    ) {
        $this->optionManager      = $optionManager;
        $this->reservationManager = $reservationManager;
        $this->squareManager      = $squareManager;
        $this->userManager        = $userManager;
        $this->userMailService    = $userMailService;
        $this->backendMailService = $backendMailService;
        $this->bookingBillManager = $bookingBillManager;
        $this->dateFormatHelper   = $dateFormatHelper;
        $this->dateRangeHelper    = $dateRangeHelper;
        $this->translator         = $translator;
        $this->priceFormatHelper  = $priceFormatHelper;
        $this->dbAdapter          = $dbAdapter;
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

        // Calendar object kept but not attached (MailService::send expects attachments array)
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

        // Convert to ICS file content
        $icsContent = $vCalendar->render(); 

        // MailService expects array of attachments:
        $attachments = [
            [
                'name'     => 'booking.ics',
                'type'     => 'text/calendar',
                'content'  => $icsContent,
            ],
        ];

        // Friendly subject:
        $subject = sprintf(
            'Your booking for Table %s %s',
            $square->need('name'),
            $reservationStart->format('j M Y')
        );

        // Build clean body:
        $userName  = $user->need('alias');
        $tableName = $square->need('name');

        $dateStr  = $reservationStart->format('j M Y');
        $startStr = $reservationStart->format('H:i');
        $endStr   = $reservationEnd->format('H:i');

        $message  = "Dear {$userName},\n\n";
        $message .= "We have reserved Table {$tableName}, from {$dateStr}, {$startStr} to {$endStr} for you. ";
        $message .= "Thank you for your booking.\n\n";
        $message .= "Enjoy your snooker.\n";

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
            . $this->optionManager->get('client.name.full') . "\n\n";
        //    . $this->t('Contact phone') . ': ' . $this->optionManager->get('client.phone') . "\n"
        //    . $this->t('Contact e-mail') . ': ' . $this->optionManager->get('client.email');

        // Use existing MailService::send signature (recipient, subject, text)
        $this->userMailService->send(
            $user,
            $subject,
            $message,
            $attachments
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

        // === Notify users who registered interest for this date ===
        try {
            $this->notifyInterestedUsers(
                $reservationStart,
                $reservationEnd,
                $square->need('name')
            );
        } catch (\Throwable $e) {
            // Do not break cancellation flow if interest notification fails
            error_log(
                'SSA NotificationListener::onCancelSingle notifyInterestedUsers failed: ' .
                $e->getMessage()
            );
        }

        // === Existing cancellation email to booking owner ===
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

        $this->userMailService->send(
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

        // Bill breakdown removed – your BillManager doesn’t expose getByBooking()
        $this->backendMailService->send(
            $backendSubject,
            $backendMessage
        );
    }

    /**
     * Notify users who registered interest for the day of this cancellation.
     * We treat "registration of interest" as consent, ignoring notify_cancel_email flag.
     */
    protected function notifyInterestedUsers(\DateTime $start, \DateTime $end, $squareName)
{
    if (! $this->dbAdapter) {
        return;
    }

    $dateStr = $start->format('Y-m-d');

    // 1) Get all interests for this date (no assumptions about notified_at)
    $sql    = 'SELECT * FROM bs_booking_interest WHERE interest_date = ?';
    $stmt   = $this->dbAdapter->createStatement($sql, array($dateStr));
    $result = $stmt->execute();

    $userIds = array();

    foreach ($result as $row) {
        $uid = null;

        if (isset($row['user_id'])) {
            $uid = (int) $row['user_id'];
        } elseif (isset($row['uid'])) {
            $uid = (int) $row['uid'];
        }

        if ($uid) {
            $userIds[] = $uid;
        }
    }

    $userIds = array_values(array_unique($userIds));

    if (empty($userIds)) {
        return;
    }

    // 2) For each user, send email ONLY if:
    //    - they have an email address, and
    //    - their profile flag notify_cancel_email is enabled (== 1)
    $fromName = $this->optionManager->get('client.name.full');


    //test addition
    foreach ($userIds as $uid) {
            if (! isset($users[$uid])) {
                error_log('SSA BookingInterestService::notifyCancellation: no contact record for uid=' . $uid);
                continue;
            }

            $contact = $users[$uid];

            // ---------------- EMAIL ----------------
            if (! empty($contact['email'])) {
                $email = $contact['email'];

                error_log(sprintf(
                    'SSA BookingInterestService::notifyCancellation: sending EMAIL to uid=%d <%s>',
                    $uid,
                    $email
                ));

                if (! isset($sentEmails[$email])) {
                    try {
                        $this->sendEmail($email, $emailBody);
                        $sentEmails[$email] = true;
                    } catch (\Throwable $e) {
                        error_log(sprintf(
                            'SSA BookingInterestService::notifyCancellation: sendEmail FAILED for <%s>: %s',
                            $email,
                            $e->getMessage()
                        ));
                    }
                }
            } else {
                error_log(sprintf(
                    'SSA BookingInterestService::notifyCancellation: NOT sending email to uid=%d (email empty)',
                    $uid
                ));
            }

            // ---------------- WHATSAPP ----------------
            if ($this->whatsApp
                && ! empty($contact['notify_cancel_whatsapp'])
                && ! empty($contact['phone'])
            ) {
                $phone = $contact['phone'];

                error_log(sprintf(
                    'SSA BookingInterestService::notifyCancellation: sending WHATSAPP to uid=%d (%s)',
                    $uid,
                    $phone
                ));

                try {
                    $this->sendWhatsApp($phone, $waUserText);
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        'SSA BookingInterestService::notifyCancellation: sendWhatsApp FAILED for %s: %s',
                        $phone,
                        $e->getMessage()
                    ));
                }
            }

            // ---------------- TWILIO SMS ----------------
            if (! empty($contact['phone'])) {
                $phone = $contact['phone'];

                error_log(sprintf(
                    'SSA BookingInterestService::notifyCancellation: sending Twilio SMS to uid=%d (%s)',
                    $uid,
                    $phone
                ));

                try {
                    $this->sendTwilioSms($phone, $waUserText);
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        'SSA BookingInterestService::notifyCancellation: sendTwilioSms FAILED for %s: %s',
                        $phone,
                        $e->getMessage()
                    ));
                }
            } else {
                error_log(sprintf(
                    'SSA BookingInterestService::notifyCancellation: NOT sending SMS, no phone for uid=%d',
                    $uid
                ));
            }
        }
// end of test addition


 

    protected function t($message, $textDomain = 'default', $locale = null)
    {
        return $this->translator->translate($message, $textDomain, $locale);
    }
}
