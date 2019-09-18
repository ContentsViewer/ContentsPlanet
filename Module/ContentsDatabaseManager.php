<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/ContentsDatabase.php";
require_once dirname(__FILE__) . "/Utils.php";

class ContentsDatabaseManager
{

    /**
     * DEFAULT_CONTENTS_FOLDER / TAG_MAP_META_FILE_NAME
     * 
     * ex)
     *  ./Master/Contents/TagMap.meta
     */
    public static function DefaultTagMapMetaFilePath()
    {
        return DEFAULT_CONTENTS_FOLDER . '/' . TAG_MAP_META_FILE_NAME;
    }

    /**
     * DEFAULT_CONTENTS_FOLDER / ROOT_FILE_NAME
     * 
     * ex)
     *  ./Master/Contents/Root
     */
    public static function DefalutRootContentPath()
    {
        return DEFAULT_CONTENTS_FOLDER . '/' . ROOT_FILE_NAME;
    }

    public static function GetRelatedRootFile($contentPath)
    {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return $rootFolder . '/' . ROOT_FILE_NAME;
    }

    public static function GetRelatedTagMapMetaFileName($contentPath)
    {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return $rootFolder . '/' . TAG_MAP_META_FILE_NAME;
    }

    public static function UpdateRelatedTagMap($contentPath){
        $rootContentPath = ContentsDatabaseManager::GetRelatedRootFile($contentPath);
        $tagMapMetaFileName = ContentsDatabaseManager::GetRelatedTagMapMetaFileName($contentPath);
        Content::CreateGlobalTagMap($rootContentPath);
        Content::SaveGlobalTagMap($tagMapMetaFileName);
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
            return DEFAULT_CONTENTS_FOLDER;
        }

        return substr($contentPath, 0, $pos + strlen("/Contents"));
    }

    public static function GetContentsFolderUpdatedTime($contentPath){
        return filemtime(Content::RealPath(static::GetRootContentsFolder($contentPath), '', false));
    }

    public static function UpdateContentsFolder($contentPath){
        @touch(Content::RealPath(ContentsDatabaseManager::GetRootContentsFolder($contentPath), '', false));
    }

    public static function CreatePathMacros($contentPath){
        return [
            ['CURRENT_DIR', 'ROOT_URI'],
            [ROOT_URI . Path2URI(dirname($contentPath)), ROOT_URI]
        ];
    }
}
