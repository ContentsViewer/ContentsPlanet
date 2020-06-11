<?php

require_once dirname(__FILE__) . "/Debug.php";

class Notifyer {
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
    public static function Notify($message, $notifyingList) {
        $succeed = false;
        foreach($notifyingList as $notifying) {
            $destination = $notifying['destination'];
            $type        = $notifying['type'];
            $result = true;
            switch($type) {
                case 'mail':
                    $result = static::MailTo($message, $destination);
                    break;
                case 'url':
                    $result = static::GetRequestTo($message, $destination);
                    break;
                default:
                    Debug::LogWarning('[Notifyer::Notify] Unknown type "' . $type . '"');
                    break;
            }
            if($result === false) {
                Debug::LogError("[Notifyer::Notify] fail to send message. type: {$type}, destination: {$destination}");
            }
            else {
                $succeed = true;
            }
        }
        if(!$succeed) {
            Debug::LogWarning(
                "[Notifyer::Notify] This message was not sended in any destinations.\n" . 
                "----- Message -----\n" . 
                print_r($message, true) . 
                "-------------------"
            );
        }
        return $succeed;
    }

    public static function MailTo($message, $destination) {
        if(filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
            Debug::LogWarning('[Notifyer::MailTo] Invalid email address "' . $destination . '"');
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

        return @mail($destination, $message['subject'], $body, $headers);
    }

    public static function GetRequestTo($message, $destination) {
        $queryString = '?';
        foreach($message as $key => $value) {
            $queryString .= $key . '=' . urlencode($value) . '&';
        }
        $queryString = substr($queryString, 0, -1); // remove last char('?' or '&')
        return @file_get_contents($destination . $queryString);
    }

}
