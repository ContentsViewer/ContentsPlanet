<?php

require_once dirname(__FILE__) . "/../CommunCMS.php";
require_once dirname(__FILE__) . "/ContentsDatabase.php";

class ContentsDatabaseManager
{

    private static $DefalutRootContentsFolder = './Master/Contents';
    private static $TagMapMetaFileName = 'TagMap.meta';
    private static $RootFileName = 'Root';

    public static function DefaultTagMapMetaFileName()
    {
        return static::$DefalutRootContentsFolder . '/' . static::$TagMapMetaFileName;
    }
    public static function DefalutRootContentPath()
    {
        return static::$DefalutRootContentsFolder . '/' . static::$RootFileName;
    }

    public static function GetRelatedRootFile($contentPath)
    {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return $rootFolder . '/' . static::$RootFileName;
    }

    public static function GetRelatedTagMapMetaFileName($contentPath)
    {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return $rootFolder . '/' . static::$TagMapMetaFileName;
    }

    public static function GetRelatedTagMapMetaFileUpdatedTime($contentPath)
    {
        return filemtime(Content::RealPath(static::GetRelatedTagMapMetaFileName($contentPath), '', false));
    }

    public static function LoadRelatedTagMap($contentPath)
    {
        $metaFileName = static::GetRelatedTagMapMetaFileName($contentPath);
        $rootContentPath = static::GetRelatedRootFile($contentPath);

        if (!Content::LoadGlobalTagMap($metaFileName)) {
            Content::CreateGlobalTagMap($rootContentPath);
            Content::SaveGlobalTagMap($metaFileName);
        }
    }

    public static function GetRootContentsFolder($contentPath)
    {
        $pos = strpos($contentPath, "/Contents/");

        if ($pos === false) {
            return static::$DefalutRootContentsFolder;
        }

        return substr($contentPath, 0, $pos + strlen("/Contents"));
    }

    public static function GetContentsFolderUpdatedTime($contentPath){
        return filemtime(Content::RealPath(static::GetRootContentsFolder($contentPath), '', false));
    }

    public static function CreatePathMacros($contentPath){
        return [
            ['CURRENT_CONTENT_DIR', 'CURRENT_DIR'],
            ['./?content=' . dirname($contentPath), CONTENTS_HOME_DIR_RELATIVE . '/' . dirname($contentPath)]
        ];
    }
}
