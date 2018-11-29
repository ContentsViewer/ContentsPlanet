<?php

require_once dirname(__FILE__) . "/../ConMAS.php";
require_once dirname(__FILE__) . "/ContentsDatabase.php";

class ContentsDatabaseManager
{

    private static $DefalutRootContentsFolder = './Master/Contents/';
    private static $MetaFileName = 'TagMap.meta';
    private static $RootFileName = 'Root';

    public static function DefaultMetaFileName()
    {
        return static::$DefalutRootContentsFolder . static::$MetaFileName;
    }
    public static function DefalutRootContentPath()
    {
        return static::$DefalutRootContentsFolder . static::$RootFileName;
    }

    public static function GetRelatedRootFile($contentPath)
    {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return $rootFolder . static::$RootFileName;
    }

    public static function GetRelatedMetaFileName($contentPath)
    {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return $rootFolder . static::$MetaFileName;
    }

    public static function GetRelatedMetaFileUpdatedTime($contentPath)
    {
        return filemtime(Content::RealPath(static::GetRelatedMetaFileName($contentPath), '', false));
    }

    public static function LoadRelatedTagMap($contentPath)
    {
        $metaFileName = static::GetRelatedMetaFileName($contentPath);
        $rootContentPath = static::GetRelatedRootFile($contentPath);

        if (!Content::LoadGlobalTagMap($metaFileName)) {
            Content::CreateGlobalTagMap($rootContentPath);
            Content::SaveGlobalTagMap($metaFileName);
            //echo "asaa";
        }
    }

    public static function GetRootContentsFolder($contentPath)
    {
        $pos = strpos($contentPath, "/Contents/");

        if ($pos === false) {
            return static::$DefalutRootContentsFolder;
        }

        return substr($contentPath, 0, $pos + strlen("/Contents/"));
    }
}
