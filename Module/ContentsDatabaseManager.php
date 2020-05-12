<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/ContentsDatabase.php";
require_once dirname(__FILE__) . "/SearchEngine.php";
require_once dirname(__FILE__) . "/Utils.php";
require_once dirname(__FILE__) . "/Localization.php";

class ContentsDatabaseManager {

    /**
     * GetTopDirectory(DEFAULT_CONTENTS_FOLDER) / META_FILE_NAME
     * 
     * ex)
     *  ./Master/.metadata
     */
    public static function DefaultMetaFilePath() {
        return GetTopDirectory(DEFAULT_CONTENTS_FOLDER) . '/' . META_FILE_NAME;
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
        $layerName = static::GetRelatedLayerName($contentPath);
        $layerName = ($layerName === false) ? '' : ('_' . $layerName);
        return $rootFolder . '/' . ROOT_FILE_NAME . $layerName;
    }

    public static function GetRelatedMetaFileName($contentPath) {
        $rootFolder = static::GetRootContentsFolder($contentPath);
        $layerName = static::GetRelatedLayerName($contentPath);
        $layerName = ($layerName === false) ? '' : ('_' . $layerName);
        return GetTopDirectory($rootFolder) . '/' . META_FILE_NAME . $layerName;
    }

    /**
     * ex)
     *  '.index_ja'
     */
    public static function GetRelatedIndexFileName($contentPath){
        $rootFolder = static::GetRootContentsFolder($contentPath);
        $layerName = static::GetRelatedLayerName($contentPath);
        $layerName = ($layerName === false) ? '' : ('_' . $layerName);
        return CONTENTS_HOME_DIR . '/' . GetTopDirectory($rootFolder) . '/' . INDEX_FILE_NAME . $layerName;
    }

    /**
     * './Master/Contents/Root_ja.note' -> 'ja'
     * './Master/Contents/Root -> false
     * './Master/Root_en.note.content' -> 'en'
     */
    public static function GetRelatedLayerName($contentPath){
        return static::GetContentPathInfo($contentPath)['layername'];
    }

    public static function GetLayerSuffix($layerName){
        if($layerName === false || $layerName === DEFAULT_LAYER_NAME){
            return '';
        }
        return '_' . $layerName;
    }

    /**
     * If the directory of the contentPath does not exist, returns false.
     * 
     * ex)
     *  $contentPath = './Master/Contents/Test/Sub'
     *  in same direcotry, 
     *  './Master/Contents/Test/Sub_en', './Master/Contents/Test/Sub_ch' exists
     * 
     *  -> ['en', 'ch', false]
     * 
     * @return false|array
     */
    public static function GetRelatedLayers($contentPath){
        $pathInfo = static::GetContentPathInfo($contentPath);
        $realDirname = Content::RealPath($pathInfo['dirname'], '');

        if($realDirname === false){
            return false;
        }

        $layers = [];

        $concatExtentions = implode('.', array_merge($pathInfo['extentions'], ['content']));
        $pattern = $pathInfo['filename'] . '_*.' . $concatExtentions;
        $files = glob($realDirname . '/' . $pattern);
        foreach($files as $file){
            $info = static::GetContentPathInfo($file);
            if(
                $info['filename'] === $pathInfo['filename'] &&
                $concatExtentions === implode('.', $info['extentions'])
            ){
                $layers[] = $info['layername'];
            }
        }

        // 最後に, layer が付いていないファイルを探す.
        // ex) ./Master/Contents/Test/Sub
        if(file_exists($realDirname . '/' . $pathInfo['filename'] . '.' . $concatExtentions)){
            $layers[] = false; // layerがないことを示す
        }

        return $layers;
    }

    /**
     * ex)
     *  './Master/Contents/Sub/Test_en.note.content'
     *  ->
     *  [
     *      'dirname' => './Master/Contents/Sub',
     *      'basename' => 'Test_en.note.content',
     *      'filename' => 'Test'
     *      'layername' => 'en',
     *      'extentions' => ['note', 'content']
     *  ]
     */
    public static function GetContentPathInfo($contentPath){
        $info = [];
        $info['dirname'] = dirname($contentPath);
        $info['basename'] = basename($contentPath);

        $extentions = [];
        $filename = $info['basename'];
        $layername = false;

        // 拡張子を取り除く
        while(($pos = strrpos($filename, '.')) != false){
            array_unshift($extentions, substr($filename, $pos + 1));
            $filename = substr($filename, 0, $pos);
        }

        if(($pos = strrpos($filename, '_')) != false){
            $layername = substr($filename, $pos + 1);
            // {言語コード(ISO 639-1)}
            // (
            //  (-{国名コード(ISO 3166-1 Alpha 2)}) |
            //  (-{文字体系(ISO 15924)}(-{国名コード(ISO 3166-1 Alpha 2)})?)
            // )?
            //
            // 言語コード(ISO 639-1): [a-z][a-z]
            // 国名コード(ISO 3166-1 Alpha 2): [A-Z][A-Z]
            // 文字体系(ISO 15924): [A-Z][a-z][a-z][a-z]
            // 
            if(preg_match('/^[a-z][a-z]((-[A-Z][A-Z])|(-[A-Z][a-z][a-z][a-z](-[A-Z][A-Z])?))?$/', $layername) === 1){
                $filename = substr($filename, 0, $pos);
            }
            else{
                $layername = false;
            }
        }

        $info['filename'] = $filename;
        $info['layername'] = $layername;
        $info['extentions'] = $extentions;

        return $info;
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
        }
    }

    public static function RegistMetadata($content){
        ContentsDatabase::UnregistTag($content->path);
        ContentsDatabase::UnregistLatest($content->path);

        ContentsDatabase::NotifyContentsChange($content->modifiedTime);

        if(in_array('noindex', $content->tags, true)){
            return;
        }

        $shouldAddLatest = true;
        foreach($content->tags as $tag){
            ContentsDatabase::RegistTag($content->path, $tag);

            if(strtolower($tag) == Localization\Localize('editing', 'editing') || $tag == 'noindex-latest'){
                $shouldAddLatest = false;    
            }
        }

        if($shouldAddLatest){
            ContentsDatabase::RegistLatest($content->path, $content->modifiedTime);
        }
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
        }
    }

    public static function RegistIndex($content){
        SearchEngine\Indexer::UnregistIndex($content->path);

        if(in_array('noindex', $content->tags, true)){
            return;
        }

        $pathInfo = ContentsDatabaseManager::GetContentPathInfo($content->path);
        
        // title の登録
        // 無い場合は, 'layer'や'extentions'を除いたファイル名の登録
        SearchEngine\Indexer::RegistIndex($content->path, NotBlankText([$content->title, $pathInfo['filename']]));
        
        if (($parent = $content->Parent()) !== false) {
            $parentPathInfo = ContentsDatabaseManager::GetContentPathInfo($parent->path);

            // 親がROOT fileのときは, 親のタイトルを登録しない.
            // カテゴリとの関係性が低いと思われるため.
            //  * ROOT は, 大体 welcome page
            //  * ROOT 直下は, 比較的たどりやすい
            if($parentPathInfo['filename'] != ROOT_FILE_NAME){
                SearchEngine\Indexer::RegistIndex($content->path, NotBlankText([$parent->title, $parentPathInfo['filename']]));
            }
        }

        foreach($content->tags as $tag){
            SearchEngine\Indexer::RegistIndex($content->path, $tag);
        }

        // path の登録
        // ただし, パス上部にあるルートディレクトリは除外する
        // ./Master/Contents/Test/Sub_en -> Test/Sub
        $pathToRegist = Path2URI($content->path);
        $pathToRegist = static::ReduceURI($pathToRegist);
        SearchEngine\Indexer::RegistIndex($content->path, $pathToRegist);
    }

    /**
     * Remove Top Directory, layer suffix, and extentions.
     * 
     * ex)
     *  /Master/Test/Sub_en.note -> Test/Sub
     *  /TagMap_en -> TagMap
     */
    public static function ReduceURI($uri){
        $reduced = substr($uri, strlen(GetTopDirectory($uri)) + 1);
        
        if($reduced === false){
            // URI の仕様で, 最初に'/'があることは決まっている
            $reduced = substr($uri, 1);
        }

        // Test/Sub_en.note
        // TagMap_en

        $pathInfo = static::GetContentPathInfo($reduced);
        return (($pathInfo['dirname'] == '.') ? ('') : ($pathInfo['dirname'] . '/')) . $pathInfo['filename'];
    }

    /**
     * ex)
     *  './Master/Contents'
     */
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
    
        usort($sorted, function($a, $b){return $b->modifiedTime - $a->modifiedTime;});
        return ['sorted' => $sorted, 'notFounds' => $notFounds];
    }

    public static function CreatePathMacros($contentPath) {
        return [
            ['CURRENT_DIR', 'ROOT_URI'],
            [ROOT_URI . Path2URI(dirname($contentPath)), ROOT_URI]
        ];
    }
}
