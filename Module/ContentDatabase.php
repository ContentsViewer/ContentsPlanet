<?php

# FIXME: Sholud follow PSR-12
//  About PSR-12
//  * https://www.php-fig.org/psr/psr-12/


require_once dirname(__FILE__) . "/Debug.php";
require_once dirname(__FILE__) . "/PathUtils.php";

if (!defined('CONTENTS_HOME_DIR')) define('CONTENTS_HOME_DIR', getcwd());

class ContentDatabaseMetadata
{
    const MAX_RECENT_COUNT = 20;

    /**
     * [
     *  'tag2path' => 
     *      [
     *          'tag' => ['path' => true, ...],
     *          ...
     *      ],
     *  'path2tag' =>
     *      [
     *          'path' => ['tag' => true, ...],
     *          ...
     *      ],
     *  'recent' => ['path' => timestamp, ...],
     *  'contentsChangedTime' => timestamp,
     *  'createdTime' => timestamp,
     *  'openedTime' => timestamp,
     *  'closedTime' => timestamp
     * ]
     */
    public $data = [];
    public $metadataOpenedTime = null;

    public function RegisterTag($path, $tag)
    {
        $this->data['tag2path'][$tag][$path] = true;
        $this->data['path2tag'][$path][$tag] = true;
    }

    public function DeleteFromTagMap($path)
    {
        if (
            !array_key_exists('path2tag', $this->data) ||
            !array_key_exists($path, $this->data['path2tag'])
        ) {
            return;
        }

        if (!array_key_exists('tag2path', $this->data)) {
            return;
        }

        foreach ($this->data['path2tag'][$path] as $tag => $value) {
            if (
                !array_key_exists($tag, $this->data['tag2path']) ||
                !array_key_exists($path, $this->data['tag2path'][$tag])
            ) {
                continue;
            }

            unset($this->data['tag2path'][$tag][$path]);
            if (empty($this->data['tag2path'][$tag])) {
                unset($this->data['tag2path'][$tag]);
            }
        }

        unset($this->data['path2tag'][$path]);
    }

    public function NotifyContentsChange($timestamp)
    {
        if (!array_key_exists('contentsChangedTime', $this->data)) {
            $this->data['contentsChangedTime'] = $timestamp;
            return;
        }

        if ($this->data['contentsChangedTime'] < $timestamp) {
            $this->data['contentsChangedTime'] = $timestamp;
        }
    }

    public function RegisterRecent($path, $timestamp)
    {
        if (!array_key_exists('recent', $this->data)) {
            $this->data['recent'] = [];
        }

        $this->DeleteFromRecent($path);

        $recentCount = count($this->data['recent']);
        if ($recentCount < self::MAX_RECENT_COUNT) {
            $this->data['recent'][$path] = $timestamp;
            return;
        }

        $minTimestamp = reset($this->data['recent']);
        $oldest = key($this->data['recent']);
        foreach ($this->data['recent'] as $pt => $ts) {
            if ($ts < $minTimestamp) {
                $oldest = $pt;
                $minTimestamp = $ts;
            }
        }

        if ($timestamp < $this->data['recent'][$oldest]) {
            return;
        }

        unset($this->data['recent'][$oldest]);
        $this->data['recent'][$path] = $timestamp;
    }

    public function DeleteFromRecent($path)
    {
        if (
            !array_key_exists('recent', $this->data) ||
            !array_key_exists($path, $this->data['recent'])
        ) {
            return;
        }

        unset($this->data['recent'][$path]);
    }

    public function SaveMetadata($metaFileName)
    {
        if (!array_key_exists('createdTime', $this->data)) {
            $this->data['createdTime'] = time();
        }

        if (is_null($this->metadataOpenedTime)) {
            $this->metadataOpenedTime = time();
        }
        $this->data['openedTime'] = $this->metadataOpenedTime;
        $this->data['closedTime'] = time();

        $metaFileName = ContentPathUtils::RealPath($metaFileName, false);
        $encoded = json_encode($this->data);
        file_put_contents($metaFileName, $encoded, LOCK_EX);
    }

    public function LoadMetadata($metaFileName)
    {
        $metaFileName = ContentPathUtils::RealPath($metaFileName, false);
        if (file_exists($metaFileName) && is_file($metaFileName)) {
            $this->metadataOpenedTime = time();

            $fp = fopen($metaFileName, "r");
            if ($fp === false || !flock($fp, LOCK_SH)) {
                fclose($fp);
                return false;
            }
            $json = stream_get_contents($fp);
            fclose($fp);

            $json = mb_convert_encoding($json, 'UTF8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN');
            $this->data = json_decode($json, true);

            return true;
        }

        return false;
    }
}

class ContentsCrawler
{

    public static function crawl(ContentDatabase $database, string $rootContentPath, $callback, $context = null)
    {
        $contentPathStack = [];
        $contentPathStack[] = $rootContentPath;
        $contentPathStackCount = 1;

        $openContentPathMap = [];

        while ($contentPathStackCount > 0) {
            // var_dump($contentPathStack);

            $contentPathStackCount--;
            $path = array_pop($contentPathStack);

            $content = $database->get($path);

            if (!$content) {
                continue;
            }

            if (array_key_exists($content->path, $openContentPathMap)) {
                \Debug::LogWarning("[ContentsCrawler::crawl] >>> Detect Circular reference. " . $content->path);
                continue;
            }

            $openContentPathMap[$content->path] = null;
            call_user_func_array($callback, [$content, $context]);

            $childPathListCount = count($content->childPathList);
            for ($i = 0; $i < $childPathListCount; $i++) {
                $childPath = dirname($content->path) . '/' . $content->childPathList[$i];
                $contentPathStack[] = $childPath;
                $contentPathStackCount++;
            }
        }
    }
}

class ContentDatabase
{
    private $cachedContents = [];

    public function __construct()
    {
    }

    /**
     * @return Content|false
     */
    public function get(string $path, bool $forceUpdate = false)
    {
        try {
            $path = \PathUtils\canonicalize($path);
        } catch (Exception $error) {
            \Debug::LogWarning(
                "[ContentDataase::get] >>> Path Canonicalized Failed\n" .
                    "  path : ${path}\n" .
                    '  error: ' . $error->getMessage()
            );
            return false;
        }

        if (!$forceUpdate && isset($this->cachedContents[$path])) {
            return $this->cachedContents[$path];
        }

        return $this->cachedContents[$path] = $this->getFromFile($path);
    }

    public function exists(string $path)
    {
        try {
            $path = \PathUtils\canonicalize($path);
            return @file_exists(CONTENTS_HOME_DIR  . '/' . $path . Content::EXTENSION);
        } catch (Exception $error) {
            return false;
        }
    }

    /**
     * @return string|false 読み込んだ文字列を返します. 失敗した場合はfalseを返します.
     */
    private static function readFile(string $filePath)
    {
        if (is_dir($filePath)) {
            \Debug::LogWarning("[ContentDatabase::readFile] >>> Directory'{$filePath}' was given.");
            return false;
        }

        //file読み込み
        $fp = @fopen($filePath, "r");
        if ($fp === false) {
            \Debug::LogWarning("[ContentDatabase::readFile] >>> Cannot open the file'{$filePath}'.");
            fclose($fp);
            return false;
        }

        if (!flock($fp, LOCK_SH)) {
            \Debug::LogWarning("[ContentDatabase::readFile] >>> Cannot lock the file'{$filePath}'.");
            fclose($fp);
            return false;
        }

        $text = stream_get_contents($fp);
        fclose($fp);

        return $text;
    }


    /**
     * Get content from file.
     * 
     * @param string $path The canonicalized path to content file.
     *  The path is relative from CONTENTS_HOME_DIR and the extension is removed.
     *  ex) Master/Sub/Test
     * @return Content|false 
     */
    private function getFromFile(string $path)
    {
        $content = new Content($this);

        $content->path = $path;

        // Homeディレクトリを含めた正しいパスへ
        $filePath = ContentPathUtils::RealPath($path . Content::EXTENSION);
        if ($filePath === false) {
            return false;
        }

        $content->openedTime = time();
        $content->modifiedTime = @filemtime($filePath); // 読み込む前に更新日時を取得
        if ($content->modifiedTime === false) {
            \Debug::LogWarning('Cannot get content modified time. content path: ' . $path);
            return false;
        }

        $text = self::readFile($filePath);
        if ($text === false) {
            return false;
        }
        $content->rawText = $text;

        // 実パスを保存
        $content->realPath = $filePath;

        // テキストを解析して要素を取得
        $parsed = Content::parse($text);
        $content->header = $parsed['header'];
        $content->summary = $parsed['summary'];
        $content->body = $parsed['body'];

        $header = $parsed['header'];
        if (isset($header['title']) && is_string($header['title'])) {
            $content->title = $header['title'];
        }
        if (isset($header['parent']) && is_string($header['parent'])) {
            $content->parentPath = $header['parent'];
        }
        if (isset($header['date']) && is_string($header['date'])) {
            $content->createdTimeRaw = $header['date'];
            $content->createdTime = strtotime($content->createdTimeRaw);
        }
        if (isset($header['tags']) && is_string($header['tags'])) {
            foreach (explode(',', $header['tags']) as $tag) {
                if (!empty($tag = trim($tag))) {
                    $content->tags[] = $tag;
                }
            }
        }
        if (isset($header['children']) && is_array($header['children'])) {
            foreach ($header['children'] as $path) {
                if (!is_string($path)) continue;
                if (!empty($path = trim($path))) {
                    $content->childPathList[] = $path;
                }
            }
        }
        // \Debug::Log(print_r($content, true));
        return $content;
    }
}


/**
 * Content Data Container & Accessor
 */
class Content
{
    // FIXME: data member properties should be readonly.


    const EXTENSION = '.content';

    const ELEMENT_TAG_MAP =
    [
        "Header"    => ["StartTag" => "<Header>", "EndTag" => "</Header>"],
        "Parent"    => ["StartTag" => "<Parent>", "EndTag" => "</Parent>"],
        "Child"     => ["StartTag" => "<Child>", "EndTag" => "</Child>"],
        "Title"     => ["StartTag" => "<Title>", "EndTag" => "</Title>"],
        "CreatedAt" => ["StartTag" => "<CreatedAt>", "EndTag" => "</CreatedAt>"],
        "Summary"   => ["StartTag" => "<Summary>", "EndTag" => "</Summary>"],
        "Tags"      => ["StartTag" => "<Tags>", "EndTag" => "</Tags>"]
    ];

    private $database = null;

    public function __construct(ContentDatabase $database = null)
    {
        $this->database = $database ?? new ContentDatabase;
    }

    /** 
     * The canonicalized content path which is relative from CONTENTS_HOME_DIR.
     * The extension is removed.
     * 
     * ex)
     *  'Master/Contents/Root'
     * 
     * @var string
     */
    public $path = "";


    /**
     * Real path to the content file
     * @var string
     */
    public $realPath = "";

    /** 
     * コンテンツタイトル 
     * @var string
     */
    public $title = "";

    /** 
     * コンテンツ概要
     * @var string 
     */
    public $summary = "";

    /** 
     * コンテンツの内容
     * @var string 
     */
    public $body = "";

    /**
     * ファイルの最終更新時刻(Unix タイムスタンプ)
     * @var int
     */
    public $modifiedTime;

    /**
     * ファイルの作成時刻(Unix タイムスタンプ)
     * @var int|false
     */
    public $createdTime = false;

    /**
     * ファイルの作成時刻(コンテンツファイルに書かれている文字列)
     * @var string
     */
    public $createdTimeRaw = "";

    /**
     * @var int
     */
    public $openedTime;

    /** 
     * 親コンテンツのパス
     * @var string
     */
    public $parentPath = "";

    /** 
     * 子コンテンツのパスリスト
     * @var array
     */
    public $childPathList = array();

    /**
     * タグリスト [tagA, tagB, ...]
     * @var array
     */
    public $tags = array();

    /**
     * @var string
     */
    public $rawText = '';

    public $header = [];

    /**
     * このContentが末端コンテンツかどうか
     */
    public function isEndpoint()
    {
        return count($this->childPathList) == 0;
    }

    /**
     * このContentが最上位コンテンツかどうか
     */
    public function isRoot()
    {
        return $this->parentPath == "";
    }

    /**
     * このContentが何番目の子供か調べます
     */
    public function nthChild()
    {
        $parent = $this->parent();
        if ($parent === false) {
            return -1;
        }

        $dirPath = dirname($parent->path) . '/';
        foreach ($parent->childPathList as $i => $path) {
            $realPath = ContentPathUtils::RealPath($dirPath . $path . self::EXTENSION);
            if ($realPath === $this->realPath) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * このContentが含むChildを取得
     * 
     * @param int $index 取得したい子コンテンツのインデックス
     * @return Content|false 取得した子コンテンツ, 失敗した場合はfalse
     */
    public function child($index)
    {
        $childPath = dirname($this->path) . '/' . $this->childPathList[$index];

        return $this->database->get($childPath);
    }

    /**
     * このContentの親コンテンツを取得.
     * 失敗したときは, falseを返す.
     * 
     * [NOTE]
     *  ファイルを読み込むため, 呼び出しは最小限にすることをお勧めします.
     * 
     * @return Content|false 取得した親content, 失敗した場合は, false
     */
    public function parent()
    {
        if ($this->parentPath === "") {
            return false;
        }

        $parentPath = dirname($this->path) . "/" . $this->parentPath;

        return $this->database->get($parentPath);
    }

    public static function parse(string $rawText)
    {
        $rawText = str_replace("\r", "", $rawText);

        $header = [];

        if (preg_match('/^---\n(.*?)\n---\n(\n|$)/s', $rawText, $matches, PREG_OFFSET_CAPTURE)) {
            // match header section
            $stack = [
                ['lspaces' => 0, 'value' => &$header]
            ];
            end($stack);
            $currentValue = &$header;

            try {
                foreach (explode("\n", $matches[1][0]) as $i => $line) {
                    // \Debug::Log([$line]);
                    $trimed = ltrim($line);
                    $lspaces = strlen($line) - strlen($trimed);

                    $trimed = rtrim($trimed);
                    if ('' === $trimed) continue;

                    $key = null;

                    if (false !== ($pos = strpos($trimed, ':'))) {
                        $key = rtrim(substr($trimed, 0, $pos));
                        $trimed = ltrim(substr($trimed, $pos + 1));
                    }
                    $value = $trimed;
                    // \Debug::Log(['kv', $key, $value]);

                    if ($stack[key($stack)]['lspaces'] == $lspaces) {
                        // same indent level
                    } else if ($stack[key($stack)]['lspaces'] < $lspaces) {
                        // indent
                        $endKey = key($currentValue);
                        if (!$endKey) {
                            throw new RuntimeException();
                        }
                        $currentValue = &$currentValue[$endKey];
                        $currentValue = [];

                        $stack[] = [
                            'lspaces' => $lspaces,
                            'value' => &$currentValue
                        ];
                        end($stack);
                    } else {
                        // outdent
                        while ($stack[key($stack)]['lspaces'] > $lspaces) {
                            if (!array_pop($stack)) {
                                throw new RuntimeException();
                            }
                            end($stack);
                        }
                        $currentValue = &$stack[key($stack)]['value'];
                    }
                    if (is_null($key)) {
                        $currentValue[] = $value;
                    } else {
                        $currentValue[$key] = $value;
                    }

                    end($currentValue);
                }
                $rawText = substr($rawText, strlen($matches[0][0]));
            } catch (Exception $error) {
                // header parse error
                $header = [];
            }
            // \Debug::Log($header);
        }

        $summary = '';
        if (preg_match('/^(.*?)\n\n===\n(\n|$)/s', $rawText, $matches, PREG_OFFSET_CAPTURE)) {
            $summary = $matches[1][0];
            $rawText = substr($rawText, strlen($matches[0][0]));
            // \Debug::Log($summary);
        }
        // \Debug::Log($rawText);

        return [
            'header' => $header,
            'summary' => $summary,
            'body' => $rawText
        ];
    }
}


class ContentPathUtils
{

    /**
     * Homeからの相対パスを実パスにします.
     * 
     * if $assureFileExists is true and $path does not exist, returns false.
     * 
     * @param string $path
     */
    public static function RealPath($path, $assureFileExists = true)
    {
        // FIXME: Check the path is included in CONTENTS_HOME_DIR.
        if ($assureFileExists) {
            return realpath(CONTENTS_HOME_DIR . "/" . $path);
        }

        return CONTENTS_HOME_DIR . "/" . $path;
    }

    /**
     * 実パスをHomeからの相対パスにします.
     */
    public static function RelativePath($dst)
    {
        return \PathUtils\getRelative($dst, CONTENTS_HOME_DIR);
    }
}
