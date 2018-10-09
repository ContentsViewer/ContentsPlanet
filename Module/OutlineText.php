<?php

namespace OutlineText;

/*
*
*/

require_once dirname(__FILE__) . "/Debug.php";


class Context{
    

    public $chunks = array();

    
    private $isEndOfChunk = false;
    private $currentChunk = null;
    private $chunksCount = 0;
    private $currentChunkIndex = 0;
    private $nextLineChunk = null;
    private $nextChunk = null;

    private $referenceCount = 0;
    private $referenceMap = [];

    public function CurrentChunk(){
        return $this->currentChunk;
    }

    public function NextLineChunk(){
        return $this->nextLineChunk;
    }

    public function IsEndOfChunk(){
        return $this->isEndOfChunk;
    }

    public function NextChunk(){
        return $this->nextChunk;
    }

    public function SetChunks($chunksToSet){
        $this->chunks = $chunksToSet;
        $this->chunksCount = count($this->chunks);
        $this->currentChunkIndex = -1;
        $this->isEndOfChunk = false;
        $this->IterateChunk();

        //var_dump($chunksToSet);

    }

    public function IterateChunk(){
        if($this->isEndOfChunk){
            return;
        }

        if((++$this->currentChunkIndex) >= $this->chunksCount){
            $this->currentChunk = null;
            $this->isEndOfChunk = true;
            $this->nextLineChunk = null;
            $this->nextChunk = null;
            return;
        }

        $this->currentChunk = $this->chunks[$this->currentChunkIndex];

        $this->nextLineChunk = null;
        if($this->currentChunk["nextLineChunkIndex"] != -1){
            $this->nextLineChunk = $this->chunks[$this->currentChunk["nextLineChunkIndex"]];
        }
        
        
        $this->nextChunk = ($this->currentChunkIndex < $this->chunksCount - 1) ? $this->chunks[$this->currentChunkIndex + 1] : null;


    }

    public function AddReference($key){
        if(!array_key_exists($key, $this->referenceMap)){
            $this->referenceMap[$key] = ["index" => -1, "content" => ""];
        }

        if($this->referenceMap[$key]["index"] == -1){
            $this->referenceMap[$key]["index"] = ++$this->referenceCount;
        }


        return $this->referenceMap[$key]["index"];
    }

    public function SetReference($key, $content){
        if(!array_key_exists($key, $this->referenceMap)){
            $this->referenceMap[$key] = ["index" => -1, "content" => ""];
        }

        $this->referenceMap[$key]["content"] = $content;

    }

    public function ReferenceList(){
        $list = [];

        foreach($this->referenceMap as $key => $value){
            if($value["index"] != -1){
                $list[$value["index"]] =["content" => $value["content"], "key" => $key];
            }
        }
        //ksort($list);

        return $list;
    }
}


class Parser{
    
    private static $indentSpace = 4;

    private static $htmlTagList = [
        "li", "dt", "dd", "p", "tr", "td", "th", "rt", "rp", "optgroup",
        "option", "thead", "tfoot", 

        "tbody", "colgroup",

        "script", "noscript",

        "pre", "ol", "ul", "dl", "figure", "figcaption", "div",

        "a", "em", "strong", "small", "s", "cite", "q", "i", "b", "span",

        "h1", "h2", "h3", "h4", "h5", "h6",

        "table", "caption",

        "form", "button", "textarea", "mark"

    ];

    private static $specialCharacterEscapeExclusionPattern = 
        "/(<br *?>)|(<br *\/?>)|(<img.*?>)|(<img.*?\/>)|(<hr *?>)|(<hr *\/?>)|(<input.*?>)|(<input.*?\/>)/";
    

    private static $commentStartToken = "<!--";
    private static $commentEndToken = "-->";




    private static $blockSeparators;
    private static $patternTagBlockStartTag;
    private static $patternTagBlockEndTag;

    public static function Init(){

        // $context = new Context();
        // $context->SetChunks([0,1,2]);
        // var_dump($context->CurrentChunk());
        // $context->IterateChunk();
        // var_dump($context->CurrentChunk());
        // $context->IterateChunk();
        // var_dump($context->CurrentChunk());
        // $context->IterateChunk();
        // var_dump($context->CurrentChunk());
        // echo $context->IsEndOfChunk();
        
        // ブロックごとに区切るためのpattern
        static::$blockSeparators = "/";
        $blockTagCount = count(static::$htmlTagList);
        for($i = 0; $i < $blockTagCount; $i++){
            static::$blockSeparators .= "(<" . static::$htmlTagList[$i] . "\b.*?>)|(<\/" . static::$htmlTagList[$i] . " *?>)";
            if($i < $blockTagCount - 1){
                static::$blockSeparators .= "|";
            }
        }

        static::$blockSeparators .= "|(`)";

        static::$blockSeparators .= "/i";




        static::$patternTagBlockStartTag = "/";
        $blockTagCount = count(static::$htmlTagList);
        for($i = 0; $i < $blockTagCount; $i++){
            static::$patternTagBlockStartTag .=  "(<" . static::$htmlTagList[$i] . "\b.*?>)";
            if($i < $blockTagCount - 1){
                static::$patternTagBlockStartTag .= "|";
            }
        }

        static::$patternTagBlockStartTag .= "/i";




        
        static::$patternTagBlockEndTag = "/";
        $blockTagCount = count(static::$htmlTagList);
        for($i = 0; $i < $blockTagCount; $i++){
            static::$patternTagBlockEndTag .= "(<\/" . static::$htmlTagList[$i] . " *?>)";
            if($i < $blockTagCount - 1){
                static::$patternTagBlockEndTag .= "|";
            }
        }

        static::$patternTagBlockEndTag .= "/i";




    }




    //
    // 処理の流れは以下の通り.
    //  1. Chunkに分ける
    //  2. Chunkごとにデコード処理を行う.
    //   2.1. 処理ごとにspanElementの処理を行う.
    //
    public static function Parse($plainText){
        //\Debug::Log($plainText);

        $output = "";

        // 前処理
        // 空行を追加する.
        // ファイルの終わりの処理と空行の処理を一緒にする.
        $plainText .= "\n";


        // 文脈用意
        $context = new Context();

        // チャンクにわける
        $context->SetChunks(static::SplitToChunk($plainText));




        // --- 複数のチャンクをまたいで存在する情報 ----
        // [TODO]
        //  以下の情報は各要素ごとのクラスで持つべき.

        $indentLevelPrevious = -1;
        $indentLevel = 0;

        $beginParagraph = false;
        $beginList = false;
        $isOrderdList = false;
        $beginTable = false;
        $listItemIndentLevel = 0;
        $tableColumnHeadCount = 0;
        $beginTableBody = false;
        $beginSectionTitle = false;
        $beginTableRow = false;
        $boxIndentStack = [];
        $beginReferenceList = false;

        $skipNextLineChunk = false;

        // End 複数のチャンクをまたいで存在する情報 ----

        // --- 各チャンクごとに対して -----------------------------------
        for(; !$context->IsEndOfChunk(); $context->IterateChunk()){

            $currentChunk = $context->CurrentChunk();


            if($currentChunk["isInlineCode"]){
                $output .= "<code>" . static::EscapeSpecialCharactersForce($currentChunk["content"]) . "</code>";
                continue;
            }

            elseif($currentChunk["isTagElement"]){
                $output .= $currentChunk["content"];
                continue;
            }
            elseif($currentChunk["isEmptyLine"]){

                if($beginSectionTitle){
                    $output .= "</h" . ($indentLevel + 2) . ">";
                    $beginSectionTitle = false;
                }

                if($beginTableRow){
                    $output .= "</tr>";
                    $beginTableRow = false;
                }

                if($beginParagraph){
                    $output .= "</p>";
                    $beginParagraph = false;
                }

                if($beginList){
                    //echo $listItemIndentLevel;
                    $indentLevelPrevious -= $listItemIndentLevel;

                    while($listItemIndentLevel >= 0){
                        $output .= "</li>" . ($isOrderdList ? "</ol>" : "</ul>");
                        
                        //echo "sa";
                        $listItemIndentLevel--;
                    }

                    $listItemIndentLevel = 0;

                    $beginList = false;
                }

                if($beginTable){
                    if($beginTableBody){
                        $output .= "</tbody>";
                    }
                    else{
                        $output .= "</thead>";
                    }

                    $output .= "</table>";
                    $tableColumnHeadCount = 0;
                    $beginTableBody = false;
                    $beginTable = false;
                }

                if($beginReferenceList){
                    $referenceList = $context->ReferenceList();
                    $referenceCount = count($referenceList);

                    $output .= "<ol class='reference-list'>";
                    for($index = 1; $index <= $referenceCount; $index++){
                        $output .= "<li><a name='ref-" . $referenceList[$index]["key"] . "'></a><cite>" . $referenceList[$index]["content"] . "</cite></li>";
                    }
                    $output .= "</ol>";

                    //var_dump($referenceList);
                    $beginReferenceList = false;
                }

                continue;
            }
            
            // 文中
            elseif($currentChunk["indentLevel"] == -1){
                $output .= static::DecodeSpanElements($currentChunk["content"], $context);
                continue;
            }


            //
            // ここから下の処理対象は, 各行の先頭にあるチャンクであることが保証されている.
            //

            if($skipNextLineChunk){
                //echo "sasasad";
                
                $skipNextLineChunk = false;

                continue;
            }
            
            if($beginSectionTitle){
                $output .= "</h" . ($indentLevel + 2) . ">";
                $beginSectionTitle = false;
            }


            if($beginTableRow){
                $output .= "</tr>";
                $beginTableRow = false;
            }



            //
            // インデントレベルの変化を見る
            //
            // 処理過程
            //  リストブロック中である
            //   リストアイテムのレベルを変更する
            //
            //  その他
            //   セクションのレベルを変更する
            // 
            // 行頭がSpaceのみの場合でも, 
            // インデントレベル変更によるセクションタグの作成が起こる可能性がある.
            $indentLevel = $currentChunk["indentLevel"];

            // 右へインデント
            if($indentLevel > $indentLevelPrevious){
                $levelDiff = $indentLevel - $indentLevelPrevious;

                while($levelDiff > 0){
                    //echo "->:" . $blocks[$j];

                    if($beginList){
                        $output .= ($isOrderdList ? "<ol>" : "<ul>");
                        $listItemIndentLevel++;
                    }else{
                        $output .= "<div class='section'>";
                    }


                    $levelDiff--;
                }
                
            }

            // 左へインデント
            if($indentLevel < $indentLevelPrevious){
                $levelDiff = $indentLevelPrevious - $indentLevel;
                if($beginList){
                    $output .= "</li>";
                }

                while($levelDiff > 0){
                    //echo "<-:" . $blocks[$j];


                    if($beginList){
                        $output .= ($isOrderdList ? "</ol>" : "</ul>") . "</li>";
                        $listItemIndentLevel--;
                    }else{
                        $output .= "</div>";
                    }



                    $levelDiff--;
                }
            }
            
            // インデントそのまま
            if($indentLevel == $indentLevelPrevious){
                if($beginList){
                    $output .= "</li>";
                }
            }

            $indentLevelPrevious = $indentLevel;

            // End インデントの変化を見る ---




            
            // --- Code block ----------------------
            if($currentChunk["isCodeBlock"]){
                
                // code blockに入る


                if($currentChunk["codeBlockAttribute"] == "math"){
                    $output .= "<div>";
                    //$output .= $currentChunk["content"];
                    $output .= static::EscapeSpecialCharactersForce($currentChunk["content"]);
                    $output .= "</div>";
                }
                else{
                    $output .= "<pre class='brush: ". $currentChunk["codeBlockAttribute"] . ";'>";
                    $output .= static::EscapeSpecialCharactersForce($currentChunk["content"]);
                    $output .= "</pre>";
                }


                continue;
            
            
            } // End Code block ------------------


            // 空文字の時
            // インデント値はあるが, 空文字
            // その次がインラインコード, html要素のときに起こる.
            if($currentChunk["content"] == ""){

                // 次がインラインコードのときは, このまま処理を続ける.
                // 次がインラインコードのとき, このまま処理を続けないと,
                // <p></p>で囲まれない.
                //
                // 逆にその次が, html要素のときは, <p></p>で囲まれてしまい, 
                // <p></p>で囲めない要素が来た時によろしくない.
                if(($context->NextChunk() !== null) && $context->NextChunk()["isInlineCode"]){
                    
                }
                else{
                    // 次がhtml要素などはこのまま処理を続けない.
                    continue;
                }
                //continue;
            }
            
            $ret = array();
            // --- Boxの開始と終了ライン ------------------
            if(static::CheckBoxStartOrEndLine($boxIndentStack, $context, $ret)){
                
                //echo "sasas";
                if($ret["isStartOfBox"]){
                    $boxIndentStack[] = $indentLevel;

                    //$output .= ""
                    $output .= "<div class='box-" . $ret['type'] . "'><span class='box-title'>" . 
                                static::DecodeSpanELements($ret["title"], $context) . "</span>";

                    $skipNextLineChunk = true;

                }

                if($ret["isEndOfBox"]){
                    array_pop($boxIndentStack);
                    
                    $output .= "</div>";
                    //$output .= "end";
                }
            } // End Boxの開始と終了ライン -----

            // --- 見出し --------------------
            elseif(static::CheckHeadingLine($context, $ret)){
                
                $output .= "<h" . ($indentLevel + 2) . " ";
                if($indentLevel <= 0){
                    $output .= "class = 'section-title'>";
                }
                elseif($indentLevel == 1){
                    $output .= "class = 'sub-section-title'>";
                }
                elseif($indentLevel == 2){
                    $output .= "class = 'sub-sub-section-title'>";
                }
                else{
                    $output .= "class = 'sub-sub-sub-section-title'>";
                }

                $output .= static::DecodeSpanElements($ret["heading"], $context);

                if($ret["nextLineIsHorizontalLine"]){
                    $skipNextLineChunk = true;
                }

                $beginSectionTitle = true;
            } // End 見出し -----------
            
            // --- 平行線 -------------------------------
            elseif(preg_match("/^----*$/", $currentChunk["content"])){
                $output .= "<hr>";
            }
            // End 平行線 ----------


            // --- List ----------------------------                     
            elseif(preg_match("/^\* /", $currentChunk["content"])){



                if(!$beginList){
                    $output .= "<ul>";
                    $listItemIndentLevel = 0;
                    $beginList = true;
                    $isOrderdList = false;
                }

                $output .= "<li>";


                $output .= static::DecodeSpanElements(substr($currentChunk["content"], 1), $context);
                
                $beginListItem = true;
            } // End List --------------------

            // --- Tree ------------------------------                    
            elseif(preg_match("/^\+ /", $currentChunk["content"])){

                if(!$beginList){
                    $output .= "<ul class='tree'>";
                    $listItemIndentLevel = 0;
                    $beginList = true;
                    $isOrderdList = false;
                }

                
                $output .= "<li>";


                $output .= static::DecodeSpanElements(substr($currentChunk["content"], 1), $context);
                
                $beginListItem = true;
            } // End Tree -----------------------

            // --- 番号付きList ----------------------
            elseif(preg_match("/^([0-9]+.)* /", $currentChunk["content"])){
                if(!$beginList){
                    $output .= "<ol>";
                    $listItemIndentLevel = 0;
                    $beginList = true;
                    $isOrderdList = true;
                }

                
                $output .= "<li>";


                $output .= static::DecodeSpanElements(substr($currentChunk["content"], strpos($currentChunk["content"], " ")), $context);
                
                $beginListItem = true;
            
            } // End 番号付きList -----------

            // --- Figure Image -------------------------
            elseif(preg_match("/^!\[(.*)?\]\((.*)?\)/", $currentChunk["content"], $ret)){

                $output .= "<figure><img src='" . $ret[2] . "' alt='". 
                    $ret[1] ."'/><figcaption>" . 
                    $ret[1] . "</figcaption></figure>";

                
            }
            // End Figure Image -------------------

            // --- 参考文献リスト ------------------------------
            elseif(preg_match("/^\[(.*?)\]: (.*)/", $currentChunk["content"], $ret)){
                $context->SetReference($ret[1], static::DecodeSpanElements($ret[2], $context));
                $beginReferenceList = true;
                //\Debug::Log($ret[0]);
            }
            // end 参考文献リスト --------------

            // --- Table ----------------------------------
            elseif(static::CheckTableLine($currentChunk["content"], $ret)){
                


                if(!$beginTable){
                    $output .= "<table>";

                    if($ret["isCaption"]){
                        $output .= "<caption>" . static::DecodeSpanElements($ret["caption"], $context) . "</caption>";
                    }

                    $output .= "<thead>";
                    $beginTable = true;
                }

                if($ret["isHeadingAndBodySeparator"]){
                    $output .= "</thead>";
                    $output .= "<tbody>";

                    $beginTableBody = true;
                }


                if($ret["isTableRow"]){
                    $beginTableRow = true;

                    $output .= "<tr>";

                    $cols = count($ret["tableRowContents"]);
                    for($col = 0; $col < $cols; $col++){
                        if( ($beginTableBody && $col < $ret["columnHeadingCount"]) || !$beginTableBody){
                            $output .= "<th>";
                        }
                        else {
                            $output .= "<td>";
                        }


                        $output .= static::DecodeSpanElements($ret["tableRowContents"][$col], $context);

                        if( ($beginTableBody && $col < $ret["columnHeadingCount"])  || !$beginTableBody){
                            $output .= "</th>";
                        }
                        else {
                            $output .= "</td>";
                        }
                    }

                }

            } // End Table ----------

            else {
                if(!$beginParagraph){
                    

                    $output .= "<p>";

                    $output .= static::DecodeSpanElements($currentChunk["content"], $context);
                    $beginParagraph = true;


                }
                else{
                    $output .= static::DecodeSpanElements($currentChunk["content"], $context);
                }
            }


    
        } // End 各チャンクごとに対して ----


        // すべてのチャンクの処理を終えた場合

        while($indentLevelPrevious >= 0){
            $output .= "</div>";
            $indentLevelPrevious--;
        }
        //Debug::Log($output);



        return $output;

    }


    private static function CheckBoxStartOrEndLine($boxIndentStack, $context, &$ret){
        $ret = ["isStartOfBox" => false, "isEndOfBox" => false, "title" => "", "type" => ""];

        $line = $context->CurrentChunk()["content"];
        $nextLine = "";
        if($context->NextLineChunk() !== null && $context->CurrentChunk()["indentLevel"] == $context->NextLineChunk()["indentLevel"]){
            $nextLine = $context->NextLineChunk()["content"];
            //echo $nextLine . "<br>";
        }

        $latestBoxIndent = -1;
        if(count($boxIndentStack) >= 1){
            $latestBoxIndent = $boxIndentStack[count($boxIndentStack) - 1];
            //echo $latestBoxIndent;
        }

        $matches = [];
        if(preg_match("/^\[(.*)\]/", $line, $matches) && preg_match("/^====*$/", $nextLine)){
            $ret["isStartOfBox"] = true;
            $blocks = explode(":", $matches[1]);
            $blocks[0] = trim($blocks[0]);
            if(count($blocks) == 2){
                $blocks[1] = trim($blocks[1]);
                $ret['type'] = strtolower($blocks[1]);
            }
            else{
                $ret['type'] = "note";
            }

            if($blocks[0] == ''){
                $ret['title'] = strtoupper($ret['type']);
            }
            else{
                $ret["title"] = $blocks[0];
            }
            
            //echo "hogehoge";

            return true;
        }
    
        if($context->CurrentChunk()["indentLevel"] == $latestBoxIndent && preg_match("/^====*$/", $line)){
            $ret["isEndOfBox"] = true;

            return true;
        }
            
        

        return false;
    }

    private static function CheckHeadingLine($context, &$ret){
        
        $ret = ["heading" => "", "nextLineIsHorizontalLine" => false];

        $headHasHash = false;

        $line = $context->CurrentChunk()["content"];
        $nextLine = "";
        if($context->NextLineChunk() !== null && $context->CurrentChunk()["indentLevel"] == $context->NextLineChunk()["indentLevel"]){
            $nextLine = $context->NextLineChunk()["content"];
            //echo $nextLine;
        }


        if(preg_match("/^\# /", $line)){
            $headHasHash = true;
        }
        
        if(preg_match("/^----*$/", $nextLine)
                || preg_match("/^====*$/", $nextLine)
                || preg_match("/^____*$/", $nextLine)){
            
            $ret["nextLineIsHorizontalLine"] = true;
            
        }

        if($headHasHash || $ret["nextLineIsHorizontalLine"]){
            if($headHasHash){
                $ret["heading"] = substr($line, 1);
            }
            else{
                $ret["heading"] = $line;
            }


            return true;
        }



        return false;
    }



    private static function CheckTableLine($line, &$ret){
        $ret = ["tableRowContents" => [], "caption" => "", "isCaption" => false, "columnHeadingCount" => 0,
                "isHeadingAndBodySeparator" => false, "isTableRow" => false];
        
        //$line = trim($line);

        // 空文字のとき
        if($line == ""){
            return false;
        }

        $blocks = explode("|", $line);
        $blocksCount = count($blocks);

        
        // 行頭が|で始まっているか
        if($blocksCount > 0 && $blocks[0] != ""){
            // 先頭が空文字でない. 行頭が|で始まっていない.
            return false;
        }

        $matches = [];
        // captionの認識
        if($blocksCount == 2 && preg_match("/\[(.*)?\]/", $blocks[1], $matches)){
            $ret["caption"] = $matches[1];
            $ret["isCaption"] = true;
            return true;
        }


        for($i = 0; $i < $blocksCount; $i++){

            // body開始トークンの認識
            if(preg_match("/^----*$/", $blocks[$i])){
                // |と|の間に-が三個以上ある場合
                $ret["isHeadingAndBodySeparator"] = true;
                return true;
            }

            


            // 行末が|で終わっているか
            if($i == $blocksCount - 1 && $blocks[$i] != ""){

                // 行末が|で終わっていない
                return false;
            }

            
            // 列ヘッド終了の認識
            if(0 < $i && $i < $blocksCount - 1 && $blocks[$i] == ""){
               
                // ||が連続である場合
                $ret["columnHeadingCount"] = $i - 1;

            }


            if($blocks[$i] != ""){
                $ret["tableRowContents"][] = $blocks[$i];
            }

        }

        $ret["isTableRow"] = true;
        //echo var_dump($blocks);
        return true;

    }





















    private static $spanElementPatternTable = [
        ["/\[(.*?)\]\((.*?)\)/", "<a href='{1}'>{0}</a>", null],
        ["/\*\*(.*?)\*\*/", "<strong>{0}</strong>", null],
        ["/\/\/(.*?)\/\//", "<em>{0}</em>", null],
        ["/__(.*?)__/", "<mark>{0}</mark>", null],
        ["/~~(.*?)~~/", "<del>{0}</del>", null],
        ["/\\\\\[(.*?)\]/", null, "DecodeReferenceElementCallbackFunction"]
    ];

    private static function DecodeSpanElements($text, $context){

        $spanElementPatternTableCount = count(static::$spanElementPatternTable);

        // --- マッチ情報の初期化 ------
        $patternMatchInfos = array();


        for($i = 0; $i < $spanElementPatternTableCount; $i++){
            
            $patternMatchInfos[] = ["matches" => array(), "iteratorIndex" => 0, "matchedCount" => 0];

        }


        // end マッチ情報の初期化 ----------


        // パターンのマッチ
        for($i = 0; $i < $spanElementPatternTableCount; $i++){
            preg_match_all(static::$spanElementPatternTable[$i][0], $text, $patternMatchInfos[$i]["matches"], PREG_OFFSET_CAPTURE);
            $patternMatchInfos[$i]["matchedCount"] = count($patternMatchInfos[$i]["matches"][0]);
        }

        //var_dump($patternMatchInfos);

        $currentPosition = 0;
        $output = "";


        for(;;){

            // マッチしたパターンのうちパターン始まり位置が若いのを選ぶ
            $focusedPatternIndex = -1;
            for($i = 0; $i < $spanElementPatternTableCount; $i++){


                if($patternMatchInfos[$i]["matchedCount"] <= 0 ||
                   $patternMatchInfos[$i]["iteratorIndex"] >= $patternMatchInfos[$i]["matchedCount"]){
                    continue;
                }

                if($focusedPatternIndex < 0){
                    $focusedPatternIndex = $i;
                    continue;
                }

                
                if($patternMatchInfos[$i]["matches"][0][ $patternMatchInfos[$i]["iteratorIndex"] ][1] < 
                   $patternMatchInfos[$focusedPatternIndex]["matches"][0][ $patternMatchInfos[$focusedPatternIndex]["iteratorIndex"] ][1]){
                       $focusedPatternIndex = $i;
                }
            }

            if($focusedPatternIndex < 0){
                break;
            }



            $focusedPatternIteratorIndex =  $patternMatchInfos[$focusedPatternIndex]["iteratorIndex"];
            $focusedPatternStartPosition = $patternMatchInfos[$focusedPatternIndex]["matches"][0][$focusedPatternIteratorIndex ][1];

            // パターン開始位置が現在の位置よりも前のとき
            // 直前のパターン内にパターン文字が含まれている可能性が高いので, currentPositionから再びパターンを検索する.
            //  例:
            //   [abc](**abc) **strong**
            //
            //   これは, 何も対策しないと次のようになる.
            //   <a href='**abc'>abc</a> **strong**
            //
            if($focusedPatternStartPosition < $currentPosition){
                preg_match_all(static::$spanElementPatternTable[$focusedPatternIndex][0], $text, $patternMatchInfos[$focusedPatternIndex]["matches"], PREG_OFFSET_CAPTURE, $currentPosition);
                $patternMatchInfos[$focusedPatternIndex]["matchedCount"] = count($patternMatchInfos[$focusedPatternIndex]["matches"][0]);
                $patternMatchInfos[$focusedPatternIndex]["iteratorIndex"] = 0;
                continue;
            }

            $focusedPatternString = $patternMatchInfos[$focusedPatternIndex]["matches"][0][ $focusedPatternIteratorIndex ][0];

            //echo $focusedPatternStartPosition;

            // パターン前の文字列を出力
            $output .= static::EscapeSpecialCharacters(substr($text, $currentPosition, $focusedPatternStartPosition - $currentPosition));

            $spanString = "";

            if(static::$spanElementPatternTable[$focusedPatternIndex][1] != null){
                $spanString = static::$spanElementPatternTable[$focusedPatternIndex][1];
                $capturedCount = count($patternMatchInfos[$focusedPatternIndex]["matches"]) - 1;
                for($i = 0; $i < $capturedCount; $i++){
                    $spanString = str_replace("{" . ($i) . "}", $patternMatchInfos[$focusedPatternIndex]["matches"][$i+1][$focusedPatternIteratorIndex][0], $spanString);

                }
            }

            
            if(static::$spanElementPatternTable[$focusedPatternIndex][2] != null){
                $matchCount = count($patternMatchInfos[$focusedPatternIndex]["matches"]);
                $matches = [];

                for($i = 0; $i < $matchCount; $i++){
                    $matches[] = $patternMatchInfos[$focusedPatternIndex]["matches"][$i][$focusedPatternIteratorIndex];
                    
                }
                $spanString .= call_user_func(["OutlineText\Parser", "DecodeReferenceElementCallbackFunction"],$matches, $context);
            
            }



            $output .= $spanString;

            $currentPosition = $focusedPatternStartPosition + strlen($focusedPatternString);

            $patternMatchInfos[$focusedPatternIndex]["iteratorIndex"]++;
            //break;
        }


        
        $output .= static::EscapeSpecialCharacters(substr($text, $currentPosition));

        return $output;

    }

    private static function DecodeReferenceElementCallbackFunction($matches, $context){
        //\var_dump($matches);
        //\Debug::Log("as");
        $key = $matches[1][0];
        $index = $context->AddReference($key);

        return "<sup class='reference'><a href='#ref-" . $key . "'>[" . $index . "]</a></sup>";
        //\Debug::Log($key);
    }


    private static function EscapeSpecialCharacters($text){

        $blocks = preg_split(static::$specialCharacterEscapeExclusionPattern, $text,-1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        //var_dump($blocks);

        $blocksCount = count($blocks);
        
        for($i = 0; $i < $blocksCount; $i++){
            if(!preg_match(static::$specialCharacterEscapeExclusionPattern, $blocks[$i])){
                        
                $blocks[$i] = static::EscapeSpecialCharactersForce($blocks[$i]);
            }
        }

        


        return implode($blocks);

    }






    private static function EscapeSpecialCharactersForce($text){
           
        $text = str_replace("&", "&amp;", $text);
        $text = str_replace("<", "&lt;", $text);
        $text = str_replace(">", "&gt;", $text);

        return $text;

    }












    // 
    // chunkについて:
    //  デコード処理単位である.
    //  まず, OutlineTextのデコード(タグのエスケープは除く)対象とそうでないものにチャンク分けは行われる.
    //  デコード対象とならないものは, インラインコードの中, コードブロックの中, タグブロックの中である.
    //  また, 行替えごとにチャンクは分けられる.
    //
    // chunkの追加のタイミング
    //  
    //  * tagBlockから抜けたとき
    //  * tagBlockに入ったとき
    //  * tagBlock内ではなく and 空白行のとき
    //  * CodeBlockから出るとき
    //  * インラインコードに入る or 出るとき
    //  * tagBlock内ではなく and 行が終わるとき
    //
    //  以下がその例. `(<)`でチャンクの追加が行われる
    //  
    /*
            Hello!!                         (<)
                Can you see it?             (<)
                                            (<)
            * item                          (<)
            * item                          (<)
            * item                          (<)
                                            (<)
            Here is (<)'inline code'(<).    (<)
                                            (<)
            ```cpp

            printf("Hello world");

            ```                             (<)
                                            (<)
            <p>(<)
                in tag block
            </p>(<)
                                            (<)
            AEIUEOAO!!                      (<)





    */
    //
    private static function SplitToChunk($plainText){
        
        $chunkList = array();
        $chunk = static::CreateChunk();




        $lines = explode("\n", $plainText);
        $lineCount = count($lines);

        
        // --- 複数行にまたがって存在する情報 -------------
        $startSpaceCount = 0;
        $tagBlockLevel = 0;
        $tagBlockLevelPrevious = 0;

        $isStartWriting = false;

        $chunkIndex = 0;
        $lineStartChunkIndex = 0;

        $isInInlineCode = false;
        $isInCodeBlock = false;
        $codeBlockIndentLevel = 0;

        $isInComment = false;

        // End 複数行にまたがって存在する情報 ----

        for($i = 0; $i < $lineCount; $i++){
            
            // --- コメントアウト処理 -------------
            if($isInComment){
                // コメントから出る
                if(preg_match("/^ *" . static::$commentEndToken . "/", $lines[$i], $matches) === 1){
                    $isInComment = false;
                    
                }
                continue;
            }
            else{
                // コメントに入る
                if(!$isInCodeBlock && $tagBlockLevel <= 0 &&
                   preg_match("/^ *" . static::$commentStartToken . "/", $lines[$i], $matches) === 1){
                    

                    if(!preg_match("/" . static::$commentEndToken . " *$/", $lines[$i], $matches)){
                        $isInComment = true;
                    }



                    continue;
                }
            }
            // end コメントアウト処理 -------------

            // 書き込みの始まりを確認
            // 最初の空白文字の計算
            if(!$isStartWriting){
                $wordCount = strlen($lines[$i]);
                
                for($startSpaceCount = 0; $startSpaceCount < $wordCount; $startSpaceCount++){
                    if($lines[$i][$startSpaceCount] != ' '){
                        break;
                    }
                }

                if($startSpaceCount != $wordCount){
                    $isStartWriting = true;
                    //echo $lines[$i] . "<br>";
                }
                
            }

            // まだ文章が始まっていないとき
            if(!$isStartWriting){
                continue;
            }


            
            // --- indentLevelの計算 -----------------------------
            $wordCount = strlen($lines[$i]);
            $spaceCount = 0;
            for($spaceCount = 0; $spaceCount < $wordCount; $spaceCount++){
                if($lines[$i][$spaceCount] != ' '){
                    break;
                }
            }

            $isEmpty = false;
            // すべて, Spaceのとき
            if($spaceCount == $wordCount){
                $isEmpty = true;
                //echo "em";
            }

            $indentLevel = ($spaceCount - $startSpaceCount) / static::$indentSpace;
            //echo $startSpaceCount;

            // End indentLevelの計算 ------------------------


            // 現在コードブロック内のとき
            if($isInCodeBlock){

                // コードブロックから出る
                if($codeBlockIndentLevel == $indentLevel && preg_match("/^ *```(.*)/", $lines[$i], $matches) === 1){
                    $isInCodeBlock = false;


                    $chunkIndex++;
                    $chunkList[] = $chunk;

                    $chunk = static::CreateChunk();

                    
                    continue;
                }

                // コードブロック内の処理
                else{

                    $chunk["content"] .= $lines[$i] . "\n";
                    

                    continue;
                }

            }

            // 現在コードブロックに入っていないとき
            else{

                // コードブロック内に入る
                if($tagBlockLevel <= 0 && preg_match("/ *```(.*)/", $lines[$i], $matches) === 1){
                    $isInCodeBlock = true;
                    $codeBlockIndentLevel = $indentLevel;
                
                    $chunk["indentLevel"] = $indentLevel;
                    $chunk["spaceCount"] = $spaceCount;
                    $chunk["isCodeBlock"] = true;
                    $chunk["codeBlockAttribute"] = $matches[1];

                    
                    continue;
                }
            }
            
            
            // 空白行のとき
            if($isEmpty){
                if($tagBlockLevel > 0){
                    $chunk["content"] .= "\n";
                }

                // タグブロック内ではない
                else{
                    $chunk["nextLineChunkIndex"] = $chunkIndex + 1;
                    $chunk["isEmptyLine"] = true;

                    $chunkList[] = $chunk;


                    $chunkIndex++;
                    $chunk = static::CreateChunk();

                    
                    $lineStartChunkIndex = $chunkIndex;

                }

                continue;
            }

            if($tagBlockLevel <= 0){
                $chunk["indentLevel"] = $indentLevel;
                $chunk["spaceCount"] = $spaceCount;
            }

            
            $blocks = preg_split(static::$blockSeparators, $lines[$i],-1,PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            //var_dump($blocks);
            $blockCount = count($blocks);


            $beginInlineCode = false;
            
            // --- ブロックごとの処理 ----
            for($j = 0; $j < $blockCount; $j++){

                //echo $blocks[$j] . "\n";


                if($beginInlineCode){

                    // インラインコードから抜ける
                    if($blocks[$j] == "`"){
                        $beginInlineCode = false;


                        $chunkIndex++;
                        $chunkList[] = $chunk;
                        //var_dump($chunk);
                        
                        $chunk = static::CreateChunk();




                        continue;
                    }

                    // インラインコード内
                    else{
                        $chunk["content"] .= $blocks[$j];
                        //echo $blocks[$j];
                        continue;
                    }

                }
                else{

                    // インラインコードに入る
                    if($tagBlockLevel <= 0 && $blocks[$j] == "`"){
                        $chunkIndex++;

                        $chunkList[] = $chunk;
                        $chunk = static::CreateChunk();


                        $beginInlineCode = true;

                        $chunk["isInlineCode"] = true;

                        //echo "34y";

                        continue;
                    }
                }
                
                


                if(preg_match(static::$patternTagBlockStartTag, $blocks[$j]) === 1){
                
                    $tagBlockLevel++;
                }

                if(preg_match(static::$patternTagBlockEndTag, $blocks[$j]) === 1){
                
                    $tagBlockLevel--;
                }


                if($tagBlockLevel != $tagBlockLevelPrevious){

                    // タグブロック内に入った
                    if($tagBlockLevel > 0 && $tagBlockLevelPrevious <= 0){
                        $chunkIndex++;

                        $chunkList[] = $chunk;
                        $chunk = static::CreateChunk();
                        $chunk["isTagElement"] = true;
                        
                        $chunk["content"] .= $blocks[$j];
                    }

                    //　タグブロックから出た
                    elseif($tagBlockLevel <= 0 && $tagBlockLevelPrevious > 0){
                        $chunkIndex++;

                        $chunk["content"] .= $blocks[$j];

                        $chunkList[] = $chunk;
                        $chunk = static::CreateChunk();
                        $chunk["isTagElement"] = false;

                    }

                    // タグブロック内での変化
                    else{
                        
                        $chunk["content"] .= $blocks[$j];
                    }

                    $tagBlockLevelPrevious = $tagBlockLevel;
                }
                
                // タグブロックの変化がない 
                else{
                    if($tagBlockLevel <= 0){
                        if($j == 0){
                            $blocks[$j] = substr($blocks[$j], $spaceCount);
                        }

                        if($j == $blockCount - 1){
                            $blocks[$j] = static::RemoveTailSpaces($blocks[$j]);
                        }
                        $chunk["content"] .= $blocks[$j];
                    }
                    else{
    
                        $chunk["content"] .= $blocks[$j];
                    }
                }


                

            } // End ブロックのごとの処理 ---

            // 行の終わり & タグブロック内ではないとき
            if($tagBlockLevel <= 0){
                $chunkIndex++;
                $chunkList[] = $chunk;

                for($j = $lineStartChunkIndex; $j < $chunkIndex; $j++){
                    $chunkList[$j]["nextLineChunkIndex"] = $chunkIndex;
                }
                $lineStartChunkIndex = $chunkIndex;


                // $chunkIndex++;
                
                // $chunkList[] = $chunk;
                $chunk = static::CreateChunk();

            }

            // 行の終わり & タグブロック内のとき
            else{
                $chunk["content"] .= "\n";
            }
        } // End 各行ごとの処理 ---


        $chunkList[] = $chunk;


        return $chunkList;
    }


    private static function CreateChunk(){
        return ["indentLevel" => -1, "spaceCount" => 0, "isTagElement" => false, "isCodeBlock" => false,
         "content" => "", "nextLineChunkIndex"=>-1, "codeBlockAttribute" => "", "isInlineCode" => false, "isEmptyLine" => false];
    }


    private static function RemoveHeadSpaces($line){
        $wordCount = strlen($line);
        $spaceCount = 0;

        for($i = 0; $i < $wordCount; $i++){
            if($line[$i] != ' '){
                break;
            }
            $spaceCount++;
        }

        return substr($line, $spaceCount);
    }

    private static function RemoveTailSpaces($line){
        $wordCount = strlen($line);
        $spaceCount = 0;

        for($i = $wordCount - 1; $i >= 0; $i--){
            if($line[$i] != ' '){
                break;
            }
            $spaceCount++;
        }

        return substr($line, 0, $wordCount - $spaceCount);
    }

    

} // End class Parser





?>