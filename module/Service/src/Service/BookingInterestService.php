<?php

namespace Service\Service;

use Zend\Db\Adapter\Adapter;
use Zend\Db\TableGateway\TableGateway;
use Zend\Db\Sql\Select;
use Zend\Mail\Message;
use Zend\Mail\Transport\TransportInterface;

class BookingInterestService
{
    protected $tg;
    protected $mail;
    protected $mailCfg;
    protected $whatsApp;

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

    public function registerInterest($userId, \DateTimeInterface $date)
    {
        $userId = (int) $userId;
        $d      = $date->format('Y-m-d');

        try {
            $this->tg->insert([
                'user_id'       => $userId,
                'interest_date' => $d,
                'created_at'    => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // ignore duplicate key etc.
        }
    }

    public function hasInterest($userId, \DateTimeInterface $date)
    {
        $userId = (int) $userId;
        $d      = $date->format('Y-m-d');

        $select = new Select('bs_booking_interest');
        $select->where([
            'user_id'       => $userId,
            'interest_date' => $d,
        ]);

        $rows = $this->tg->selectWith($select);

        foreach ($rows as $row) {
            return true;
        }

        return false;
    }

    public function notifyCancellation(array $booking)
    {
        $startLog = isset($booking['start'])
            ? (is_object($booking['start'])
                ? $booking['start']->format('Y-m-d H:i:s')
                : (string) $booking['start'])
            : 'NULL';

        error_log('SSA BookingInterestService::notifyCancellation START, start=' . $startLog);

        $start = isset($booking['start']) ? $booking['start'] : null;
        if (! $start) {
            error_log('SSA BookingInterestService::notifyCancellation: no start date, abort');
            return;
        }

        if (! $start instanceof \DateTimeInterface) {
            $start = new \DateTime($start);
        }

        $dateStr = $start->format('Y-m-d');
        error_log('SSA BookingInterestService::notifyCancellation: interest_date=' . $dateStr);

        $select = new Select('bs_booking_interest');
        $select->where(['interest_date' => $dateStr]);

        $rows    = $this->tg->selectWith($select);
        $rowList = [];
        foreach ($rows as $row) {
            $rowList[] = $row;
        }

        $rowCount = count($rowList);
        error_log('SSA BookingInterestService::notifyCancellation: interest rows found=' . $rowCount);

        if ($rowCount === 0) {
            return;
        }

        $userIds = [];
        foreach ($rowList as $row) {
            $uIdFromRow = null;

            if (isset($row['user_id'])) {
                $uIdFromRow = (int) $row['user_id'];
            } elseif (isset($row['uid'])) {
                $uIdFromRow = (int) $row['uid'];
            }

            if ($uIdFromRow !== null) {
                $userIds[] = $uIdFromRow;
            }
        }

        $userIds = array_values(array_unique($userIds));
        error_log('SSA BookingInterestService::notifyCancellation: userIds=' . implode(',', $userIds));

        if (empty($userIds)) {
            error_log('SSA BookingInterestService::notifyCancellation: no userIds resolved from interest rows');
            return;
        }

        $users = $this->fetchUserContacts($userIds);

        if (empty($users)) {
            error_log('SSA BookingInterestService::notifyCancellation: fetchUserContacts returned empty map');
        }

        $emailBody  = $this->buildEmailBody($booking);
        $waUserText = $this->buildWhatsAppUserText($booking);

        $sentEmails = [];

        foreach ($userIds as $uid) {
            if (! isset($users[$uid])) {
                error_log('SSA BookingInterestService::notifyCancellation: no contact record for uid=' . $uid);
                continue;
            }

            $contact = $users[$uid];

            // Email notifications — treat “registered interest” as consent
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
                    'SSA BookingInterestService::notifyCancellation: NOT sending email to uid=%d (flag=%s, email=%s)',
                    $uid,
                    isset($contact['notify_cancel_email']) ? (string) $contact['notify_cancel_email'] : 'null',
                    isset($contact['email']) ? (string) $contact['email'] : 'null'
                ));
            }

            // WhatsApp notifications
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
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($rowList as $row) {
            $pk = null;

            if (isset($row['id'])) {
                $pk = (int) $row['id'];
            } elseif (isset($row['iid'])) {
                $pk = (int) $row['iid'];
            }

            if ($pk !== null) {
                try {
                    $this->tg->update(
                        ['notified_at' => $now],
                        ['id' => $pk]
                    );

                    error_log('SSA BookingInterestService::notifyCancellation: marked notified_at for interest id=' . $pk);
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        'SSA BookingInterestService::notifyCancellation: FAILED to update notified_at for interest id=%d: %s',
                        $pk,
                        $e->getMessage()
                    ));
                }
            }
        }

        error_log('SSA BookingInterestService::notifyCancellation END');
    }

    protected function buildEmailBody(array $booking)
    {
        $square = isset($booking['square_name']) ? $booking['square_name'] : 'Selected court';

        $start = isset($booking['start']) ? $booking['start'] : null;
        $end   = isset($booking['end']) ? $booking['end'] : null;

        $slot = $this->formatSlot($start, $end);

        $body  = "Good news!\n\n";
        $body .= "A booking has just been cancelled for {$square}.\n";
        $body .= "Date and time: {$slot}\n\n";
        $body .= "If you are still interested in this slot, please log in and make a booking as soon as possible.\n\n";
        $body .= "Best regards,\n";
        $body .= "Surrey Snooker Academy\n";

        return $body;
    }

    protected function buildWhatsAppUserText(array $booking)
    {
        $square = isset($booking['square_name']) ? $booking['square_name'] : 'Selected court';

        $start = isset($booking['start']) ? $booking['start'] : null;
        $end   = isset($booking['end']) ? $booking['end'] : null;

        $slot = $this->formatSlot($start, $end);

        $text  = "Free slot available at Surrey Snooker Academy!\n";
        $text .= "{$square}, {$slot}\n";
        $text .= "Book now via the SSA booking system.";

        return $text;
    }

    protected function formatSlot($start, $end = null)
    {
        if (! $start) {
            return 'the selected date/time';
        }

        if ($start instanceof \DateTimeInterface) {
            $s = $start;
        } else {
            $s = new \DateTime($start);
        }

        if ($end) {
            if ($end instanceof \DateTimeInterface) {
                $e = $end;
            } else {
                $e = new \DateTime($end);
            }
            return $s->format('Y-m-d H:i') . ' – ' . $e->format('H:i');
        }

        return $s->format('Y-m-d');
    }

    protected function sendEmail($to, $body)
    {
        $fromEmail = isset($this->mailCfg['from_email']) ? $this->mailCfg['from_email'] : 'no-reply@example.com';
        $fromName  = isset($this->mailCfg['from_name']) ? $this->mailCfg['from_name'] : 'Booking System';
        $subject   = isset($this->mailCfg['subject']) ? $this->mailCfg['subject'] : 'A free slot is now available';

        $message = new Message();
        $message->setFrom($fromEmail, $fromName);
        $message->addTo($to);
        $message->setSubject($subject);
        $message->setBody($body);

        $this->mail->send($message);
    }

    protected function sendWhatsApp($phone, $text)
    {
        if (! $this->whatsApp) {
            return;
        }

        $this->whatsApp->sendMessage($phone, $text);
    }

    protected function fetchUserContacts(array $userIds)
    {
        if (empty($userIds)) {
            return [];
        }

        $userIds = array_map('intval', $userIds);
        $in      = implode(',', $userIds);

        $sql = "SELECT uid, email, phone, notify_cancel_email, notify_cancel_whatsapp
                FROM bs_users
                WHERE uid IN ($in)";

        $result = $this->tg->getAdapter()->query($sql, []);

        $map = [];
        foreach ($result as $row) {
            $uid = (int) $row['uid'];

            $map[$uid] = [
                'email'                  => $row['email'],
                'phone'                  => $row['phone'],
                'notify_cancel_email'    => isset($row['notify_cancel_email']) ? (int) $row['notify_cancel_email'] : 0,
                'notify_cancel_whatsapp' => isset($row['notify_cancel_whatsapp']) ? (int) $row['notify_cancel_whatsapp'] : 0,
            ];
        }

        return $map;
    }

    public function cleanupOldInterests($days = 30)
    {
        $cutoff = (new \DateTime("-{$days} days"))->format('Y-m-d');

        return $this->tg->delete("interest_date < '{$cutoff}' OR notified_at IS NOT NULL");
    }


    protected function sendTwilioSms($to, $body)
    {
        require_once __DIR__ . '/../../../vendor/autoload.php'; // adjust if needed

        $sid         = getenv('TWILIO_MESSAGING_SERVICE_SID');
        $token       = getenv('TWILIO_AUTH_TOKEN');
        $twilioFrom  = getenv('TWILIO_WHATSAPP_FROM'); // must be +44… etc

        if (! $sid || ! $token || ! $twilioFrom) {
            error_log("Twilio SMS skipped: missing SID/TOKEN/FROM");
            return;
        }

        // Normalise UK number
        $to = $this->normalizeUkNumber($to);

        try {
            $client = new \Twilio\Rest\Client($sid, $token);

            $message = $client->messages->create(
                $to,
                [
                    'from' => $twilioFrom,
                    'body' => $body
                ]
            );

            error_log("Twilio SMS sent: SID={$message->sid} to={$to}");
        }
        catch (\Exception $e) {
            error_log("Twilio SMS FAILED: " . $e->getMessage());
        }
    }

    protected function normalizeUkNumber($number)
    {
        // Strip all non-digits
        $number = preg_replace('/\D+/', '', $number);

        if ($number === '') {
            return $number;
        }

        if (strpos($number, '0') === 0) {
            // 07xxxxxxxx → +447xxxxxxxx
            return '+44' . substr($number, 1);
        }

        if (strpos($number, '44') === 0) {
            // 44xxxxxxxx → +44xxxxxxxx
            return '+' . $number;
        }

        if (strpos($number, '44') === 0) {
            return '+44' . substr($number, 2);
        }

        // Already +44… or other country code? If it starts with +, leave as is.
        if (strpos($number, '+') === 0) {
            return $number;
        }

        // Fallback: assume UK, prefix +44
        return '+44' . $number;
    }
}
