<?php

/*
 *
 * HTMLElements
 * ExceptElements
 * InlineCode
 * CodeBlock
 * SpanElements
 * 
 * インデントの数が要素の階層になるようにしよう
 *
 */

namespace OutlineText;

require_once dirname(__FILE__) . "/Debug.php";


class BlockElementParser {
    public static function OnReset() {}

    public static function OnEmptyLine($context, &$output) {$output = ''; return false;}

    public static function OnBlockBreak($context, &$output) {$output = ''; return false;}

    public static function OnNewLine($context, &$output) {$output = ''; return false;}

    public static function OnPreBeginLine($context, &$output) {$output = ''; return false;}

    public static function OnBeginLine($context, &$output) {$output = ''; return false;}

    public static function OnIndent($context, &$output) {$output = ''; return false;}

    public static function OnOutdent($context, &$output) {$output = ''; return false;}

    public static function OnEndOfDocument($context, &$output) {$output = ''; return false;}
}


class FigureElementParser extends BlockElementParser {
    public static function OnBeginLine($context, &$output) {
        $output = '';

        $matches = [];
        if (preg_match("/^!\[(.*)?\]\((.*)?\)/", $context->morphSequence->currentLine, $matches)) {
            $src =  $context->ReplacePathMacros($matches[2]);
            $caption = Parser::DecodeInlineElements($matches[1] , $context);
            $title = strip_tags($caption);
            $output .= '<figure><a href="' . $src . '"><img src="' . $src . '" alt="' . $title
                . '"/></a><figcaption><span>' . $caption . '</span></figcaption></figure>';
            
            $context->morphSequence->JumpToEndOfLineMorph();
            return true;
        }

        return false;
    }
}


class HorizontalLineElementParser extends BlockElementParser {
    public static function OnBeginLine($context, &$output) {
        $output = '';

        if (preg_match("/^----*$/", $context->morphSequence->currentMorph["content"])) {
            $out = '';
            Parser::DoBlockBreak($context, $out);
            $output .= $out;
            $output .= '<hr>';
            return true;
        }

        return false;
    }
}


class ReferenceListParser extends BlockElementParser {
    private static $currentMatches = false;
    private static $currentGroupAndKey = ['group' => false, 'key' => false];
    private static $peekedGroupAndKey = false;

    public static function OnReset() {
        self::$currentMatches = false;
        self::$currentGroupAndKey = ['group' => false, 'key' => false];
    }

    public static function OnEndOfDocument($context, &$output) {
        $output = '';
        if(self::$currentGroupAndKey['group'] !== false) {
            // 前のgroupがあるとき
            $output = self::CreateReferenceList($context);
            self::$currentGroupAndKey['group'] = false;
        }
        return false;
    }

    public static function OnBlockBreak($context, &$output) {
        self::OnEndOfDocument($context, $output);
        return false;
    }

    public static function OnNewLine($context, &$output) {
        $output = '';
        self::$currentMatches = false;

        if (preg_match("/^\[(.*?)\]: (.*)/", $context->morphSequence->currentLine, $matches)) {
            // OnBeginLine()で実行
            self::$currentMatches = $matches;
            $groupAndKey = self::ParseGroupAndKey($matches[1]);

            if(
                self::$currentGroupAndKey['group'] !== false && 
                self::$currentGroupAndKey['group'] !== $groupAndKey['group']
            ) {
                // 前のgroupがあり, 異なるgroupのとき, 前のgroupを作成
                $output = self::CreateReferenceList($context);
            }
            self::$peekedGroupAndKey = $groupAndKey;
        }
        else {
            if(self::$currentGroupAndKey['group'] !== false) {
                // 前のgroupがあるとき
                $output = self::CreateReferenceList($context);
                self::$currentGroupAndKey['group'] = false;
            }
        }
        return false;
    }

    public static function OnBeginLine($context, &$output) {
        $output = '';
        if(self::$currentMatches !== false) {
            self::$currentGroupAndKey = self::$peekedGroupAndKey;
            $context->SetReference(
                self::$currentGroupAndKey['group'],
                self::$currentGroupAndKey['key'],
                Parser::DecodeInlineElements(self::$currentMatches[2], $context)
            );
            $context->morphSequence->JumpToEndOfLineMorph();
            return true;
        }
        return false;
    }

    public static function ParseGroupAndKey($groupAndKey) {
        $key = ''; $group = 'cite';
        $blocks = explode(".", $groupAndKey, 2);
        
        if(count($blocks) == 1){
            $key = trim($blocks[0]);
        }
        else{
            $group = trim($blocks[0]);
            $key = trim($blocks[1]);
        }
        return ['group' => $group, 'key' => $key];
    }

    /**
     * 現在のgroupで文献リストを作成する.
     */
    public static function CreateReferenceList($context) {
        $group = static::$currentGroupAndKey['group'];
        $referenceList = $context->ReferenceList($group);
        $referenceCount = count($referenceList);
        $output = "<ol class='references'>";
        for ($index = 1; $index <= $referenceCount; $index++) {
            $output .= '<li id="' . $group . '-note-' . $referenceList[$index]["key"] . '">';

            if($referenceList[$index]["totalCitation"] == 1) {
                $output .= '<b><a href="#' . $group . '-ref-' .  $referenceList[$index]["key"] . '-0">^</a></b> ';
            }
            else {
                $output .= '^ ';
                for($i = 0; $i < $referenceList[$index]["totalCitation"]; $i++){
                    $output .= '<a href="#' . $group . '-ref-' . $referenceList[$index]["key"] . '-' . $i . '"><sup><i><b>' . chr(97 + $i) . '</b></i></sup></a> ';
                }
            }
            $output .= '<span class="reference-text">' . $referenceList[$index]["content"] . '</span></li>';
        }
        $output .= '</ol>';
        return $output;
    }
}


class BlockquoteElementParser extends BlockElementParser{
    private static $indentStack = [];

    public static function OnReset(){
        static::$indentStack = [];
    }

    public static function OnBeginLine($context, &$output){
        $output = '';

        $line = $context->morphSequence->currentLine;

        if(preg_match("/^>>>>*$/", $line)){
            $latestIndent = -1;
            if(count(static::$indentStack) > 0){
                $latestIndent = static::$indentStack[count(static::$indentStack) - 1];
            }
            
            if($context->indentLevel > $latestIndent){
                // 始める
                $output .= '<blockquote>';
                static::$indentStack[] = $context->indentLevel;
                return true;
            }
            elseif($context->indentLevel == $latestIndent){
                // 閉じる
                $output .= '</blockquote>';
                array_pop(static::$indentStack);
                return true;
            }
            else{
                // 文法に誤りがある
            }
        }
        
        return false;
    }
}


class BoxElementParser extends BlockElementParser {
    private static $boxIndentStack = [];

    private static $isStartOfBox = false;
    private static $isEndOfBox = false;
    private static $title = '';
    private static $type = '';
    private static $isMatched = false;

    public static function OnReset() {
        static::$boxIndentStack = [];
    }

    public static function OnPreBeginLine($context, &$output) {
        static::$isMatched = static::IsStartOrEndLine($context);

        if (static::$isMatched) {
            $out = '';
            Parser::DoBlockBreak($context, $out);
            $output .= $out;
        }

        return false;
    }

    public static function OnBeginLine($context, &$output) {
        $output = '';

        if (static::$isMatched) {

            if (static::$isStartOfBox) {
                static::$boxIndentStack[] = $context->indentLevel;

                $output .= "<div class='box-" . static::$type . "'><span class='box-title'>" .
                Parser::DecodeInlineElements(static::$title, $context) . '</span>';

                $context->skipNextLineMorph = true;

            }

            if (static::$isEndOfBox) {
                array_pop(static::$boxIndentStack);

                $output .= '</div>';
            }

            $context->morphSequence->JumpToEndOfLineMorph();

            return true;
        }
        return false;
    }

    private static function IsStartOrEndLine($context) {
        static::$isStartOfBox = false;
        static::$isEndOfBox = false;
        static::$title = '';
        static::$type = '';

        $currentMorph = $context->morphSequence->currentMorph;
        $nextLineMorph = $context->morphSequence->nextLineMorph;
        $line = $context->morphSequence->currentLine;
        $nextLine = '';
        if ($nextLineMorph !== null && $currentMorph["indentLevel"] == $nextLineMorph["indentLevel"]) {
            $nextLine = $nextLineMorph["content"];
        }

        $latestBoxIndent = -1;
        if (count(static::$boxIndentStack) >= 1) {
            $latestBoxIndent = static::$boxIndentStack[count(static::$boxIndentStack) - 1];
        }

        $matches = [];
        if (preg_match("/^\[(.*)\]/", $line, $matches) && preg_match("/^====*$/", $nextLine)) {
            static::$isStartOfBox = true;
            $blocks = explode("::", $matches[1]);
            $blocks[0] = trim($blocks[0]);
            if (count($blocks) == 2) {
                $blocks[1] = trim($blocks[1]);
                static::$type = strtolower($blocks[1]);
            } else {
                static::$type = 'note';
            }

            if ($blocks[0] == '') {
                static::$title = strtoupper(static::$type);
            } else {
                static::$title = $blocks[0];
            }

            return true;
        }

        if ($currentMorph["indentLevel"] == $latestBoxIndent && preg_match("/^====*$/", $line)) {
            static::$isEndOfBox = true;

            return true;
        }

        return false;
    }
}


class ParagraphElementParser extends BlockElementParser {
    private static $isBegin = false;

    public static function OnReset() {
        static::$isBegin = false;
    }

    public static function OnBeginLine($context, &$output) {
        $output = '';

        $line = $context->morphSequence->currentLine;

        if (!static::$isBegin) {
            $output = '<p>' . Parser::DecodeInlineElements($line, $context);
            static::$isBegin = true;

        } else {
            $output = Parser::DecodeInlineElements($line, $context);
        }

        if (static::$isBegin) {
            // 行末にバックスラッシュが二つあるとき
            if (preg_match("/\\\\\\\\$/", $line)) {
                $output = substr($output, 0, -2) . '<br>';
            }
            else{
                // 行末が半角文字のとき, スペースを入れる.
                if(strlen(mb_substr($line, -1)) == 1){
                    $output .= ' ';
                }
            }
        }

        $context->morphSequence->JumpToEndOfLineMorph();

        return true;
    }

    public static function OnEmptyLine($context, &$output) {
        $output = '';

        if(!static::$isBegin) return false;

        $output .= '</p>';
        static::$isBegin = false;
    
        return false;
    }

    public static function OnBlockBreak($context, &$output) {
        self::OnEmptyLine($context, $output);
        return false;
    }
}


class SectionElementParser extends BlockElementParser {
    public static function OnIndent($context, &$output) {
        $output = '';

        // セクションに入る前にブロックの区切りをする
        $out = '';
        Parser::DoBlockBreak($context, $out);
        $output .= $out;

        $output .= "<div class='section'>";
        return false;
    }

    public static function OnOutdent($context, &$output) {
        $output = '';
        
        // セクションから抜ける前にブロックの区切りをする
        $out = '';
        Parser::DoBlockBreak($context, $out);
        $output .= $out;

        $output .= '</div>';
        return false;
    }
}


class DefinitionListElementParser extends BlockElementParser{
    /**
     * [{'indentLevel' => 0, 'prevElementIsItem' => false}]
     */
    private static $indentStack = [];
    private static $indentStackCount = 0;

    private static function GetLatestIndent(){
        $latestIndent = -1;
        if(static::$indentStackCount > 0){
            $latestIndent = static::$indentStack[static::$indentStackCount - 1];
        }
        return $latestIndent;
    }

    public static function OnReset(){
        static::$indentStack = [];
        static::$indentStackCount = 0;
    }
    
    public static function OnEndOfDocument($context, &$output){
        $output = '';
        if(static::GetLatestIndent() >= 0){
            // この時, 
            // スタックの要素数が 1 で
            // インデントレベルが 0 になっていないとおかしい
            
            array_pop(static::$indentStack);
            static::$indentStackCount--;
            
            $output .= '</dl>';

            if(static::GetLatestIndent() >= 0){
                // 文法ミス
            }
        }
        return false;
    }

    public static function OnPreBeginLine($context, &$output){
        $output = '';

        $line = $context->morphSequence->currentLine;
        
        if(preg_match("/(.*):$/", $line, $matches)){
            // OnBeginLine で実行
        }
        else{
            if(static::GetLatestIndent() == $context->indentLevel){
                $output .= '</dl>';
                array_pop(static::$indentStack);
                static::$indentStackCount--;
            }
        }
        return false;
    }

    public static function OnBeginLine($context, &$output){
        $output = '';

        $line = $context->morphSequence->currentLine;
        
        if(preg_match("/(.*):$/", $line, $matches)){
            
            if($context->indentLevel > static::GetLatestIndent()){
                // 新しくリストを始める

                $term = $matches[1];
                $output .= '<dl><dt>' . Parser::DecodeInlineElements($term, $context) . '</dt>';
                static::$indentStack[] = $context->indentLevel;
                static::$indentStackCount++;
                
                $context->morphSequence->JumpToEndOfLineMorph();
                return true;
            }
            elseif($context->indentLevel == static::GetLatestIndent()){
                // 続けてアイテム
                
                $term = $matches[1];
                $output .= '<dt>' . Parser::DecodeInlineElements($term, $context) . '</dt>';

                $context->morphSequence->JumpToEndOfLineMorph();
                return true;
            }
            else {
                // 文法がおかしい
            }
        }
        else{
            // OnPreBeginLine で実行
        }
        return false;
    }

    public static function OnIndent($context, &$output){
        $output = '';
        
        if($context->indentLevel == static::GetLatestIndent() + 1){
            // Term:
            //     Description
            // ->(dd)
            //  x(section)
            $output .= '<dd>';
            return true;
        }

        return false;
    }
    
    public static function OnOutdent($context, &$output){
        $output = '';
        
        if($context->indentLevel == static::GetLatestIndent()){
            $output .= '</dl>';
            array_pop(static::$indentStack);
            static::$indentStackCount--;
        }

        if($context->indentLevel == static::GetLatestIndent() + 1){
            // Term:
            //     Description
            // <-(/dd)
            //  x(/section)
            $out = '';
            Parser::DoBlockBreak($context, $out);
            $output .= $out;
            $output .= '</dd>';
            return true;
        }

        return false;
    }
}


class ListElementParser extends BlockElementParser {
    /**
     * [{'indentLevel' => 0, 'endTag' => '', 'startTag' => ''}]
     */
    private static $listStack = [];
    private static $listStackCount = 0;

    public static function OnReset() {
        static::$listStack = [];
        static::$listStackCount = 0;
    }

    public static function OnEndOfDocument($content, &$output){
        $output = '';

        if(($list = static::GetLatestList()) !== false){
            // この時, 
            // スタックの要素数が 1 で
            // インデントレベルが 0 になっていないとおかしい
            
            $list =array_pop(static::$listStack);
            static::$listStackCount--;

            $output .= '</li>' . $list['endTag'];

            if(($list = static::GetLatestList()) !== false){
                // 文法ミス
            }
        }
        return false;
    }


    public static function OnIndent($context, &$output) {
        $output = '';
        
        $currentMorph = $context->morphSequence->currentMorph;
        if(
            $context->indentLevel == $currentMorph['indentLevel'] && 
            (
                ($list = static::GetLatestList()) !== false &&
                $context->indentLevelPrevious == $list['indentLevel']
            ) &&
            static::MatchFirstItem($currentMorph['content'], $startTag, $endTag)
        ){
            // * item
            //     * item
            // ->(ul) 
            //  x(section)
            return true;
        }

        return false;
    }

    public static function OnOutdent($context, &$output) {
        $output = '';

        $currentMorph = $context->morphSequence->currentMorph;
        if(
            ($list = static::GetLatestList()) !== false &&
            $list['indentLevel'] == $context->indentLevel
        ){
            $list = array_pop(static::$listStack);
            static::$listStackCount--;

            $output .= '</li>' . $list['endTag'];

            if(static::$listStackCount > 0){
                return true;
            }
            return false;
        }
        
        return false;
    }

    public static function OnPreBeginLine($context, &$output){
        $output = '';

        $currentMorph = $context->morphSequence->currentMorph;

        if(static::$listStackCount <= 0){
            // OnBeginLine で実行
        }
        elseif(
            ($list = static::GetLatestList()) !== false &&
            $list['indentLevel'] == $currentMorph['indentLevel']
        ){
            if(
                preg_match("/^\* /", $currentMorph["content"]) ||
                preg_match("/^\+ /", $currentMorph["content"]) ||
                preg_match("/^([a-zA-Z0-9]+\.)+ /", $currentMorph["content"])
            ){
                // OnBeginLine で実行
            }
            else{
                // このレベルのリスト終了
                $list = array_pop(static::$listStack);
                static::$listStackCount--;

                $output .= '</li>' . $list['endTag'];
                return false;
            }
        }
        return false;
    }

    public static function OnBeginLine($context, &$output) {
        $output = '';

        $currentMorph = $context->morphSequence->currentMorph;

        if(static::$listStackCount <= 0){
            if(static::MatchFirstItem($currentMorph['content'], $startTag, $endTag)){
                // このレベルで新しいリスト
                static::$listStack[] = ['indentLevel' => $currentMorph['indentLevel'], 'startTag' => $startTag, 'endTag' => $endTag];
                static::$listStackCount++;

                $output .= $startTag . '<li>' 
                    . Parser::DecodeInlineElements(substr($currentMorph['content'],  strpos($currentMorph["content"], ' ') + 1), $context);
                
                return true;
            }
        }
        elseif(
            ($list = static::GetLatestList()) !== false &&
            $list['indentLevel'] == $currentMorph['indentLevel']
        ){
            if(
                preg_match("/^\* /", $currentMorph["content"]) ||
                preg_match("/^\+ /", $currentMorph["content"]) ||
                preg_match("/^([a-zA-Z0-9]+\.)+ /", $currentMorph["content"])
            ){
                // このレベルのリストアイテム
                $output .= '</li><li>'
                    . Parser::DecodeInlineElements(substr($currentMorph['content'],  strpos($currentMorph["content"], ' ') + 1), $context);

                return true;
            }
            else{
                // OnPreBeginLineで実行
            }
        }
        elseif(
            ($list = static::GetLatestList()) !== false && 
            $list['indentLevel'] < $currentMorph['indentLevel']
        ){
            if(static::MatchFirstItem($currentMorph['content'], $startTag, $endTag)){
                // このレベルで新しいリスト
                static::$listStack[] = ['indentLevel' => $currentMorph['indentLevel'], 'startTag' => $startTag, 'endTag' => $endTag];
                static::$listStackCount++;

                $output .= $startTag . '<li>' 
                    . Parser::DecodeInlineElements(substr($currentMorph['content'],  strpos($currentMorph["content"], ' ') + 1), $context);
                return true;
            }
        }
        return false;
    }
    
    private static function GetLatestList(){
        if(static::$listStackCount <= 0) return false;

        return static::$listStack[static::$listStackCount - 1];
    }

    private static function MatchFirstItem($str, &$startTag, &$endTag){
        $startTag = '';
        $endTag = '';

        $isMatch = false;
        if($isMatch = preg_match("/^\* /", $str)){
            $startTag = '<ul>';
            $endTag = '</ul>';
        }
        else if($isMatch = preg_match("/^\+ /", $str)){
            $startTag = "<ul class='tree'>";
            $endTag = '</ul>';
        }
        else if($isMatch = preg_match("/^1\. /", $str)){
            $startTag = '<ol type="1">';
            $endTag = '</ol>';
        }
        else if($isMatch = preg_match("/^([0-9]+\.)+1\. /", $str)){
            $startTag = '<ol class="scope-ordered">';
            $endTag = '</ol>';
        }
        else if($isMatch = preg_match("/^a\. /", $str)){
            $startTag = '<ol type="a">';
            $endTag = '</ol>';
        }
        else if($isMatch = preg_match("/^A\. /", $str)){
            $startTag = '<ol type="A">';
            $endTag = '</ol>';
        }
        else if($isMatch = preg_match("/^i\. /", $str)){
            $startTag = '<ol type="i">';
            $endTag = '</ol>';
            $offset = 3;
        }
        else if($isMatch = preg_match("/^I\. /", $str)){
            $startTag = '<ol type="I">';
            $endTagStack[] = '</ol>';
        }
        return $isMatch;
    }
}


class TableElementParser extends BlockElementParser {
    private static $isBegin = false;
    private static $columnHeadCount = 0;
    private static $isBeginBody = false;
    private static $isBeginRow = false;

    private static $tableRowContents = [];
    private static $caption = '';
    private static $isCaption = false;
    private static $columnHeadingCount = 0;
    private static $isHeadingAndBodySeparator = false;
    private static $isTableRow = false;

    public static function OnReset() {
        static::$isBegin = false;
        static::$columnHeadCount = 0;
        static::$isBeginBody = false;
        static::$isBeginRow = false;
    }

    public static function OnEmptyLine($context, &$output) {
        $output = '';

        if (static::$isBeginRow) {
            $output .= '</tr>';
            static::$isBeginRow = false;
        }

        if (static::$isBegin) {
            if (static::$isBeginBody) {
                $output .= '</tbody>';
            } else {
                $output .= '</thead>';
            }

            $output .= '</table>';
            static::$columnHeadCount = 0;
            static::$isBeginBody = false;
            static::$isBegin = false;
        }

        return false;
    }

    public static function OnBlockBreak($context, &$output) {
        self::OnEmptyLine($context, $output);
        return false;
    }

    public static function OnNewLine($context, &$output) {
        $output = '';

        if (static::$isBeginRow) {
            $output .= "</tr>";
            static::$isBeginRow = false;
        }

        return false;
    }

    public static function OnBeginLine($context, &$output) {
        $output = '';

        if (static::IsTableLine($context)) {

            if (!static::$isBegin) {
                $output .= '<table>';

                if (static::$isCaption) {
                    $output .= '<caption>' . Parser::DecodeInlineElements(static::$caption, $context) . '</caption>';
                }

                $output .= '<thead>';
                static::$isBegin = true;
            }

            if (static::$isHeadingAndBodySeparator) {
                $output .= '</thead><tbody>';

                static::$isBeginBody = true;
            }

            if (static::$isTableRow) {
                static::$isBeginRow = true;

                $output .= '<tr>';

                $colsCount = count(static::$tableRowContents);
                for ($col = 0; $col < $colsCount; $col++) {
                    if ((static::$isBeginBody && $col < static::$columnHeadingCount) || !static::$isBeginBody) {
                        $output .= '<th>';
                    } else {
                        $output .= '<td>';
                    }

                    $output .= Parser::DecodeInlineElements(static::$tableRowContents[$col], $context);

                    if ((static::$isBeginBody && $col < static::$columnHeadingCount) || !static::$isBeginBody) {
                        $output .= '</th>';
                    } else {
                        $output .= '</td>';
                    }
                }

            }

            $context->morphSequence->JumpToEndOfLineMorph();

            return true;
        }

        return false;
    }

    private static function IsTableLine($context) {
        static::$tableRowContents = [];
        static::$caption = '';
        static::$isCaption = false;
        static::$columnHeadingCount = 0;
        static::$isHeadingAndBodySeparator = false;
        static::$isTableRow = false;

        $line = $context->morphSequence->currentLine;

        // 空文字のとき
        if ($line == "") {
            return false;
        }

        $blocks = explode("|", $line);
        $blocksCount = count($blocks);

        // 行頭が|で始まっているか
        if ($blocksCount > 0 && $blocks[0] != "") {
            // 先頭が空文字でない. 行頭が|で始まっていない.
            return false;
        }

        $matches = [];
        // captionの認識
        if ($blocksCount == 2 && preg_match("/\[(.*)?\]/", $blocks[1], $matches)) {
            static::$caption = $matches[1];
            static::$isCaption = true;
            return true;
        }

        for ($i = 0; $i < $blocksCount; $i++) {

            // body開始トークンの認識
            if (preg_match("/^----*$/", $blocks[$i])) {

                // |と|の間に-が三個以上ある場合
                static::$isHeadingAndBodySeparator = true;

                return true;
            }

            // 行末が|で終わっているか
            if ($i == $blocksCount - 1 && $blocks[$i] != "") {

                // 行末が|で終わっていない
                return false;
            }

            // 列ヘッド終了の認識
            if (0 < $i && $i < $blocksCount - 1 && $blocks[$i] == "") {

                // ||が連続である場合
                static::$columnHeadingCount = $i - 1;
            }

            if ($blocks[$i] != '') {
                static::$tableRowContents[] = $blocks[$i];
            }

        }

        static::$isTableRow = true;

        return true;
    }
}


class HeadingElementParser extends BlockElementParser {
    private static $isBegin = false;
    private static $heading = '';
    private static $level;
    private static $nextLineIsHorizontalLine = false;

    public static function OnReset() {
        static::$isBegin = false;
    }

    public static function OnEmptyLine($context, &$output) {
        $output = '';

        if (static::$isBegin) {
            $output .= '</h' . static::$level . '>';
            static::$isBegin = false;
        }

        return false;
    }
    
    public static function OnBlockBreak($context, &$output) {
        self::OnEmptyLine($context, $output);
        return false;
    }

    public static function OnNewLine($context, &$output) {
        $output = '';

        if (static::$isBegin) {
            $output .= '</h' . static::$level . '>';
            static::$isBegin = false;
        }

        return false;
    }

    public static function OnBeginLine($context, &$output) {
        $output = '';

        if (static::IsHeadingLine($context)) {
            static::$level = $context->indentLevel + 2;
            $output .= '<h' . static::$level . '>';
            $output .= Parser::DecodeInlineElements(static::$heading, $context);

            if (static::$nextLineIsHorizontalLine) {
                $context->skipNextLineMorph = true;
            }

            static::$isBegin = true;
            return true;
        }

        return false;
    }

    private static function IsHeadingLine($context) {
        static::$heading = '';
        static::$nextLineIsHorizontalLine = false;

        $headingHasHash = false;
        $currentMorph = $context->morphSequence->currentMorph;
        $nextLineMorph = $context->morphSequence->nextLineMorph;
        $line = $currentMorph['content'];
        $nextLine = "";
        if ($nextLineMorph !== null && $currentMorph['indentLevel'] == $nextLineMorph['indentLevel']) {
            $nextLine = $nextLineMorph['content'];
        }

        if (preg_match("/^\# /", $line)) {
            $headingHasHash = true;
        }

        // if (preg_match("/^----*$/", $nextLine)
        //     || preg_match("/^====*$/", $nextLine)
        //     || preg_match("/^____*$/", $nextLine)) {
                
        if ( preg_match("/^____*$/", $nextLine)) {

            static::$nextLineIsHorizontalLine = true;
        }

        if ($headingHasHash || static::$nextLineIsHorizontalLine) {
            if ($headingHasHash) {
                static::$heading = substr($line, 2);
            } else {
                static::$heading = $line;
            }

            return true;
        }

        return false;
    }
}

/**
 * [
 *  'indentLevel' => -1, 
 *  'spaceCount' => 0, 
 *  'isTagElement' => false, 
 *  'isCodeBlock' => false,
 *  'content' => '', 
 *  'nextLineMorphIndex' => -1, 
 *  'codeBlockAttribute' => '', 
 *  'isInlineCode' => false, 
 *  'isEmptyLine' => false
 * ]
 */
function CreateMorph() {
    return [
        'indentLevel' => -1,
        'spaceCount' => 0,
        'isTagElement' => false,
        'isCodeBlock' => false,
        'content' => '',
        'nextLineMorphIndex' => -1,
        'codeBlockAttribute' => '', 
        'isInlineCode' => false, 
        'isEmptyLine' => false
    ];
}

class MorphSequence {
    public $morphs = [];

    public $morphCount = 0;
    public $currentMorph = null;
    public $nextMorph = null;
    public $currentLine = '';
    public $currentMorphIndex = -1;
    public $isEndOfMorph = false;
    public $nextLineMorph = null;
    public $nextLineMorphIndex = -1;
    
    public function SetSequence($morphsToSet) {
        $this->morphs = $morphsToSet;
        $this->morphCount = count($this->morphs);
        $this->currentMorphIndex = -1;
        $this->isEndOfMorph = false;
        $this->Iterate();
    }
    
    public function Iterate() {
        if ($this->isEndOfMorph) {
            return;
        }

        if ((++$this->currentMorphIndex) >= $this->morphCount) {
            $this->currentMorph = null;
            $this->isEndOfMorph = true;
            $this->nextLineMorph = null;
            $this->nextMorph = null;
            return;
        }

        $this->currentMorph = $this->morphs[$this->currentMorphIndex];

        $this->nextLineMorphIndex = -1;
        $this->nextLineMorph = null;
        if ($this->currentMorph["nextLineMorphIndex"] != -1) {
            $this->nextLineMorphIndex = $this->currentMorph["nextLineMorphIndex"];
            $this->nextLineMorph = $this->morphs[$this->nextLineMorphIndex];
        }

        if ($this->currentMorph['indentLevel'] != -1) {
            // 文頭
            $this->currentLine = '';

            $endOfLineIndex = $this->nextLineMorphIndex != -1 ? $this->nextLineMorphIndex - 1 : $this->morphCount - 1;

            for ($index = $this->currentMorphIndex; $index <= $endOfLineIndex; $index++) {
                $morph = $this->morphs[$index];

                if ($morph['isTagElement'] || $morph['isCodeBlock'] || $morph['isInlineCode']) {
                    $this->currentLine .= "\016{" . $index . "}\016";
                } else {
                    $this->currentLine .= $morph['content'];
                }
            }
        }

        $this->nextMorph = ($this->currentMorphIndex < $this->morphCount - 1) ?
            $this->morphs[$this->currentMorphIndex + 1] : null;
    }

    public function JumpToEndOfLineMorph() {
        $iterateCount = $this->nextLineMorphIndex === -1 ? 
            $this->morphCount - $this->currentMorphIndex : $this->nextLineMorphIndex - $this->currentMorphIndex;
        $iterateCount--;

        for ($cnt = 0; $cnt < $iterateCount; $cnt++) {
            $this->Iterate();
        }
    }
    
    public function JumpToNextLineMorph() {
        $this->JumpToEndOfLineMorph();
        $this->Iterate();
    }
}

class Context {
    public $morphSequence;

    public $indentLevel = 0;
    public $indentLevelPrevious = 0;

    public $skipNextLineMorph = false;
    public $pathMacros = [[],[]];

    /**
     * [
     *  "group-0" => [
     *      "key-a" => ["index" => 0, "content" => "AAA", "totalCitation" => 0] ,
     *      "key-b" => ["index" => 1, "content" => "BBB", "totalCitation" => 0] , 
     *  ],   
     *  "group-1" => [
     *      "key-1" => ["index" => 0, "content" => "111", "totalCitation" => 0] ,
     *      "key-2" => ["index" => 1, "content" => "222", "totalCitation" => 0] ,  
     *  ], ...
     * ] 
     */
    private $referenceMap = [];
    
    function __construct() {
        $this->morphSequence = new MorphSequence();
    }

    public function AddReference($group, $key) {
        if (!array_key_exists($group, $this->referenceMap)){
            $this->referenceMap[$group] = [];
        }
        if (!array_key_exists($key, $this->referenceMap[$group])) {
            $this->referenceMap[$group][$key] = ["index" => -1, "content" => "", "totalCitation" => 0];
        }

        if ($this->referenceMap[$group][$key]["index"] == -1) {
            $this->referenceMap[$group][$key]["index"] = count($this->referenceMap[$group]);
        }
        $this->referenceMap[$group][$key]["totalCitation"]++;

        return $this->referenceMap[$group][$key];
    }

    public function SetReference($group, $key, $content) {
        if (!array_key_exists($group, $this->referenceMap)){
            $this->referenceMap[$group] = [];
        }
        if (!array_key_exists($key, $this->referenceMap[$group])) {
            $this->referenceMap[$group][$key] = ["index" => -1, "content" => "", "totalCitation" => 0];
        }

        $this->referenceMap[$group][$key]["content"] = $content;
    }

    public function ReferenceList($group) {
        if (!array_key_exists($group, $this->referenceMap)){
            return [];
        }
        
        $list = [];
        foreach ($this->referenceMap[$group] as $key => $value) {
            if ($value["index"] != -1) {
                $list[$value["index"]] = [
                    "content" => $value["content"], 
                    "totalCitation" => $value["totalCitation"],
                    "key" => $key, 
                ];
            }
        }

        return $list;
    }

    public function ReplacePathMacros($subject){
        return str_replace($this->pathMacros[0], $this->pathMacros[1], $subject);
    }
}


class Parser {
    // === Parser Configuration ======================================
    public static $indentSpace = 4;

    public static $nonVoidHtmlTagList = [
        'li', 'dt', 'dd', 'p', 'tr', 'td', 'th', 'rt', 'rp', 'optgroup',
        'option', 'thead', 'tfoot',

        'tbody', 'colgroup',

        'script', 'noscript',

        'pre', 'ol', 'ul', 'dl', 'figure', 'figcaption', 'div',

        'a', 'em', 'strong', 'small', 's', 'cite', 'q', 'i', 'b', 'span',

        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',

        'table', 'caption',

        'form', 'button', 'textarea', 'mark',

        'code', 'del', 'iframe'
    ];

    public static $voidHtmlTagList = ['br', 'img', 'hr', 'input'];

    public static $commentStartToken = '<!--';
    public static $commentEndToken = '-->';

    public static $onResetParserList = [
        'OutlineText\HeadingElementParser',
        'OutlineText\TableElementParser',
        'OutlineText\ListElementParser',
        'OutlineText\ParagraphElementParser',
        'OutlineText\BoxElementParser',
        'OutlineText\ReferenceListParser',
        'OutlineText\BlockquoteElementParser',
        'OutlineText\DefinitionListElementParser',
    ];

    public static $onNewLineParserList = [
        'OutlineText\HeadingElementParser',
        'OutlineText\TableElementParser',
        'OutlineText\ReferenceListParser',
    ];

    public static $onPreBeginLineParserList = [
        'OutlineText\ListElementParser',
        'OutlineText\DefinitionListElementParser',
        'OutlineText\BoxElementParser',
    ];

    public static $onBeginLineParserList = [
        'OutlineText\ListElementParser',
        'OutlineText\DefinitionListElementParser',
        'OutlineText\HeadingElementParser',
        'OutlineText\BoxElementParser',
        'OutlineText\BlockquoteElementParser',
        'OutlineText\HorizontalLineElementParser',
        'OutlineText\FigureElementParser',
        'OutlineText\ReferenceListParser',
        'OutlineText\TableElementParser',
        'OutlineText\ParagraphElementParser',
    ];

    public static $onEmptyLineParserList = [
        'OutlineText\HeadingElementParser',
        'OutlineText\ParagraphElementParser',
        'OutlineText\TableElementParser',
    ];

    public static $onBlockBreakParserList = [
        'OutlineText\HeadingElementParser',
        'OutlineText\ParagraphElementParser',
        'OutlineText\TableElementParser',
        'OutlineText\ReferenceListParser',
    ];

    // Note:
    //  Definitionの子供に list は, あるが,
    //  list の子供に　Definition はない.
    //  listの子供のSectionがdefinitionを持つ
    public static $onIndentParserList = [
        'OutlineText\DefinitionListElementParser', // 順番大事. Definition -> list
        'OutlineText\ListElementParser',
        'OutlineText\SectionElementParser',
    ];

    public static $onOutdentParserList = [
        'OutlineText\ListElementParser', // 順番大事. List -> Definition
        'OutlineText\DefinitionListElementParser',
        'OutlineText\SectionElementParser',
    ];

    public static $onEndOfDocumentParserList = [
        'OutlineText\ListElementParser',
        'OutlineText\DefinitionListElementParser',
        'OutlineText\ReferenceListParser',
    ];

    public static $inlineElementPatternTable = [
        ["/\[\[ *(.*?) *\]\]/", '<a name="{0}"></a>', null],
        ["/\[(.*?)\]\((.*?)\)/", null, ['OutlineText\Parser','DecodeLinkElementCallback']],
        ["/\*\*(.*?)\*\*/", '<strong>{0}</strong>', null],
        ["/\/\/(.*?)\/\//", '<em>{0}</em>', null],
        ["/__(.*?)__/", '<mark>{0}</mark>', null],
        ["/~~(.*?)~~/", '<del>{0}</del>', null],
        ["/\^\[(.*?)\]/", null, ['OutlineText\Parser','DecodeReferenceElementCallback']],
        ["/<((http|https):\/\/[0-9a-z\-\._~%\:\/\?\#\[\]@\!\$&'\(\)\*\+,;\=]+)>/i", '<a href="{0}" class="bare link">{0}</a>', null],
        ["/<(([a-zA-Z0-9])+([a-zA-Z0-9\?\*\[|\]%'=~^\{\}\/\+!#&\$\._-])*@([a-zA-Z0-9_-])+\.([a-zA-Z0-9\._-]+)+)>/", '<a href="mailto:{0}">{0}</a>', null],
        // ["/:([^:]*?)-solid:/",'<i class="fas fa-{0}" title="{0}" aria-hidden="true"></i><span class="sr-only">{0}</span>', null],
        // ["/:([^:]*?)-light:/",'<i class="fal fa-{0}" title="{0}" aria-hidden="true"></i><span class="sr-only">{0}</span>', null],
        // ["/:([^:]*?)-duotone:/",'<i class="fad fa-{0}" title="{0}" aria-hidden="true"></i><span class="sr-only">{0}</span>', null],
        // ["/:(.+?):/",'<i class="far fa-{0}" title="{0}" aria-hidden="true"></i><span class="sr-only">{0}</span>', null],
        ["/->/", '&#8594;', null],
        ["/<-/", '&#8592;', null],
        ["/=>/", '&#8658;', null],
        ["/<=/", '&#8656;', null],
        ["/\.\.\./", '&#8230;', null],
        ["/--/", '&#8212;', null],
        ["/\(TM\)/", '&#8482;', null],
        ["/\(R\)/", '&#174;', null],
        ["/\(C\)/", '&#169;', null],
        ["/'/", '&#8217;', null],
    ];

    // End Parser Configuration ===

    private static $onResetParserFuncList = [];
    private static $onEmptyLineParserFuncList = [];
    private static $onNewLineParserFuncList = [];
    private static $onPreBeginLineParserFuncList = [];
    private static $onBeginLineParserFuncList = [];
    private static $onIndentParserFuncList = [];
    private static $onOutdentParserFuncList = [];
    private static $onEndOfDocumentParserFuncList = [];
    private static $onBlockBreakParserFuncList = [];

    private static $blockSeparatorsPattern;
    private static $nonVoidHtmlStartTagsPattern;
    private static $nonVoidHtmlEndTagsPattern;
    private static $voidHtmlTagsPattern;
    private static $inlienElementsCount;

    private static $isInitialized = false;

    public static function Init() {
        if (static::$isInitialized) {
            return;
        }

        $nonVoidHtmlTagCount = count(static::$nonVoidHtmlTagList);
        static::$nonVoidHtmlStartTagsPattern = '';
        for ($i = 0; $i < $nonVoidHtmlTagCount; $i++) {
            // \b: 単語境界
            static::$nonVoidHtmlStartTagsPattern .= '(<' . static::$nonVoidHtmlTagList[$i] . '\b.*?>)';
            if ($i < $nonVoidHtmlTagCount - 1) {
                static::$nonVoidHtmlStartTagsPattern .= '|';
            }
        }

        static::$nonVoidHtmlEndTagsPattern = '';
        for ($i = 0; $i < $nonVoidHtmlTagCount; $i++) {
            static::$nonVoidHtmlEndTagsPattern .= '(<\/' . static::$nonVoidHtmlTagList[$i] . ' *?>)';
            if ($i < $nonVoidHtmlTagCount - 1) {
                static::$nonVoidHtmlEndTagsPattern .= '|';
            }
        }

        $voidHtmlTagCount = count(static::$voidHtmlTagList);
        static::$voidHtmlTagsPattern = '';
        for ($i = 0; $i < $voidHtmlTagCount; $i++){
            static::$voidHtmlTagsPattern .= '(<' . static::$voidHtmlTagList[$i] . '\b.*?\/?>)';
            if ($i < $voidHtmlTagCount - 1) {
                static::$voidHtmlTagsPattern .= '|';
            }
        }

        // ブロックごとに区切るためのpattern
        static::$blockSeparatorsPattern = '/' . 
            static::$nonVoidHtmlStartTagsPattern . 
            '|' . 
            static::$nonVoidHtmlEndTagsPattern . 
            '|' . 
            static::$voidHtmlTagsPattern .
            '|(`)' . 
            '/i';

        static::$nonVoidHtmlStartTagsPattern = '/' . static::$nonVoidHtmlStartTagsPattern . '/i';
        static::$nonVoidHtmlEndTagsPattern = '/' . static::$nonVoidHtmlEndTagsPattern . '/i';
        static::$voidHtmlTagsPattern = '/' . static::$voidHtmlTagsPattern . '/i';

        foreach (static::$onEmptyLineParserList as $parser) {
            static::$onEmptyLineParserFuncList[] = [$parser, 'OnEmptyLine'];
        }
        foreach (static::$onNewLineParserList as $parser) {
            static::$onNewLineParserFuncList[] = [$parser, 'OnNewLine'];
        }
        foreach (static::$onPreBeginLineParserList as $parser) {
            static::$onPreBeginLineParserFuncList[] = [$parser, 'OnPreBeginLine'];
        }
        foreach (static::$onBeginLineParserList as $parser) {
            static::$onBeginLineParserFuncList[] = [$parser, 'OnBeginLine'];
        }
        foreach (static::$onResetParserList as $parser) {
            static::$onResetParserFuncList[] = [$parser, 'OnReset'];
        }
        foreach (static::$onIndentParserList as $parser) {
            static::$onIndentParserFuncList[] = [$parser, 'OnIndent'];
        }
        foreach (static::$onOutdentParserList as $parser) {
            static::$onOutdentParserFuncList[] = [$parser, 'OnOutdent'];
        }
        foreach (static::$onEndOfDocumentParserList as $parser) {
            static::$onEndOfDocumentParserFuncList[] = [$parser, 'OnEndOfDocument'];
        }
        foreach (static::$onBlockBreakParserList as $parser) {
            static::$onBlockBreakParserFuncList[] = [$parser, 'OnBlockBreak'];
        }

        static::$inlienElementsCount = count(static::$inlineElementPatternTable);

        static::$isInitialized = true;
    }

    //
    // 処理の流れは以下の通り.
    //  1. morphに分ける
    //  2. morphごとにデコード処理を行う.
    //
    public static function Parse($plainText, &$context = null) {
        if (!static::$isInitialized) {
            static::Init();
        }

        $output = '';

        // 前処理
        // 空行を追加する.
        // ファイルの終わりの処理と空行の処理を一緒にする.
        $plainText .= "\n";

        // 文脈用意
        if (is_null($context)) {
            $context = new Context();
        }

        // morphにわける
        $morphs = self::ParseMorphs($plainText);
        $context->morphSequence->SetSequence($morphs);

        foreach (static::$onResetParserFuncList as $func) {
            call_user_func($func);
        }

        $context->indentLevel = 0;
        $context->indentLevelPrevious = 0;

        // --- 各チャンクごとに対して --------------------------------
        for (;!$context->morphSequence->isEndOfMorph; $context->morphSequence->Iterate()) {

            $currentMorph = $context->morphSequence->currentMorph;

            $out = '';
            if (static::DecodeExceptElements($currentMorph, $out)) {
                $output .= $out;
                continue;
            } elseif ($currentMorph['isEmptyLine']) {
                $output .= static::CallbackEventFuncs(static::$onEmptyLineParserFuncList, $context);
                continue;
            }

            // 文中
            elseif ($currentMorph["indentLevel"] == -1) {
                $output .= static::DecodeInlineElements($currentMorph["content"], $context);
                continue;
            }

            //
            // ここから下の処理対象は, 各行の先頭にあるチャンクであることが保証されている.
            //

            if ($context->skipNextLineMorph) {
                $context->skipNextLineMorph = false;
                continue;
            }

            // 新しい行が始まった
            $output .= static::CallbackEventFuncs(static::$onNewLineParserFuncList, $context);

            //
            // --- インデントレベルの変化を見る ----------------------

            // 右へインデント
            if ($currentMorph["indentLevel"] > $context->indentLevelPrevious) {
                while ($context->indentLevel < $currentMorph["indentLevel"]) {
                    $context->indentLevel++;
                    $output .= static::CallbackEventFuncs(static::$onIndentParserFuncList, $context);
                }
            }

            // 左へインデント
            if ($currentMorph["indentLevel"] < $context->indentLevelPrevious) {
                while ($currentMorph["indentLevel"] < $context->indentLevel) {
                    $output .= static::CallbackEventFuncs(static::$onOutdentParserFuncList, $context);
                    $context->indentLevel--;
                }
            }

            $context->indentLevelPrevious = $context->indentLevel;

            // End インデントの変化を見る ---
            
            // 空文字の時
            // インデント値はあるが, 空文字
            // その次がインラインコード, html要素のときに起こる.
            if ($currentMorph["content"] == "") {

                // 次がインラインコードのときは, このまま処理を続ける.
                // 次がインラインコードのとき, このまま処理を続けないと,
                // <p></p>で囲まれない.
                //
                // 逆にその次が, html要素のときは, <p></p>で囲まれてしまい,
                // <p></p>で囲めない要素が来た時によろしくない.
                if (($context->morphSequence->nextMorph !== null) && $context->morphSequence->nextMorph["isInlineCode"]) {

                } else {
                    // 次がhtml要素などはこのまま処理を続けない.
                    // \Debug::Log('asad');
                    continue;
                }
                //continue;
            }

            // 行頭の前処理
            $output .= static::CallbackEventFuncs(static::$onPreBeginLineParserFuncList, $context);

            // 行頭の処理
            $output .= static::CallbackEventFuncs(static::$onBeginLineParserFuncList, $context);

        } // End 各チャンクごとに対して ----

        // すべてのチャンクの処理を終えた場合
        while(0 < $context->indentLevel){
            $output .= static::CallbackEventFuncs(static::$onOutdentParserFuncList, $context);
            $context->indentLevel--;
        }
        $context->indentLevelPrevious = $context->indentLevel;
        
        $output .= static::CallbackEventFuncs(static::$onEndOfDocumentParserFuncList, $context);

        //Debug::Log($output);
        return $output;
    }

    private static function CallbackEventFuncs($funcs, $context) {
        $output = '';

        foreach ($funcs as $func) {
            $out = '';
            $break = call_user_func_array($func, [$context, &$out]);
            $output .= $out;
            if ($break) {
                break;
            }
        }

        return $output;
    }

    public static function DoBlockBreak($context, &$output) {
        $output = static::CallbackEventFuncs(static::$onBlockBreakParserFuncList, $context);
    }

    // 文法外要素
    // InlineCode, CodeBlock, HTMLElements
    // が対象.
    private static function DecodeExceptElements($morph, &$output) {
        if ($morph['isTagElement']) {
            $output = $morph["content"];
            return true;
        }
        elseif ($morph['isCodeBlock']) {
            if ($morph["codeBlockAttribute"] == "math") {
                $output = "<div class='math'>" .
                static::EscapeSpecialCharacters($morph["content"]) .
                    "</div>";
                return true;
            }
            else {
                $attribute = $morph["codeBlockAttribute"] == '' ? 'plain' : $morph["codeBlockAttribute"];
                $output = "<pre class='brush: " . $attribute . ";'>" .
                static::EscapeSpecialCharacters($morph["content"]) .
                    "</pre>";
                return true;
            }
        }
        elseif ($morph['isInlineCode']) {
            $output = "<code>" . static::EscapeSpecialCharacters($morph["content"]) . "</code>";
            return true;
        }

        return false;
    }

    public static function DecodeInlineElements($text, $context) {
        // --- マッチ情報の初期化 ------------------------------------
        $patternMatchInfos = array();

        for ($i = 0; $i < static::$inlienElementsCount; $i++) {
            $patternMatchInfos[] = ["matches" => array(), "iteratorIndex" => 0, "matchedCount" => 0];
        }

        // end マッチ情報の初期化 ---

        // パターンのマッチ
        for ($i = 0; $i < static::$inlienElementsCount; $i++) {
            preg_match_all(static::$inlineElementPatternTable[$i][0], $text, $patternMatchInfos[$i]["matches"], PREG_OFFSET_CAPTURE);
            $patternMatchInfos[$i]["matchedCount"] = count($patternMatchInfos[$i]["matches"][0]);
        }

        //var_dump($patternMatchInfos);

        $currentPosition = 0;
        $output = "";

        for (;;) {
            // マッチしたパターンのうちパターン始まり位置が若いのを選ぶ
            $focusedPatternIndex = -1;
            for ($i = 0; $i < static::$inlienElementsCount; $i++) {

                if ($patternMatchInfos[$i]["matchedCount"] <= 0 ||
                    $patternMatchInfos[$i]["iteratorIndex"] >= $patternMatchInfos[$i]["matchedCount"]) {
                    continue;
                }

                if ($focusedPatternIndex < 0) {
                    $focusedPatternIndex = $i;
                    continue;
                }

                if ($patternMatchInfos[$i]["matches"][0][$patternMatchInfos[$i]["iteratorIndex"]][1] <
                    $patternMatchInfos[$focusedPatternIndex]["matches"][0][$patternMatchInfos[$focusedPatternIndex]["iteratorIndex"]][1]) {
                    $focusedPatternIndex = $i;
                }
            }

            if ($focusedPatternIndex < 0) {
                break;
            }

            $focusedPatternIteratorIndex = $patternMatchInfos[$focusedPatternIndex]["iteratorIndex"];
            $focusedPatternStartPosition = $patternMatchInfos[$focusedPatternIndex]["matches"][0][$focusedPatternIteratorIndex][1];

            // パターン開始位置が現在の位置よりも前のとき
            // 直前のパターン内にパターン文字が含まれている可能性が高いので, currentPositionから再びパターンを検索する.
            //  例:
            //   [abc](**abc) **strong**
            //
            //   これは, 何も対策しないと次のようになる.
            //   <a href='**abc'>abc</a> **strong**
            //
            if ($focusedPatternStartPosition < $currentPosition) {
                preg_match_all(static::$inlineElementPatternTable[$focusedPatternIndex][0], $text, $patternMatchInfos[$focusedPatternIndex]["matches"], PREG_OFFSET_CAPTURE, $currentPosition);
                $patternMatchInfos[$focusedPatternIndex]["matchedCount"] = count($patternMatchInfos[$focusedPatternIndex]["matches"][0]);
                $patternMatchInfos[$focusedPatternIndex]["iteratorIndex"] = 0;
                continue;
            }

            $focusedPatternString = $patternMatchInfos[$focusedPatternIndex]["matches"][0][$focusedPatternIteratorIndex][0];

            //echo $focusedPatternStartPosition;

            // パターン前の文字列を出力
            $output .= static::EscapeSpecialCharacters(substr($text, $currentPosition, $focusedPatternStartPosition - $currentPosition));

            $spanString = "";

            if (static::$inlineElementPatternTable[$focusedPatternIndex][1] != null) {
                $spanString = static::$inlineElementPatternTable[$focusedPatternIndex][1];
                $capturedCount = count($patternMatchInfos[$focusedPatternIndex]["matches"]) - 1;
                for ($i = 0; $i < $capturedCount; $i++) {
                    $spanString = str_replace(
                        "{" . ($i) . "}", 
                        static::EscapeSpecialCharacters($patternMatchInfos[$focusedPatternIndex]["matches"][$i + 1][$focusedPatternIteratorIndex][0]), 
                        $spanString);
                }
            }

            if (static::$inlineElementPatternTable[$focusedPatternIndex][2] != null) {
                $matchCount = count($patternMatchInfos[$focusedPatternIndex]["matches"]);
                $matches = [];

                for ($i = 0; $i < $matchCount; $i++) {
                    $matches[] = $patternMatchInfos[$focusedPatternIndex]["matches"][$i][$focusedPatternIteratorIndex];
                }
                $spanString .= call_user_func(static::$inlineElementPatternTable[$focusedPatternIndex][2], $matches, $context);
            }

            $output .= $spanString;

            $currentPosition = $focusedPatternStartPosition + strlen($focusedPatternString);

            $patternMatchInfos[$focusedPatternIndex]["iteratorIndex"]++;
        }

        $output .= static::EscapeSpecialCharacters(substr($text, $currentPosition));

        $blocks = preg_split("/(\016{[0-9]+}\016)/", $output, -1, PREG_SPLIT_DELIM_CAPTURE);
        $blocksCount = count($blocks);
        for ($index = 0; $index < $blocksCount; $index++) {
            if (preg_match("/(\016{[0-9]+}\016)/", $blocks[$index])) {
                $morphIndex = intval(substr($blocks[$index], 2, -2));
                $morph = $context->morphSequence->morphs[$morphIndex];

                static::DecodeExceptElements($morph, $blocks[$index]);
            }
        }

        return implode($blocks);
    }

    private static function DecodeReferenceElementCallback($matches, $context) {
        $key = "";
        $group = "cite";
        $prefix = "";

        $blocks = explode(".", $matches[1][0], 2);

        if(count($blocks) == 1){
            $key = trim($blocks[0]);
        }
        else{
            $group = trim($blocks[0]);
            $key = trim($blocks[1]);
            $prefix = $group . ' ';
        }

        $ref = $context->AddReference($group, $key);
        $citationNumber = $ref["totalCitation"] - 1;
        return "<sup id='{$group}-ref-{$key}-{$citationNumber}' class='reference'><a href='#{$group}-note-{$key}'>[{$prefix}{$ref["index"]}]</a></sup>";
    }

    private static function DecodeLinkElementCallback($matches, $context){
        $linkText = $matches[1][0];
        $url = $context->ReplacePathMacros($matches[2][0]);

        return '<a href="' . $url .'" class="link">' . $linkText . '</a>';
    }

    private static function EscapeSpecialCharacters($text) {
        $text = str_replace('&', '&amp;', $text);
        $text = str_replace('<', '&lt;', $text);
        $text = str_replace('>', '&gt;', $text);

        return $text;
    }
    
    //
    // morphについて:
    //  デコード処理単位である.
    //  まず, デコード(タグのエスケープは除く)対象とそうでないものにチャンク分けは行われる.
    //  デコード対象とならないものは, インラインコードの中, コードブロックの中, タグブロックの中である.
    //  また, 行替えごとにチャンクは分けられる.
    //
    // morphの追加のタイミング
    //
    //  * tagBlockから抜けたとき
    //  * tagBlockに入ったとき
    //  * tagBlock内ではなく and 空白行のとき
    //  * CodeBlockから出るとき
    //  * インラインコードに入る or 出るとき
    //  * tagBlock内ではなく and 行が終わるとき
    //
    // ExceptElementsにはインデント値を含めないこと.
    //
    public static function ParseMorphs($plainText) {
        $morphs = [];
        $morph = CreateMorph();

        $lines = explode("\n", $plainText);
        $lineCount = count($lines);

        // --- 複数行にまたがって存在する情報 -------------
        $startSpaceCount = 0;
        $tagBlockLevel = 0;
        $tagBlockLevelPrevious = 0;

        $isStartWriting = false;

        $morphIndex = 0;
        $lineStartMorphIndex = 0;

        $isInInlineCode = false;
        $isInCodeBlock = false;
        $codeBlockIndentLevel = 0;
        $continueLine = false;

        $isInComment = false;
        // End 複数行にまたがって存在する情報 ----

        for ($i = 0; $i < $lineCount; $i++) {
            
            // --- コメントアウト処理 -------------
            if ($isInComment) {
                // コメントから出る
                if (preg_match("/^ *" . static::$commentEndToken . "/", $lines[$i], $matches) === 1) {
                    $isInComment = false;
                }
                continue;
            } else {
                // コメントに入る
                if (!$isInCodeBlock && $tagBlockLevel <= 0 &&
                    preg_match("/^ *" . static::$commentStartToken . "/", $lines[$i], $matches) === 1) {

                    if (!preg_match("/" . static::$commentEndToken . " *$/", $lines[$i], $matches)) {
                        $isInComment = true;
                    }

                    continue;
                }
            }
            // end コメントアウト処理 -------------

            // 書き込みの始まりを確認
            // 最初の空白文字の計算
            if (!$isStartWriting) {
                $wordCount = strlen($lines[$i]);

                for ($startSpaceCount = 0; $startSpaceCount < $wordCount; $startSpaceCount++) {
                    if ($lines[$i][$startSpaceCount] != ' ') {
                        break;
                    }
                }

                if ($startSpaceCount != $wordCount) {
                    $isStartWriting = true;
                    //echo $lines[$i] . "<br>";
                }
            }

            // まだ文章が始まっていないとき
            if (!$isStartWriting) {
                continue;
            }

            // 前の行から続いているとき
            if($continueLine){
                $continueLine = false;
            }
            // 新しく行が始まるとき
            else {
                // --- indentLevelの計算 -----------------------------
                $wordCount = strlen($lines[$i]);
                $spaceCount = 0;
                for ($spaceCount = 0; $spaceCount < $wordCount; $spaceCount++) {
                    if ($lines[$i][$spaceCount] != ' ') {
                        break;
                    }
                }

                $isEmpty = false;

                // すべて, Spaceのとき
                if ($spaceCount == $wordCount) {
                    $isEmpty = true;
                }

                $indentLevel = intdiv(($spaceCount - $startSpaceCount), static::$indentSpace);
                //echo $startSpaceCount;

                // End indentLevelの計算 ------------------------

                // 現在コードブロック内のとき
                if ($isInCodeBlock) {
                    // コードブロックから出る
                    if ($codeBlockIndentLevel == $indentLevel && preg_match("/^ *```(.*)/", $lines[$i], $matches) === 1) {
                        $isInCodeBlock = false;

                        $morphIndex++;
                        $morphs[] = $morph;

                        $morph = CreateMorph();

                        continue;
                    }

                    // コードブロック内の処理
                    else {
                        $morph["content"] .= $lines[$i] . "\n";
                        continue;
                    }
                }

                // 現在コードブロックに入っていないとき
                else {
                    // コードブロック内に入る
                    if ($tagBlockLevel <= 0 && preg_match("/^ *```(.*)/", $lines[$i], $matches) === 1) {
                        $isInCodeBlock = true;
                        $codeBlockIndentLevel = $indentLevel;

                        $morph["indentLevel"] = $indentLevel;
                        $morph["spaceCount"] = $spaceCount;

                        $morphIndex++;

                        $morphs[] = $morph;
                        $morph = CreateMorph();

                        $morph["isCodeBlock"] = true;
                        $morph["codeBlockAttribute"] = $matches[1];

                        continue;
                    }
                }

                // 空白行のとき
                if ($isEmpty) {
                    if ($tagBlockLevel > 0) {
                        $morph["content"] .= "\n";
                    }

                    // タグブロック内ではない
                    else {
                        $morph["nextLineMorphIndex"] = $morphIndex + 1;
                        $morph["isEmptyLine"] = true;

                        $morphs[] = $morph;

                        $morphIndex++;
                        $morph = CreateMorph();

                        $lineStartMorphIndex = $morphIndex;
                    }

                    continue;
                }

                if ($tagBlockLevel <= 0) {
                    $morph["indentLevel"] = $indentLevel;
                    $morph["spaceCount"] = $spaceCount;
                }
            }

            // ブロックの分割
            $blocks = preg_split(static::$blockSeparatorsPattern, $lines[$i], -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            $blockCount = count($blocks);
            // var_dump($blocks);

            $beginInlineCode = false;

            // --- ブロックごとの処理 ----
            for ($j = 0; $j < $blockCount; $j++) {
                if ($beginInlineCode) {
                    // インラインコードから抜ける
                    if ($blocks[$j] == "`") {
                        $beginInlineCode = false;

                        $morphIndex++;
                        $morphs[] = $morph;
                        $morph = CreateMorph();

                        continue;
                    }

                    // インラインコード内
                    else {
                        $morph["content"] .= $blocks[$j];
                        continue;
                    }

                } else {
                    // インラインコードに入る
                    if ($tagBlockLevel <= 0 && $blocks[$j] == "`") {
                        $morphIndex++;
                        $morphs[] = $morph;
                        $morph = CreateMorph();

                        $beginInlineCode = true;
                        $morph["isInlineCode"] = true;

                        continue;
                    }
                }

                if (preg_match(static::$nonVoidHtmlStartTagsPattern, $blocks[$j]) === 1) {
                    $tagBlockLevel++;
                }

                if (preg_match(static::$nonVoidHtmlEndTagsPattern, $blocks[$j]) === 1) {
                    $tagBlockLevel--;
                }

                if ($tagBlockLevel != $tagBlockLevelPrevious) {
                    // タグブロック内に入った
                    if ($tagBlockLevel > 0 && $tagBlockLevelPrevious <= 0) {
                        $morphIndex++;

                        $morphs[] = $morph;
                        $morph = CreateMorph();
                        $morph["isTagElement"] = true;

                        $morph["content"] .= $blocks[$j];
                    }

                    //　タグブロックから出た
                    elseif ($tagBlockLevel <= 0 && $tagBlockLevelPrevious > 0) {
                        $morphIndex++;

                        $morph["content"] .= $blocks[$j];

                        $morphs[] = $morph;
                        $morph = CreateMorph();
                        $morph["isTagElement"] = false;
                    }

                    // タグブロック内での変化
                    else {
                        $morph["content"] .= $blocks[$j];
                    }

                    $tagBlockLevelPrevious = $tagBlockLevel;
                }

                // タグブロックの変化がない
                else {
                    if ($tagBlockLevel <= 0) {
                        // タグブロック外
                        if (preg_match(static::$voidHtmlTagsPattern, $blocks[$j]) === 1){
                            // voidHtmlTag(閉じタグのないHTML要素タグ)のとき
                            $morphIndex++;
                            $morphs[] = $morph;
                            $morph = CreateMorph();
    
                            $morph["isTagElement"] = true;
                            $morph["content"] .= $blocks[$j];
    
                            $morphIndex++;
                            $morphs[] = $morph;
                            $morph = CreateMorph();
                        }
                        else{
                            if ($j == 0) {
                                // 先頭のスペースを削除
                                $blocks[$j] = ltrim($blocks[$j], ' ');
                            }

                            if ($j == $blockCount - 1) {
                                // 行末のスペースを削除
                                $blocks[$j] = rtrim($blocks[$j], ' ');
                            }
                            $morph["content"] .= $blocks[$j];
                        }
                    } else {
                        // タグブロック内
                        $morph["content"] .= $blocks[$j];
                    }
                }

            } // End ブロックのごとの処理 ---

            // 行の終わり & タグブロック内ではないとき
            if ($tagBlockLevel <= 0) {
                // 直前に'\'がない'\'にマッチする
                if(preg_match("/(?<!\\\\)\\\\$/", $morph["content"])){
                    // 行末がバックスラッシュのとき行が続いているとする.
                    // チャンクが続いているとする
                    $morph['content'] = substr($morph['content'], 0, -1);
                    $continueLine = true;
                    continue;
                }

                $morphIndex++;
                $morphs[] = $morph;

                for ($j = $lineStartMorphIndex; $j < $morphIndex; $j++) {
                    $morphs[$j]["nextLineMorphIndex"] = $morphIndex;
                }
                $lineStartMorphIndex = $morphIndex;

                $morph = CreateMorph();
            }

            // 行の終わり & タグブロック内のとき
            else {
                $morph["content"] .= "\n";
            }
        } // End 各行ごとの処理 ---

        // ループを抜けたmorphを次の行とするこれまでのチャンクが存在する.
        // このとき, indent値が設定されていないmorphは, 空行のみである.
        if($morph['indentLevel'] == -1){
            $morph['isEmptyLine'] = true;
        }
        $morphs[] = $morph;

        return $morphs;
    }
    
} // End class Parser
