<?php

class Logger
{
    const LOG_FILE_NAME = "OutputLog.txt";
    const ROTATION_FILE_NAME = "OutputLog.1.txt";
    const DEFAULT_MAX_FILE_SIZE = 1048576; // 1MB

    private string $logDir;
    private int $maxFileSize;

    public function __construct(string $logDir, int $maxFileSize = self::DEFAULT_MAX_FILE_SIZE)
    {
        $this->logDir = $logDir;
        $this->maxFileSize = $maxFileSize;
    }

    public function debug(mixed $message): bool
    {
        $messageStr = self::toString($message);
        return $this->outputLog($messageStr);
    }

    public function warning(mixed $message): bool
    {
        $messageStr = "[WARNING] " . self::toString($message);
        return $this->outputLog($messageStr);
    }

    public function error(mixed $message): bool
    {
        $messageStr = "[ERROR]   " . self::toString($message);
        return $this->outputLog($messageStr);
    }

    private function outputLog(string $messageStr): bool
    {
        $logFilePath = $this->logDir . DIRECTORY_SEPARATOR . self::LOG_FILE_NAME;

        // サイズ超過時にローテーション
        if (file_exists($logFilePath) && @filesize($logFilePath) >= $this->maxFileSize) {
            $rotationPath = $this->logDir . DIRECTORY_SEPARATOR . self::ROTATION_FILE_NAME;
            @rename($logFilePath, $rotationPath);
        }

        $file = @fopen($logFilePath, "a");
        if ($file === false) { return false; }

        flock($file, LOCK_EX);
        fputs($file, "\n" . date("H:i:s; m.d.Y") . "\n" . $messageStr . "\n");
        flock($file, LOCK_UN);
        fclose($file);

        return true;
    }

    public static function toString(mixed $object): string
    {
        if (is_null($object)) { return "null"; }
        if (is_string($object)) { return $object; }
        if (is_numeric($object)) { return strval($object); }

        if (is_bool($object)) {
            if ($object === true)  { return "true"; }
            if ($object === false) { return "false"; }
        }

        if (is_object($object)) {
            if (in_array("__toString", get_class_methods($object))) {
                return strval($object->__toString());
            } else {
                return get_class($object) . ": " . spl_object_hash($object);
            }
        }

        if (is_array($object)) {
            return print_r($object, true);
        }

        return strval($object);
    }
}

/**
 * Loggerの共有インスタンスを返す。
 */
function logger(): Logger
{
    static $instance = null;
    if ($instance === null) {
        $instance = new Logger(
            defined('LOG_DIR') ? LOG_DIR : getcwd()
        );
    }
    return $instance;
}
