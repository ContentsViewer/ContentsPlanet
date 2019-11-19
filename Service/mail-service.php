<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";

// mb_internal_encoding("utf-8");

if(!defined('MAIL_TO') ){
    echo 'Not defined MAIL_TO';
    exit;
}

$mailTo = MAIL_TO;


if(!isset($_POST['subject']) || !isset($_POST['name'])||
    !isset($_POST['email']) || !isset($_POST['message']) ||
    !isset($_POST['failTo']) || !isset($_POST['successTo']))
{
    echo "Invalid form.";
    exit;
}

$refererUrl = parse_url($_SERVER['HTTP_REFERER']);

if($refererUrl['host'] !== $_SERVER['HTTP_HOST']){
    echo "External Access";
    exit;
}


$fieldSubject = $_POST['subject'];
$fieldName = $_POST['name'];
$fieldEmail = $_POST['email'];
$fieldMessage = $_POST['message'];
$failTo = $_POST['failTo'];
$successTo = $_POST['successTo'];


if(filter_var($fieldEmail, FILTER_VALIDATE_EMAIL) === false){
    echo "Invalid email.";
    exit;
}


//headerを設定
$charset = "UTF-8";
// $headers['MIME-Version'] 	= "1.0";
$headers['Content-Type'] = "text/plain; charset=".$charset;
// $headers['Content-Transfer-Encoding'] 	= "8bit";
$headers['From'] = $fieldName;
$header['Reply-to'] = $fieldEmail;
 
//headerを編集
foreach ($headers as $key => $val) {
	$arrheader[] = $key . ': ' . $val;
}
$strHeader = implode("\r\n", $arrheader);


$subject = $fieldSubject . $fieldName;


$bodyMessage = "From: ". $fieldName."\n";
$bodyMessage .= "E-mail: ". $fieldEmail."\n";
$bodyMessage .= "Message: \n". $fieldMessage."\n";

$bodyMessage = str_replace("\r", "", $bodyMessage);
$bodyMessage = str_replace("\n", "\r\n", $bodyMessage);
// $bodyMessage = MbWordWrap($bodyMessage, 35, "\r\n");

echo "Processing...";

if(@mail($mailTo, $subject, $bodyMessage, $strHeader))
{
    echo "Success";
    MoveTo(H($successTo));
}
else
{
    echo "Fail";
    MoveTo(H($failTo));
}


echo "Somethig error.";

exit;

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

function MbWordWrap($str, $width=35, $break=PHP_EOL)
{
    $c = mb_strlen($str);
    $arr = [];
    for ($i=0; $i<=$c; $i+=$width) {
        $arr[] = mb_substr($str, $i, $width);
    }
    return implode($break, $arr);
}

function H($text){
    return htmlspecialchars($text, ENT_QUOTES);
}
