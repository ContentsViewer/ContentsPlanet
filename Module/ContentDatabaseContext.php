<?php

require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/ContentDatabase.php";
require_once dirname(__FILE__) . "/ContentDatabaseControls.php";
require_once dirname(__FILE__) . "/SearchEngine.php";
require_once dirname(__FILE__) . "/Localization.php";
require_once dirname(__FILE__) . "/Utils.php";


use ContentDatabaseControls as DBControls;


class ContentDatabaseContext
{
    /**
     * 現在のコンテンツフォルダ
     * 'Master/Contents'
     * @var string
     */
    public $contentsFolder = '';

    /**
     * @var SearchEngine\Index
     */
    public $index = null;

    /**
     * @var ContentDatabase
     */
    public $database = null;

    /**
     * @var string
     */
    public $indexFileName = "";


    /**
     * @var string
     */
    public $metaFileName = "";

    /**
     * @var string
     */
    public $rootContentPath = "";

    /**
     * @param string $contentPath ex) "./Master/Contents/Root"
     */
    public function __construct(string $contentPath)
    {
        $this->contentsFolder = DBControls\GetContentsFolder($contentPath);
        $this->indexFileName = DBControls\GetRelatedIndexFileName($contentPath);
        $this->metaFileName = DBControls\GetRelatedMetaFileName($contentPath);
        $this->rootContentPath = DBControls\GetRelatedRootFile($contentPath);
        $this->index = new SearchEngine\Index();
        $this->database = new ContentDatabase();
    }


    /**
     * Does `$contentPath` start with `$contentsFolder`?
     * $contentPath must be canonicalized.
     */
    public function IsInContentsFolder($contentPath)
    {
        return strpos($contentPath, $this->contentsFolder . '/') === 0;
    }


    /**
     * 渡されたコンテントパスに関連するメタデータを読み込みます.
     * メタデータが存在しないときは, ルートからすべてのコンテンツをクロールし, 
     * メタデータを作成します
     */
    public function LoadMetadata()
    {
        if (!$this->database->LoadMetadata($this->metaFileName)) {
            $this->database->CrawlContents($this->rootContentPath, [$this, 'RegisterToMetadata']);
            // 一回全探索した後に作成される完全なtag2pathを用いてもう一度全探索を行う.
            $this->database->CrawlContents($this->rootContentPath, [$this, 'RegisterToMetadata']);
            $this->database->SaveMetadata($this->metaFileName);
        }
    }


    // Memo
    //  単純にコンテンツのタグのみ:
    //      143.30ms, 16.6KB
    //  親と提案コンテンツ含めて, 二回全探索
    //      336.88ms, 26.7KB
    public function RegisterToMetadata($content, $database = null, $context = null)
    {

        $this->database->DeleteFromTagMap($content->path);
        $this->database->DeleteFromRecent($content->path);

        if (!$this->IsInContentsFolder($content->path)) {
            return;
        }

        $this->database->NotifyContentsChange($content->modifiedTime);

        if (in_array('noindex', $content->tags, true)) {
            return;
        }

        $shouldAddRecent = true;
        foreach ($content->tags as $tag) {
            $this->database->RegisterTag($content->path, $tag);
            if (strtolower($tag) == Localization\Localize('editing', 'editing') || $tag == 'noindex-recent') {
                $shouldAddRecent = false;
            }
        }
        if ($shouldAddRecent) {
            $this->database->RegisterRecent($content->path, $content->modifiedTime);
        }

        $suggestedTags = DBControls\GetSuggestedTags($content, $this->database->metadata['tag2path'] ?? []);
        foreach ($suggestedTags as $tag) {
            $this->database->RegisterTag($content->path, $tag);
        }

        if (($parent = $content->Parent()) !== false) {
            $parentPathInfo = DBControls\GetContentPathInfo($parent->path);
            if ($parentPathInfo['filename'] != ROOT_FILE_NAME) {
                $suggestedTags = DBControls\GetSuggestedTags($parent, $this->database->metadata['tag2path'], false);
                foreach ($suggestedTags as $tag) {
                    $this->database->RegisterTag($content->path, $tag);
                }
            }
        }
    }


    /**
     * 渡されたコンテントパスに関連するインデックスを読み込みます.
     * インデックスが存在しないときは, ルートからすべてのコンテンツをクロールし, 
     * インデックスを作成します
     */
    public function LoadIndex()
    {
        if (!$this->index->Load($this->indexFileName)) {
            $this->database->CrawlContents($this->rootContentPath, [$this, 'RegisterToIndex']);
            $this->index->Apply($this->indexFileName);
        }
    }


    public function RegisterToIndex($content, $database = null, $context = null)
    {
        SearchEngine\Indexer::Delete($this->index, $content->path);

        if (!$this->IsInContentsFolder($content->path)) {
            return;
        }

        if (in_array('noindex', $content->tags, true)) {
            return;
        }

        $pathInfo = DBControls\GetContentPathInfo($content->path);

        // title の登録
        // 無い場合は, 'layer'や'extentions'を除いたファイル名の登録
        SearchEngine\Indexer::Index($this->index, $content->path, NotBlankText([$content->title, $pathInfo['filename']]));

        if (($parent = $content->Parent()) !== false) {
            $parentPathInfo = DBControls\GetContentPathInfo($parent->path);

            // 親がROOT fileのときは, 親のタイトルを登録しない.
            // カテゴリとの関係性が低いと思われるため.
            //  * ROOT は, 大体 welcome page
            //  * ROOT 直下は, 比較的たどりやすい
            if ($parentPathInfo['filename'] != ROOT_FILE_NAME) {
                SearchEngine\Indexer::Index($this->index, $content->path, NotBlankText([$parent->title, $parentPathInfo['filename']]));
            }
        }

        foreach ($content->tags as $tag) {
            SearchEngine\Indexer::Index($this->index, $content->path, $tag);
        }

        // metadata に登録されているタグもインデックスする.
        // metadata に登録されているタグには, 提案タグが含まれている.
        $path2tag = $this->database->metadata['path2tag'] ?? [];
        if (array_key_exists($content->path, $path2tag)) {
            foreach ($path2tag[$content->path] as $tag => $_) {
                SearchEngine\Indexer::Index($this->index, $content->path, $tag);
            }
        }

        // path の登録
        // ただし, パス上部にあるルートディレクトリは除外する
        // ./Master/Contents/Test/Sub_en -> Test/Sub
        $pathToRegist = Path2URI($content->path);
        $pathToRegist = DBControls\ReduceURI($pathToRegist);
        SearchEngine\Indexer::Index($this->index, $content->path, $pathToRegist);
    }


    public function DeleteContentsFromMetadata($contentPaths)
    {
        return DBControls\DeleteContentsFromMetadata($this->database, $contentPaths);
    }


    public function DeleteContentsFromIndex($contentPaths)
    {
        return DBControls\DeleteContentsFromIndex($this->index, $contentPaths);
    }


    public function SaveMetadata()
    {
        return $this->database->SaveMetadata($this->metaFileName);
    }


    public function ApplyIndex()
    {
        return $this->index->Apply($this->indexFileName);
    }
}
