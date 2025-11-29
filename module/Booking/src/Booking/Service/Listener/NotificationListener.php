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

    foreach ($userIds as $uid) {
        try {
            $user = $this->userManager->get($uid);
        } catch (\Exception $e) {
            continue;
        }

        if (! $user) {
            continue;
        }

        // Respect user preference: notify_cancel_email
        // If the field does not exist or is 0, we do NOT send.
        $notifyFlag = (int) $user->get('notify_cancel_email');
        if ($notifyFlag !== 1) {
            // user has not opted in for free-slot / cancellation notifications
            continue;
        }

        $email = $user->get('email');
        if (! $email) {
            continue;
        }

        $subject = 'A table has become available';
        $body    = "Good news!\n\n";
        $body   .= "A booking has just been cancelled for Table {$squareName}.\n";
        $body   .= "Date and time: " . $start->format('d.m.Y H:i') . ' - ' . $end->format('H:i') . "\n\n";
        $body   .= "If you are still interested in this slot, please log in and make a booking as soon as possible.\n\n";
        $body   .= "Best regards,\n";
        $body   .= $fromName . "\n";

        $this->userMailService->send(
            $user,
            $subject,
            $body
        );
    

        // ---------------------------------------------------------
        // SMS / TEXT NOTIFICATION VIA TWILIO (HARD-CODED TEST)
        //
        // Reuse notify_cancel_whatsapp as the "mobile alert" flag.
        // ---------------------------------------------------------
        $notifySms = (int) $user->get('notify_cancel_whatsapp');
        $phone  = $user->getMeta('phone');
        

        // For now we ignore the user's stored phone and always send to a fixed test number
        if ($notifySms === 1) {

            // Very simple test message
            $smsBody  = "SSA Message: Cancellation Notification.\n";
            $smsBody .= "There was a Cancellation on the day you registered interest\n";
            $smsBody .= "Table: {$squareName}\n";
            $smsBody .= "Date: " . $start->format('d.m.Y') . "\n";
            $smsBody .= "Time: " . $start->format('H:i') . ' - ' . $end->format('H:i');
            $smsBody .= "is free now.\n";

            // Debug email so you can see this branch is being hit
                $debugBody  = "DEBUG: SMS branch hit.\n";
                $debugBody .= "Current user UID: {$uid}\n";
                $debugBody .= "notify_cancel_whatsapp: {$notifySms}\n";
                $debugBody .= "User phone (meta): {$phone}\n\n";

                // List all users who registered interest for this date
                $debugBody .= "Users registered interest for {$dateStr}:\n";
                $debugBody .= implode(', ', $userIds) . "\n";

                $this->userMailService->send(
                    $user,
                    'DEBUG: SMS branch hit',
                    $debugBody
                );

            // Hard-coded test number
            // TEST $this->sendTwilioSms('+447743960776', $smsBody);
            $this->sendTwilioSms($phone, $smsBody);
        }


    }
    // 3) Try to mark interests as notified if the column exists – ignore errors
    try {
        $now        = (new \DateTime())->format('Y-m-d H:i:s');
        $updateSql  = 'UPDATE bs_booking_interest SET notified_at = ? WHERE interest_date = ?';
        $updateStmt = $this->dbAdapter->createStatement($updateSql, array($now, $dateStr));
        $updateStmt->execute();
    } catch (\Throwable $e) {
        // If notified_at doesn't exist or update fails, we just ignore it.
    }
}

    /**
     * Send an SMS via Twilio using Messaging Service SID (no SDK, just cURL).
     * Uses TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_MESSAGING_SERVICE_SID env vars.
     */
    protected function sendTwilioSms($to, $body)
    {
        $sid        = getenv('TWILIO_ACCOUNT_SID');
        $token      = getenv('TWILIO_AUTH_TOKEN');
        $msgService = getenv('TWILIO_MESSAGING_SERVICE_SID');

        if (! $sid || ! $token || ! $msgService) {
            error_log(sprintf(
                'SSA NotificationListener::sendTwilioSms: missing env (SID=%s, TOKEN=%s, MSG_SID=%s)',
                $sid ? 'set' : 'missing',
                $token ? 'set' : 'missing',
                $msgService ? 'set' : 'missing'
            ));
            return;
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $data = [
            'To'                  => $to,
            'MessagingServiceSid' => $msgService,
            'Body'                => $body,
        ];

        $postFields = http_build_query($data, '', '&');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        try {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response === false) {
                $err = curl_error($ch);
                error_log('SSA NotificationListener::sendTwilioSms: cURL error=' . $err);
            } else {
                error_log(sprintf(
                    'SSA NotificationListener::sendTwilioSms: HTTP %d, response=%s',
                    $httpCode,
                    $response
                ));
            }
        } catch (\Throwable $e) {
            error_log('SSA NotificationListener::sendTwilioSms: exception=' . $e->getMessage());
        } finally {
            if (is_resource($ch)) {
                curl_close($ch);
            }
        }
    }

    protected function t($message, $textDomain = 'default', $locale = null)
    {
        return $this->translator->translate($message, $textDomain, $locale);
    }

}
