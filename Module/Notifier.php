<?php

require_once dirname(__FILE__) . "/Logger.php";

class Notifier
{
    private string $envelopeFrom;
    private string $from;

    public function __construct(string $envelopeFrom = '', string $from = '')
    {
        $this->envelopeFrom = $envelopeFrom;
        $this->from = $from;
    }

    /**
     * @param array $message
     *  [
     *      'subject' => '',
     *      'name'    => '',
     *      'email'   => '',
     *      'content' => ''
     *  ]
     * @param array $notifyingList
     *  [
     *      ['type' => 'mail', 'destination' => ''],
     *      ['type' => 'url',  'destination' => ''],
     *      ...
     *  ]
     */
    public function notify($message, $notifyingList)
    {
        $succeed = false;
        foreach ($notifyingList as $notifying) {
            $destination = $notifying['destination'];
            $type        = $notifying['type'];
            $result = true;
            switch ($type) {
                case 'mail':
                    $result = $this->mailTo($message, $destination);
                    break;
                case 'url':
                    $result = $this->getRequestTo($message, $destination);
                    break;
                default:
                    logger()->warning('[Notifier::notify] Unknown type "' . $type . '"');
                    break;
            }
            if ($result === false) {
                logger()->error("[Notifier::notify] fail to send message. type: {$type}, destination: {$destination}");
            } else {
                $succeed = true;
            }
        }
        if (!$succeed) {
            logger()->warning(
                "[Notifier::notify] This message was not sent to any destinations.\n" .
                    "----- Message -----\n" .
                    print_r($message, true) .
                    "-------------------"
            );
        }
        return $succeed;
    }

    public function mailTo($message, $destination)
    {
        if (filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
            logger()->warning('[Notifier::mailTo] Invalid email address "' . $destination . '"');
            return false;
        }

        // The From header must use an address on a domain we are authorized to
        // send from (SPF/DKIM aligned). Using the visitor's address here makes
        // receivers such as Gmail reject the mail due to the sender's DMARC
        // policy. The visitor's address goes into Reply-To instead, so the
        // recipient can still reply directly to them.
        $from = $this->from !== '' ? $this->from : $this->envelopeFrom;

        // Use the visitor's name as the From display name (MIME-encoded so that
        // non-ASCII names are valid; this also neutralizes header injection).
        $displayName = trim(str_replace(["\r", "\n"], '', (string) ($message['name'] ?? '')));
        $fromHeader = $displayName !== ''
            ? mb_encode_mimeheader($displayName, 'UTF-8') . ' <' . $from . '>'
            : $from;

        $replyTo = filter_var($message['email'], FILTER_VALIDATE_EMAIL) !== false
            ? $message['email']
            : $from;

        $headers = [
            'Content-Type' => "text/plain; charset=UTF-8",
            'From'         => $fromHeader,
            'Reply-To'     => $replyTo
        ];

        $body =
            "Subject: " . $message['subject'] . "\n" .
            "Name   : " . $message['name'] .    "\n" .
            "Email  : " . $message['email'] .   "\n" .
            "Content: \n" .
            $message['content'] . "\n";

        $body = str_replace("\r", "", $body);
        $body = str_replace("\n", "\r\n", $body);

        $additionalParams = $this->envelopeFrom !== ''
            ? '-f' . $this->envelopeFrom
            : '';

        return @mail($destination, $message['subject'], $body, $headers, $additionalParams);
    }

    public function getRequestTo($message, $destination)
    {
        $queryString = '?';
        foreach ($message as $key => $value) {
            $queryString .= $key . '=' . urlencode($value) . '&';
        }
        $queryString = substr($queryString, 0, -1); // remove last char('?' or '&')
        return @file_get_contents($destination . $queryString);
    }
}

/**
 * Notifierの共有インスタンスを返す。
 */
function notifier(): Notifier
{
    static $instance = null;
    if ($instance === null) {
        $envelopeFrom = defined('MAIL_ENVELOPE_FROM') ? MAIL_ENVELOPE_FROM : '';
        $instance = new Notifier(
            $envelopeFrom,
            defined('MAIL_FROM') ? MAIL_FROM : $envelopeFrom
        );
    }
    return $instance;
}
