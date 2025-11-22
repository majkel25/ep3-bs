<?php
namespace Service\Service;

use Zend\Db\Adapter\Adapter;
use Zend\Db\TableGateway\TableGateway;
use Zend\Db\Sql\Select;
use Zend\Mail\Message;
use Zend\Mail\Transport\TransportInterface;

/**
 * Handles "I am interested in this day" and notifications on cancellation.
 *
 * DB tables:
 *  - bs_booking_interest (id, user_id, interest_date, created_at, notified_at)
 *  - bs_users (uid, email, phone, notify_cancel_email, notify_cancel_whatsapp)
 */
class BookingInterestService
{
    /**
     * @var TableGateway
     */
    protected $tg;

    /**
     * @var TransportInterface
     */
    protected $mail;

    /**
     * @var array
     */
    protected $mailCfg;

    /**
     * @var mixed|null WhatsAppService instance or null if unavailable
     */
    protected $whatsApp;

    /**
     * @param Adapter            $db
     * @param TransportInterface $mail
     * @param array              $mailCfg
     * @param mixed              $whatsApp  WhatsAppService|null
     */
    public function __construct(
        Adapter $db,
        TransportInterface $mail,
        array $mailCfg,
        $whatsApp = null
    ) {
        $this->tg       = new TableGateway('bs_booking_interest', $db);
        $this->mail     = $mail;
        $this->mailCfg  = $mailCfg;
        $this->whatsApp = $whatsApp;
    }

    /**
     * Register interest of a user for a particular date (whole day).
     *
     * @param int                $userId
     * @param \DateTimeInterface $date
     */
    public function registerInterest($userId, \DateTimeInterface $date)
    {
        $userId = (int) $userId;
        $d      = $date->format('Y-m-d');

        try {
            $this->tg->insert(array(
                'user_id'       => $userId,
                'interest_date' => $d,
                'created_at'    => (new \DateTime())->format('Y-m-d H:i:s'),
            ));
        } catch (\Exception $e) {
            // Unique key (user_id, interest_date) will throw on duplicate registrations – ignore.
        }
    }

    /**
     * Notify all interested users when a booking is cancelled.
     *
     * @param array $booking expects:
     *   - id
     *   - start (DateTime or string)
     *   - end   (DateTime or string|null)
     *   - square_name (optional nice label)
     */
    public function notifyCancellation(array $booking)
    {
        $start = isset($booking['start']) ? $booking['start'] : null;
        if (!$start) {
            return;
        }

        if (!$start instanceof \DateTimeInterface) {
            $start = new \DateTime($start);
        }

        $dateStr = $start->format('Y-m-d');

        $select = new Select('bs_booking_interest');
        $select->where(array('interest_date' => $dateStr));

        $rows = $this->tg->selectWith($select);
        if (count($rows) === 0) {
            return;
        }

        $userIds = array();
        foreach ($rows as $row) {
            $userIds[] = (int) $row['user_id'];
        }
        $userIds = array_values(array_unique($userIds));

        $users = $this->fetchUserContacts($userIds);

        $emailBody  = $this->buildEmailBody($booking);
        $waUserText = $this->buildWhatsAppUserText($booking);

        $sentEmails = array();

        foreach ($userIds as $uid) {
            if (!isset($users[$uid])) {
                continue;
            }

            $contact = $users[$uid];

            // Email
            if (!empty($contact['notify_cancel_email']) && !empty($contact['email'])) {
                $email = $contact['email'];
                if (!isset($sentEmails[$email])) {
                    $this->sendEmail($email, $emailBody);
                    $sentEmails[$email] = true;
                }
            }

            // WhatsApp – do not break if service is missing
            if ($this->whatsApp
                && !empty($contact['notify_cancel_whatsapp'])
                && !empty($contact['phone'])
            ) {
                $this->whatsApp->sendToNumber($contact['phone'], $waUserText);
            }
        }

        // Mark notifications as sent for this date
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $this->tg->update(
                array('notified_at' => $now),
                array('id' => (int) $row['id'])
            );
        }
    }

    /**
     * Load contact data for a set of user IDs.
     *
     * @param int[] $userIds
     * @return array uid => [email, phone, notify_cancel_email, notify_cancel_whatsapp]
     */
    protected function fetchUserContacts(array $userIds)
    {
        if (empty($userIds)) {
            return array();
        }

        $userIds = array_map('intval', $userIds);
        $in      = implode(',', $userIds);

        $sql = "SELECT uid, email, phone, notify_cancel_email, notify_cancel_whatsapp
                FROM bs_users
                WHERE uid IN ($in)";

        $result = $this->tg->getAdapter()->query($sql, array());

        $map = array();
        foreach ($result as $row) {
            $uid = (int) $row['uid'];

            $map[$uid] = array(
                'email'                  => $row['email'],
                'phone'                  => $row['phone'],
                'notify_cancel_email'    => isset($row['notify_cancel_email']) ? (int) $row['notify_cancel_email'] : 0,
                'notify_cancel_whatsapp' => isset($row['notify_cancel_whatsapp']) ? (int) $row['notify_cancel_whatsapp'] : 0,
            );
        }

        return $map;
    }

    /**
     * @param array $booking
     * @return string
     */
    protected function buildEmailBody(array $booking)
    {
        $square = isset($booking['square_name']) ? $booking['square_name'] : 'Selected court';
        $when   = $this->formatSlot($booking);

        $startDate = isset($booking['start']) ? new \DateTime($booking['start']) : null;
        $dateLine  = $startDate ? $startDate->format('Y-m-d') : 'selected day';

        $body  = "Hello,\n\n";
        $body .= "A booking on {$square} for {$when} was cancelled.\n";
        $body .= "A slot may now be available on the day you are watching.\n\n";
        $body .= "Watched date: {$dateLine}\n";
        $body .= "Court: {$square}\n";
        if (isset($booking['id'])) {
            $body .= "Reference: " . $booking['id'] . "\n";
        }
        $body .= "\nPlease open the booking system to try and reserve the freed slot.\n\n";
        $body .= "This is a one-time notification for this day.\n";

        return $body;
    }

    /**
     * @param string $to
     * @param string $body
     */
    protected function sendEmail($to, $body)
    {
        $message = new Message();
        $message->setSubject('Booking cancelled – a slot may be free');
        $message->addTo($to);

        $fromAddress = isset($this->mailCfg['address']) ? $this->mailCfg['address'] : 'no-reply@example.com';
        $message->setFrom($fromAddress, 'Bookings');

        $message->setBody($body);
        $this->mail->send($message);
    }

    /**
     * @param array $booking
     * @return string
     */
    protected function buildWhatsAppUserText(array $booking)
    {
        $square = isset($booking['square_name']) ? $booking['square_name'] : 'Selected court';
        $when   = $this->formatSlot($booking);

        $text  = "Booking alert \xF0\x9F\x94\x94\n"; // 🔔
        $text .= "A bo
