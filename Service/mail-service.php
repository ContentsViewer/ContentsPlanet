
<?php


/*
 * Mailの送信
 *
 */
$mailTo = 'xx@xx';

$fieldSubject = $_POST['subject'];
$fieldName = $_POST['name'];
$fieldEmail = $_POST['email'];
$fieldMessage = $_POST['message'];
$failTo = $_POST['failTo'];
$successTo = $_POST['successTo'];

if(!isset($fieldEmail) || !isset($fieldMessage)||
    !isset($fieldName) || !isset($fieldSubject) ||
    !isset($failTo) || !isset($successTo))
{
    echo "Not Sync.";
    exit;
}

$subject = $fieldSubject.$fieldName;
$bodyMessage = "From: ". $fieldName."\r\n";
$bodyMessage .= "E-mail: ". $fieldEmail."\r\n";
$bodyMessage .= "Message: \r\n". $fieldMessage."\r\n";
$headers = 'From: '. $fieldName. "\r\n";
$headers.= 'Reply-to: '. $fieldEmail."\r\n";

echo "Processing...";

if(mail($mailTo, $subject, $bodyMessage, $headers))
{
    echo "Success";
    MoveTo($successTo);
}
else
{
    echo "Fail";
    MoveTo($failTo);
}

function MoveTo($address)
{
    if($address == "")
    {
        return;
    }
    echo "<script>";
    echo "window.location='".$address."';";
    echo "</script>";
}

function DetectCharCode($str)
    {
        foreach(array('UTF-8','SJIS','EUC-JP','ASCII','JIS') as $charCode)
        {
            if(mb_convert_encoding($str, $charCode, $charCode) == $str)
            {
                return $charCode;
            }
        }

        return null;
    }

?>