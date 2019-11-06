<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/ContentsDatabase.php";
require_once dirname(__FILE__) . "/Utils.php";

class ContentsDatabaseManager {

    /**
     * GetTopDirectory(DEFAULT_CONTENTS_FOLDER) / META_FILE_NAME
     * 
     * ex)
     *  ./Master/.metadata
     */
    public static function DefaultMetaFilePath() {
        return GetTopDirectory(DEFAULT_CONTENTS_FOLDER) . '/' . META_FILE_NAME;
        // return DEFAULT_CONTENTS_FOLDER . '/../' . TAG_MAP_META_FILE_NAME;
    }

    /**
     * DEFAULT_CONTENTS_FOLDER / ROOT_FILE_NAME
     * 
     * ex)
     *  ./Master/Contents/Root
     */
    public static function DefalutRootContentPath() {
        return DEFAULT_CONTENTS_FOLDER . '/' . ROOT_FILE_NAME;
    }

    public static function GetRelatedRootFile($contentPath) {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return $rootFolder . '/' . ROOT_FILE_NAME;
    }

    public static function GetRelatedMetaFileName($contentPath) {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return GetTopDirectory($rootFolder) . '/' . META_FILE_NAME;
    }

    public static function GetRelatedIndexFileName($contentPath){
        $rootFolder = static::GetRootContentsFolder($contentPath);
        return CONTENTS_HOME_DIR . '/' . GetTopDirectory($rootFolder) . '/' . INDEX_FILE_NAME;
    }

    public static function UpdateAndSaveRelatedMetadata($contentPath){
        $rootContentPath = ContentsDatabaseManager::GetRelatedRootFile($contentPath);
        $metaFileName = ContentsDatabaseManager::GetRelatedMetaFileName($contentPath);
        ContentsDatabase::UpdateMetadata($rootContentPath);
        ContentsDatabase::SaveMetadata($metaFileName);
    }

    public static function GetRelatedMetaFileUpdatedTime($contentPath) {
        return filemtime(Content::RealPath(static::GetRelatedMetaFileName($contentPath), '', false));
    }

    public static function LoadRelatedMetadata($contentPath) {
        $metaFileName = static::GetRelatedMetaFileName($contentPath);
        $rootContentPath = static::GetRelatedRootFile($contentPath);

        if (!ContentsDatabase::LoadMetadata($metaFileName)) {
            ContentsDatabase::UpdateMetadata($rootContentPath);
            ContentsDatabase::SaveMetadata($metaFileName);
        }
    }

    public static function GetRootContentsFolder($contentPath) {
        $pos = strpos($contentPath, "/Contents/");

        if ($pos === false) {
            return DEFAULT_CONTENTS_FOLDER;
        }

        return substr($contentPath, 0, $pos + strlen("/Contents"));
    }

    public static function GetContentsFolderUpdatedTime($contentPath) {
        return filemtime(Content::RealPath(static::GetRootContentsFolder($contentPath), '', false));
    }

    public static function UpdateContentsFolder($contentPath) {
        @touch(Content::RealPath(ContentsDatabaseManager::GetRootContentsFolder($contentPath), '', false));
    }

    public static function CreatePathMacros($contentPath) {
        return [
            ['CURRENT_DIR', 'ROOT_URI'],
            [ROOT_URI . Path2URI(dirname($contentPath)), ROOT_URI]
        ];
    }
}
