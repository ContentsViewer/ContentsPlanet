<?php

require_once dirname(__FILE__) . "/Debug.php";

class Notifier
{
    private string $envelopeFrom;

    public function __construct(string $envelopeFrom = '')
    {
        $this->envelopeFrom = $envelopeFrom;
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
                    Debug::LogWarning('[Notifier::notify] Unknown type "' . $type . '"');
                    break;
            }
            if ($result === false) {
                Debug::LogError("[Notifier::notify] fail to send message. type: {$type}, destination: {$destination}");
            } else {
                $succeed = true;
            }
        }
        if (!$succeed) {
            Debug::LogWarning(
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
            Debug::LogWarning('[Notifier::mailTo] Invalid email address "' . $destination . '"');
            return false;
        }

        $headers = [
            'Content-Type' => "text/plain; charset=UTF-8",
            'From'         => $message['email'],
            'Reply-to'     => $message['email']
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
        $instance = new Notifier(
            defined('MAIL_ENVELOPE_FROM') ? MAIL_ENVELOPE_FROM : ''
        );
    }
    return $instance;
}
