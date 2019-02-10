<?php


require_once dirname(__FILE__) . "/Debug.php";

if(!defined('CONTENTS_HOME_DIR') ) define('CONTENTS_HOME_DIR', getcwd());


class Content
{

    private static $elementTagMap =
    [
        "Header" => ["StartTag" => "<Header>", "EndTag" => "</Header>"],
        "Parent" => ["StartTag" => "<Parent>", "EndTag" => "</Parent>"],
        "Child" => ["StartTag" => "<Child>", "EndTag" => "</Child>"],
        "Title" => ["StartTag" => "<Title>", "EndTag" => "</Title>"],
        "CreatedAt" => ["StartTag" => "<CreatedAt>", "EndTag" => "</CreatedAt>"],
        "Summary" => ["StartTag" => "<Summary>", "EndTag" => "</Summary>"],
        "Tag" => ["StartTag" => "<Tag>", "EndTag" => "</Tag>"]
    ];


    private static $dateFormat = "Y/m/d";

    private static $contentFileExtension = ".content";

    private static $globalTagMapMetaFileName = "GlobalTagMap.meta";
    
    private static $globalTagMap = NULL;



    // コンテンツファイルへのパス.
    private $path = "";
    private $title = "";
    private $summary = "";
    private $body = "";
    private $updatedAt = "";
    private $updatedAtTimestamp;
    private $createdAt = "";

    //parentへのfilePath
    private $parentPath = "";

    //各childへのfilePathList
    private $childPathList = array();

    private $tags = array();

    public static function ContentFileExtension(){return static::$contentFileExtension;}

    public static function GlobalTagMap(){ return static::$globalTagMap;}

    public static function CreateGlobalTagMap($rootContentPath)
    {
        $content = new Content();
        
        $contentPathStack = [];
        $contentPathStack[] = $rootContentPath;

        $openContentPathMap = [];

        $workGlobalTagMap = [];
        
        while(count($contentPathStack) > 0){
            //var_dump($contentPathStack);

            if( !$content->SetContent(array_pop($contentPathStack)) ){
                
                continue;
            }

            if(array_key_exists($content->Path(), $openContentPathMap)){
                Debug::LogWarning("[CreateGlobalTagMap] >> Detect Circular reference. " . $content->Path());
                continue;
            }
            $openContentPathMap[$content->Path()] = null;


            $tagsCount = count($content->Tags());
            for($i = 0; $i < $tagsCount; $i++){
                if(!array_key_exists($content->Tags()[$i], $workGlobalTagMap)){
                    $workGlobalTagMap[$content->Tags()[$i]] = [];
                }

                $workGlobalTagMap[$content->Tags()[$i]][] = $content->Path();
            }


            $childPathListCount = count($content->ChildPathList());
            for($i = 0; $i < $childPathListCount; $i++){
                
                $childPath = dirname($content->Path()) . '/' . $content->ChildPathList()[$i];
                
                $contentPathStack[] = $childPath;
            }
        }
        ksort($workGlobalTagMap);
        static::$globalTagMap = $workGlobalTagMap;
    }


    public static function SaveGlobalTagMap($metaFileName)
    {
        $metaFileName = static::RealPath($metaFileName, '', false);

        $encoded = json_encode(static::$globalTagMap);
        file_put_contents($metaFileName , $encoded);
    }

    public static function LoadGlobalTagMap($metaFileName)
    {
        $metaFileName = static::RealPath($metaFileName, '', false);
        //Debug::Log($metaFileName);
        if(file_exists($metaFileName) && is_file($metaFileName)){
            $json = file_get_contents($metaFileName);
            $json = mb_convert_encoding($json, 'UTF8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN');
            static::$globalTagMap = json_decode($json,true);

            return true;
        }

        return false;
    }

    public function Tags(){ return  $this->tags;}
    public function SetTags($tags){$this->tags = $tags; }



    //このContentがあったファイルのパスを取得
    public function Path(){ return $this->path;}
    public function SetPath($path) {$this->path = $path;}

    //Title(題名)取得
    public function Title(){return $this->title;}
    public function SetTitle($title){$this->title = $title;}

    //概要取得
    public function Summary(){return $this->summary;}
    public function SetSummary($summary){ $this->summary = $summary;}

    //このContentが持つ子Contents取得
    public function ChildPathList(){return $this->childPathList;}
    public function SetChildPathList($childPathList){$this->childPathList = $childPathList;}
    

    public function ParentPath(){return $this->parentPath;}
    public function SetParentPath($parentPath){$this->parentPath = $parentPath;}

    //このContentが持つ子Contentsの数
    public function ChildCount()
    {
        return count($this->childPathList);
    }

    //このContentのRootContent取得
    public function Body(){return $this->body;}
    public function SetBody($body){ $this->body = $body;}


    //このContentが末端コンテンツかどうか
    public function IsFinal()
    {
        return count($this->childPathList) == 0;
    }

    //このContentが最上位コンテンツかどうか
    public function IsRoot()
    {
        return $this->parentPath == "";
    }

    //このContentが持つupdatedAt取得
    public function UpdatedAt(){return $this->updatedAt;}
    public function UpdatedAtTimestamp(){return $this->updatedAtTimestamp;}

    public function CreatedAt(){return $this->createdAt;}
    public function SetCreatedAt($createdAt){$this->createdAt = $createdAt;}


    //このContentが何番目の子供か調べます
    public function ChildIndex()
    {
        $parent = $this->Parent();
        if($parent === false)
        {
            return -1;
        }

        $myIndex = -1;
        $brothers = $parent->ChildPathList();
        $count = count($brothers);

        for($i = 0; $i < $count; $i++)
        {
            if(static::NormalizedPath(dirname($parent->path) . '/' . $brothers[$i]) === $this->path)
            {
                $myIndex =$i;
                break;
            }
        }

        return $myIndex;
    }


    /**
     * このContentが含むChildを取得
     * 
     * @param int $index 取得したい子コンテンツのインデックス
     * @return Content|false 取得した子コンテンツ, 失敗した場合はfalse
     */
    public function Child($index)
    {
        $childPath = dirname($this->path) . '/' . $this->childPathList[$index];
        
        $child = new Content();
        if($child->SetContent($childPath) === false)
        {
            return false;
        }

        return $child;
    }


    /**
     * このContentの親コンテンツを取得.
     * [NOTE]
     *  ファイルを読み込むため, 呼び出しは最小限にすることをお勧めします.
     * 
     * @return Content|false 取得した親content, 失敗した場合は, false
     */
    public function Parent()
    {
        if($this->parentPath === ""){
            return false;
        }

        $parentPath = dirname($this->path) . "/" . $this->parentPath;

        //Debug::Log($this->parentPath);
        $parent = new Content();
        if($parent->SetContent($parentPath) === false)
        {
            return false;
        }
        return $parent;
    }


    /**
     * fileを読み込みContentの情報を設定します.
     * 
     * @param string $contentPath コンテンツファイルへのパス. CONTENTS_HOME_DIRからの相対パス
     * @return true|false 
     */
    function SetContent($contentPath)
    {
        //拡張子確認
        //$ext = substr($filePath, strrpos($filePath, '.') + 1);
        //if($ext != static::$contentFileExtension)
        //{
        //    return false;
        //}
        
        // Homeディレクトリを含めた正しいパスへ
        $filePath = static::RealPath($contentPath);
        if($filePath === false){
            return false;
        }

        $text = $this->ReadFile($filePath);
        if($text === false){
            return false;
        }


        
        // 拡張子を除くPathを保存
        $this->path = static::NormalizedPath($contentPath);

        //$this->path = $filePath;
        $this->updatedAtTimestamp = filemtime($filePath);
        $this->updatedAt = date(static::$dateFormat, $this->updatedAtTimestamp);

        //$dataList = $this->ToDataList($data);

        //Content情報を初期化
        $this->body = "";
        $this->childPathList = array();
        $this->parentPath = "";
        $this->tags = array();



        $lines = explode("\n", $text);
        $lineCount = count($lines);

        $isInHeader = false;
        $isInSummary = false;

        // 各行ごとの処理
        for($i = 0; $i < $lineCount; $i++){
            
            if($isInHeader){
                // Header内にある場合はHeaderの終了タグを検索する.
                if(strpos($lines[$i], static::$elementTagMap['Header']['EndTag']) !== false){
                    $isInHeader = false;
                    continue;
                }
            }

            else{
                // Header内にないときはHeaderの開始タグを検索する.
                if(strpos($lines[$i], static::$elementTagMap['Header']['StartTag']) !== false){
                    $isInHeader = true;
                    continue;
                    
                }
            }

            // Header内
            if($isInHeader){
            
                if($isInSummary){
                    if(strpos($lines[$i], static::$elementTagMap['Summary']['EndTag']) !== false){
                        $isInSummary = false;
                        continue;
                    }
                }
                else{
                    $position = 0;

                    if(($position = strpos($lines[$i], static::$elementTagMap['Parent']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['Parent']['StartTag']);

                        $this->parentPath = substr($lines[$i], $position);
                        $this->parentPath = str_replace(" ", "", $this->parentPath);

                        continue;
                    
                    } elseif(($position = strpos($lines[$i], static::$elementTagMap['Child']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['Child']['StartTag']);
                        
                        $childPath = substr($lines[$i], $position);
                        $childPath = str_replace(" ", "", $childPath);

                        $this->childPathList[] = $childPath;
                        
                        continue;

                    } elseif(($position = strpos($lines[$i], static::$elementTagMap['CreatedAt']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['CreatedAt']['StartTag']);
                        
                        $this->createdAt = substr($lines[$i], $position);
                        $this->createdAt = str_replace(" ", "", $this->createdAt);

                        continue;

                     } elseif(($position = strpos($lines[$i], static::$elementTagMap['Title']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['Title']['StartTag']);
                        
                        $this->title = substr($lines[$i], $position);
                        $this->title = trim($this->title);
                        continue;

                    } elseif(($position = strpos($lines[$i], static::$elementTagMap['Tag']['StartTag'])) !== false){
                        $position += strlen(static::$elementTagMap['Tag']['StartTag']);
                        
                        $tagsStr = substr($lines[$i], $position);
                        $tags = explode(",", $tagsStr);
                        $tagsCount = count($tags);
                        
                        for($j = 0; $j < $tagsCount; $j++){
                            $tags[$j] = trim($tags[$j]);
                            if($tags[$j] != ""){
                                $this->tags[] = $tags[$j];
                            }
                        }
        
                        continue;

                    } elseif(($position = strpos($lines[$i], static::$elementTagMap['Summary']['StartTag'])) !== false){
                        $isInSummary = true;
                        continue;

                    }
                }

                if($isInSummary){
                    $this->summary .= $lines[$i] . "\n";
                }
            } // End Header内

            else{
                $this->body .= $lines[$i] . "\n";
            }
        }

        // summary, bodyの最後の改行を取り除く
        $this->summary = substr($this->summary, 0, -1);
        $this->body = substr($this->body, 0, -1);

        return true;
    }

    public function ToContentFileString(){
        $output = "";

        $output .= static::$elementTagMap["Header"]["StartTag"] . "\n";

        $output .= "    " . static::$elementTagMap["Parent"]["StartTag"] . " " . $this->parentPath . "\n";
        $output .= "    " . static::$elementTagMap["Title"]["StartTag"] . " " . $this->title . "\n";
        $output .= "    " . static::$elementTagMap["CreatedAt"]["StartTag"] . " " . $this->createdAt . "\n";
        $output .= "    " . static::$elementTagMap["Tag"]["StartTag"] . " " . implode(", ", $this->tags) . "\n";
        $output .= "    " . static::$elementTagMap["Summary"]["StartTag"] . "\n";
        $output .= $this->summary . "\n";
        $output .= "    " . static::$elementTagMap["Summary"]["EndTag"] . "\n";
        foreach($this->childPathList as $childPath){
            $output .= "    " . static::$elementTagMap["Child"]["StartTag"] . " " . $childPath . "\n";
        }

        $output .= static::$elementTagMap["Header"]["EndTag"] . "\n";

        $output .= $this->body;

        return $output;
    }


    public function SaveContentFile(){
        $realPath = static::RealPath($this->path);
        
        file_put_contents($this->realPath, $this->ToContentFileString(), LOCK_EX);

        //Debug::Log($output);
    }

    //
    // ファイルを読み込みます
    //
    // @param filePath:
    //  読み込み先
    //
    // @return:
    //  読み込んだ文字列を返します. 失敗した場合はfalseを返します.
    //
    static function ReadFile($filePath)
    {
        if(is_dir($filePath))
        {
            Debug::LogError("[ReadFile] Fail > Directory'{$filePath}'が読み込まれました.");
            return false;
        }

        //file読み込み
        $text = @file_get_contents($filePath);

        if($text === false)
        {
            Debug::LogError("[ReadFile] Fail > file'{$filePath}'の読み込みに失敗しました.");
            return false;
        }

        // Unix処理系の改行コード(LF)にする.
        $text = str_replace("\r", "", $text);

        //Debug::Log("[ReadFile] file'{$filePath}'を読み込みました.");

        return $text;
    }

    // コンテントパスを正規化します.
    public static function NormalizedPath($contentPath, $extention = null, $removeExtention = true){
        // コンテンツパスを実パスにしてから, Homeからの相対パスへ
        $realPath = static::RealPath($contentPath, $extention);
        if($realPath === false){
            return false;
        }

        $relative = static::RelativePath($realPath);
        if($relative === false){
            return false;
        }

        
        // 拡張子をとる.
        if($removeExtention){
            return substr($relative, 0, strrpos($relative, '.'));
        }

        return $realPath;
    }

    // コンテントパスを実パスにします.
    public static function RealPath($contentPath, $extention = null, $normalized = true){
        if($extention === null){
            $extention = static::$contentFileExtension;
        }

        if($normalized){
            return realpath(CONTENTS_HOME_DIR . "/" . $contentPath .  $extention);
        }
        
        return CONTENTS_HOME_DIR . "/" . $contentPath .  $extention;
    }


    

    // 実パスをHomeからの相対パスにします.
    public static function RelativePath($dst) {
        switch (false) {
            case $src = CONTENTS_HOME_DIR:
            case $dst = realpath($dst):
            case $src = explode(DIRECTORY_SEPARATOR, $src):
            case $dst = explode(DIRECTORY_SEPARATOR, $dst):
            case $src[0] === $dst[0]:
                return false;
        }
        //var_dump($dst);
        //var_dump($src);
        $cmp =
            DIRECTORY_SEPARATOR === '\\' ?
            'strcasecmp' :
            'strcmp'
        ;

        for (
            $i = 0;
            isset($src[$i], $dst[$i]) && !$cmp($src[$i], $dst[$i]);
            ++$i
        );


        return implode(
            '/',
            array_merge(
                array('.'),
                ($count = count($src) - $i) ?
                    array_fill(0, $count, '..') :
                    array()
                ,
                array_slice($dst, $i)
            )
        );
        // return implode(
        //     DIRECTORY_SEPARATOR,
        //     array_merge(
        //         array('.'),
        //         ($count = count($src) - $i) ?
        //             array_fill(0, $count, '..') :
        //             array()
        //         ,
        //         array_slice($dst, $i)
        //     )
        // );
    }
}

?>