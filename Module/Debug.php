<?php

class Debug
{
    private static $logFileName = "OutputLog.txt";

    public static function Log($message)
    {
        //文字列に変換
        $messageStr = static::ToString($message);
        return static::OutputLog($messageStr);
    }

    public static function LogWarning($message)
    {
        //文字列に変換
        $messageStr = "[WARNING] " . static::ToString($message);
        return static::OutputLog($messageStr);
    }

    public static function LogError($message)
    {
        //文字列に変換
        $messageStr = "[ERROR] " . static::ToString($message);
        return static::OutputLog($messageStr);
    }

    private static function OutputLog($messageStr)
    {
        $renew = false;

        //Fileが存在するとき
        //Fileを新しく更新するかどうか判別
        if(file_exists(static::$logFileName))
        {
            $fdate = filemtime(static::$logFileName);
            if($fdate === false)
            {
                return false;
            }
            $fmonth = intval(date("n", $fdate));

            $date = getdate();
            $month = $date["mon"];
            if($month != $fmonth)
            {
                $renew = true;
            }
            else
            {
                $renew = false;
            }
        }

        //Fileを開く
        $file = null;
        if($renew)
        {
            $file = @fopen(static::$logFileName, "w");

            if($file === false)
            {
                return false;
            }
        }
        else
        {
            $file = @fopen(static::$logFileName, "a");
            if($file === false)
            {
                return false;
            }
        }

        //書き込み
        flock($file, LOCK_EX);
        fputs($file, "\n" . date("H:i:s; m.d.Y") . "\n" . $messageStr . "\n");
        flock($file, LOCK_UN);

        fclose($file);

        return true;
    }

    public static function ToString($object)
    {
        //nullのとき
        if(is_null($object))
        {
            return "null";
        }

        //stringのとき
        if(is_string($object))
        {
            return $object;
        }

        //数値のとき
        if(is_numeric($object))
        {
            return strval($object);
        }

        //boolのとき
        if(is_bool($object))
        {
            if($object === true)
            {
                return "true";
            }

            if($object === false)
            {
                return "false";
            }
        }

        //objectのとき
        if(is_object($object))
        {
            if(in_array("__toString", get_class_methods($object)))
            {
                return strval($object->__toString());
            }
            else
            {
                return get_class($object) . ": " . spl_object_hash($object);
            }
        }

        //配列のとき
        if(is_array($object))
        {
            return print_r($object, true);
        }

        //その他
        return strval($object);
    }
}

?>