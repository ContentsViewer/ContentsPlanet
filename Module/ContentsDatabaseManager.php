<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/ContentsDatabase.php";
require_once dirname(__FILE__) . "/SearchEngine.php";
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

    /**
     * 渡されたコンテントパスに関連するメタデータを読み込みます.
     * メタデータが存在しないときは, ルートからすべてのコンテンツをクロールし, 
     * メタデータを作成します
     */
    public static function LoadRelatedMetadata($contentPath) {
        $metaFileName = static::GetRelatedMetaFileName($contentPath);
        $rootContentPath = static::GetRelatedRootFile($contentPath);

        if (!ContentsDatabase::LoadMetadata($metaFileName)) {
            ContentsDatabase::CrawlContents($rootContentPath, ['ContentsDatabaseManager', 'RegistMetadata']);
            ContentsDatabase::SaveMetadata($metaFileName);
            Debug::Log("ABC");
        }
    }

    public static function RegistMetadata($content){
        ContentsDatabase::UnregistTag($content->Path());
        ContentsDatabase::UnregistLatest($content->Path());

        $shouldAddLatest = true;
        foreach($content->Tags() as $tag){
            ContentsDatabase::RegistTag($content->Path(), $tag);

            if(strtolower($tag) == 'editing' || $tag == '編集中'){
                $shouldAddLatest = false;    
            }
        }
        
        if($shouldAddLatest){
            ContentsDatabase::RegistLatest($content->Path(), $content->UpdatedAtTimestamp());
        }
        ContentsDatabase::NotifyContentsChange($content->UpdatedAtTimestamp());
    }
    
    /**
     * 渡されたコンテントパスに関連するインデックスを読み込みます.
     * インデックスが存在しないときは, ルートからすべてのコンテンツをクロールし, 
     * インデックスを作成します
     */
    public static function LoadRelatedIndex($contentPath) {
        $indexFileName = static::GetRelatedIndexFileName($contentPath);
        $rootContentPath = static::GetRelatedRootFile($contentPath);

        if (!SearchEngine\Indexer::LoadIndex($indexFileName)) {
            ContentsDatabase::CrawlContents($rootContentPath, ['ContentsDatabaseManager', 'RegistIndex']);
            SearchEngine\Indexer::ApplyIndex($indexFileName);
            Debug::Log("EFG");
        }
    }

    public static function RegistIndex($content){
        SearchEngine\Indexer::UnregistIndex($content->Path());
        SearchEngine\Indexer::RegistIndex($content->Path(), $content->Title());
        if (($parent = $content->Parent()) !== false) {
            SearchEngine\Indexer::RegistIndex($content->Path(),  $parent->Title());
        }
        foreach($content->Tags() as $tag){
            SearchEngine\Indexer::RegistIndex($content->Path(), $tag);
        }
        SearchEngine\Indexer::RegistIndex($content->Path(), Path2URI($content->Path()));
    }

    public static function GetRootContentsFolder($contentPath) {
        $pos = strpos($contentPath, "/Contents/");

        if ($pos === false) {
            return DEFAULT_CONTENTS_FOLDER;
        }

        return substr($contentPath, 0, $pos + strlen("/Contents"));
    }

    /**
     * ['sorted' => [Content, ...], 'notFounds' => ['path', ...]]
     * 
     * @param array $pathList
     * @return array ['sorted' => [Content, ...], 'notFounds' => ['path', ...]]
     */
    public static function GetSortedContentsByUpdatedTime($pathList) {
        $sorted = [];
        $notFounds = [];
        foreach($pathList as $path){
            $content = new Content();
            if(!$content->SetContent($path)){
                $notFounds[] = $path;
                continue;
            }
    
            $sorted[] = $content;
        }
    
        usort($sorted, function($a, $b){return $b->UpdatedAtTimestamp() - $a->UpdatedAtTimestamp();});
        return ['sorted' => $sorted, 'notFounds' => $notFounds];
    }

    public static function CreatePathMacros($contentPath) {
        return [
            ['CURRENT_DIR', 'ROOT_URI'],
            [ROOT_URI . Path2URI(dirname($contentPath)), ROOT_URI]
        ];
    }
}
