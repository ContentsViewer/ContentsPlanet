<?php


require_once dirname(__FILE__) . "/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/Debug.php";

class Tips{
    public static $tipsContentFileName = './Master/Contents/Tips';


    public static function GetTip(){
        $tipsContent = new Content();

        $tipsContent->SetContent(static::$tipsContentFileName);

        if($tipsContent === false)
            return;
            
        // Debug::Log($tipsContent->Body());
        $body = trim($tipsContent->Body());
        $body = str_replace("\r", "", $body);
        $tips = explode("\n", $body);

        $tipsCount = count($tips);
        if($tipsCount <= 0){
            return "";
        }

        return $tips[rand(0, $tipsCount - 1)];

    }

    
}

?>