<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/ContentsDatabase.php";

class ContentsDatabaseManager
{

    const DEFAULT_CONTENTS_FOLDER = './Master/Contents';
    const TAG_MAP_META_FILE_NAME = 'TagMap.meta';
    const ROOT_FILE_NAME = 'Root';

    public static function DefaultTagMapMetaFilePath()
    {
        return self::DEFAULT_CONTENTS_FOLDER . '/' . self::TAG_MAP_META_FILE_NAME;
    }
    public static function DefalutRootContentPath()
    {
        return self::DEFAULT_CONTENTS_FOLDER . '/' . self::ROOT_FILE_NAME;
    }

    public static function GetRelatedRootFile($contentPath)
    {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return $rootFolder . '/' . self::ROOT_FILE_NAME;
    }

    public static function GetRelatedTagMapMetaFileName($contentPath)
    {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return $rootFolder . '/' . self::TAG_MAP_META_FILE_NAME;
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
            return self::DEFAULT_CONTENTS_FOLDER;
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
