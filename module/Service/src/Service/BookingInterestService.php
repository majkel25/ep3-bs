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

    /**
     * Register a user's interest in a given date.
     *
     * @param int $userId
     * @param \DateTimeInterface $date
     */
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
        // ---- DEBUG: log incoming booking data ----
        $startLog = isset($booking['start'])
            ? (is_object($booking['start'])
                ? $booking['start']->format('Y-m-d H:i:s')
                : (string) $booking['start'])
            : 'NULL';

        error_log('SSA BookingInterestService::notifyCancellation START, start=' . $startLog);

        // Normalise start -> DateTime
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

        // ---- Load interest rows for that date ----
        $select = new Select('bs_booking_interest');
        $select->where(['interest_date' => $dateStr]);

        $rows    = $this->tg->selectWith($select);
        $rowList = [];
        foreach ($rows as $row) {
            // buffer rows so we can iterate multiple times
            $rowList[] = $row;
        }

        $rowCount = count($rowList);
        error_log('SSA BookingInterestService::notifyCancellation: interest rows found=' . $rowCount);

        if ($rowCount === 0) {
            // Nothing to notify
            return;
        }

        // ---- Build list of user IDs from rows (support user_id OR uid) ----
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

        if (empty($userIds)) {
            error_log('SSA BookingInterestService::notifyCancellation: no userIds collected');
            return;
        }

        // ---- Fetch user contact data from bs_users ----
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

            // Email
            // SSA CHANGE: treat "registered interest" as full consent; send email if address exists
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

            // WhatsApp (optional)
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

        // ---- Mark interest rows as notified ----
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

    /**
     * Build the email body for the “free slot” notification.
     *
     * @param array $booking
     * @return string
     */
    protected function buildEmailBody(array $booking)
    {
        $square = isset($booking['square_name']) ? $booking['square_name'] : 'Selected court';
        $when   = $this->formatSlot($booking);

        // IMPORTANT: handle DateTime OR string safely
        if (isset($booking['start'])) {
            $start = $booking['start'];

            if ($start instanceof \DateTimeInterface) {
                $when = $start->format('l, d.m.Y H:i');
            } else {
                try {
                    $dt = new \DateTime((string) $start);
                    $when = $dt->format('l, d.m.Y H:i');
                } catch (\Exception $e) {
                    // keep default
                }
            }
        }

        $body  = "Good news!\n\n";
        $body .= "A booking has just been cancelled for {$square}.\n";
        $body .= "Date and time: {$when}\n\n";
        $body .= "If you are still interested in this slot, please log in and make a booking as soon as possible.\n\n";
        $body .= "Best regards,\n";
        $body .= "Surrey Snooker Academy\n";

        return $body;
    }

    /**
     * Build a short WhatsApp text for the user.
     *
     * @param array $booking
     * @return string
     */
    protected function buildWhatsAppUserText(array $booking)
    {
        $square = isset($booking['square_name']) ? $booking['square_name'] : 'Selected court';
        $when   = $this->formatSlot($booking);

        if (isset($booking['start'])) {
            $start = $booking['start'];

            if ($start instanceof \DateTimeInterface) {
                $when = $start->format('d.m.Y H:i');
            } else {
                try {
                    $dt = new \DateTime((string) $start);
                    $when = $dt->format('d.m.Y H:i');
                } catch (\Exception $e) {
                    // keep default
                }
            }
        }

        $text  = "Free slot available at Surrey Snooker Academy!\n";
        $text .= "{$square}, {$when}\n";
        $text .= "Book now via the SSA booking system.";

        return $text;
    }

    /**
     * Simple helper to format the slot.
     *
     * @param array $booking
     * @return string
     */
    protected function formatSlot(array $booking)
    {
        if (isset($booking['start']) && $booking['start'] instanceof \DateTimeInterface) {
            $start = $booking['start'];
            return $start->format('d.m.Y H:i');
        }

        return 'the selected date/time';
    }

    /**
     * Send an email using the configured transport.
     *
     * @param string $to
     * @param string $body
     */
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

    /**
     * Send a WhatsApp message (if integration is provided).
     *
     * @param string $phone
     * @param string $text
     */
    protected function sendWhatsApp($phone, $text)
    {
        if (! $this->whatsApp) {
            return;
        }

        // $this->whatsApp is expected to be some kind of client, injected via factory
        $this->whatsApp->sendMessage($phone, $text);
    }

    /**
     * Fetch user contact data (email/phone + notification flags).
     *
     * @param array $userIds
     * @return array [uid => [email, phone, notify_cancel_email, notify_cancel_whatsapp]]
     */
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
}
