<?php

/*
 *
 * HTMLElements
 * ExceptElements
 * InlineCode
 * CodeBlock
 * SpanElements
 *
 */

namespace OutlineText;

require_once dirname(__FILE__) . "/Debug.php";

class ElementParser
{
    public static function OnReset()
    {}

    public static function OnEmptyLine($context, &$output)
    {$output = '';return false;}

    public static function OnPreBeginLine($context, &$output)
    {$output = '';return false;}

    public static function OnBeginLine($context, &$output)
    {$output = '';return false;}

    public static function OnIndent($context, &$output)
    {$output = '';return false;}

    public static function OnUnindent($context, &$output)
    {$output = '';return false;}

    public static function OnUnchangedIndent($context, &$output)
    {$output = '';return false;}
}

class FigureElementParser extends ElementParser
{
    public static function OnBeginLine($context, &$output)
    {
        $output = '';

        $matches = [];
        if (preg_match("/^!\[(.*)?\]\((.*)?\)/", $context->CurrentChunk()["content"], $matches)) {

            $output .= "<figure><img src='" . $matches[2] . "' alt='" .
                $matches[1] . "'/><figcaption>" .
                $matches[1] . '</figcaption></figure>';

            return true;
        }
        return false;
    }
}

class HorizontalLineElementParser extends ElementParser
{
    public static function OnBeginLine($context, &$output)
    {
        $output = '';

        if (preg_match("/^----*$/", $context->CurrentChunk()["content"])) {
            $output .= '<hr>';
            return true;
        }

        return false;
    }
}

class ReferenceListParser extends ElementParser
{
    private static $isBegin = false;

    public static function OnReset()
    {
        static::$isBegin = false;
    }

    public static function OnEmptyLine($context, &$output)
    {
        $output = '';

        if (static::$isBegin) {
            $referenceList = $context->ReferenceList();
            $referenceCount = count($referenceList);

            $output .= "<ol class='reference-list'>";
            for ($index = 1; $index <= $referenceCount; $index++) {
                $output .= "<li><a name='ref-" . $referenceList[$index]["key"] . "'></a><cite>" . $referenceList[$index]["content"] . '</cite></li>';
            }
            $output .= '</ol>';

            static::$isBegin = false;
        }

        return false;
    }

    public static function OnBeginLine($context, &$output)
    {
        $output = '';

        $matches = [];
        if (preg_match("/^\[(.*?)\]: (.*)/", $context->CurrentChunk()["content"], $matches)) {
            $context->SetReference($matches[1], Parser::DecodeSpanElements($matches[2], $context));
            static::$isBegin = true;

            return true;
        }

        return false;
    }

}

class BoxElementParser extends ElementParser
{

    private static $boxIndentStack = [];

    private static $isStartOfBox = false;
    private static $isEndOfBox = false;
    private static $title = '';
    private static $type = '';
    private static $isMatched = false;

    public static function OnReset()
    {
        static::$boxIndentStack = [];
    }

    public static function OnPreBeginLine($context, &$output)
    {

        static::$isMatched = static::IsStartOrEndLine($context);

        if (static::$isMatched) {
            $out = '';
            Parser::DoEmptyLine($context, $out);
            $output .= $out;
        }

        return false;
    }

    public static function OnBeginLine($context, &$output)
    {
        $output = '';

        if (static::$isMatched) {

            if (static::$isStartOfBox) {
                static::$boxIndentStack[] = $context->indentLevel;

                $output .= "<div class='box-" . static::$type . "'><span class='box-title'>" .
                Parser::DecodeSpanElements(static::$title, $context) . '</span>';

                $context->skipNextLineChunk = true;

            }

            if (static::$isEndOfBox) {
                array_pop(static::$boxIndentStack);

                $output .= '</div>';
            }

            $context->JumpToEndOfLineChunk();

            return true;
        }
        return false;
    }

    private static function IsStartOrEndLine($context)
    {
        static::$isStartOfBox = false;
        static::$isEndOfBox = false;
        static::$title = '';
        static::$type = '';

        // $line = $context->CurrentChunk()["content"];
        $line = $context->CurrentLine();
        $nextLine = '';
        if ($context->NextLineChunk() !== null && $context->CurrentChunk()["indentLevel"] == $context->NextLineChunk()["indentLevel"]) {
            $nextLine = $context->NextLineChunk()["content"];
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

        if ($context->CurrentChunk()["indentLevel"] == $latestBoxIndent && preg_match("/^====*$/", $line)) {
            static::$isEndOfBox = true;

            return true;
        }

        return false;
    }

}

class ParagraphElementParser extends ElementParser
{
    private static $isBegin = false;

    public static function OnReset()
    {
        static::$isBegin = false;
    }

    public static function OnBeginLine($context, &$output)
    {
        $output = '';

        $currentChunk = $context->CurrentChunk();

        if (!static::$isBegin) {

            $output .= '<p>' . Parser::DecodeSpanElements($currentChunk["content"], $context);
            static::$isBegin = true;

        } else {
            $output .= Parser::DecodeSpanElements($currentChunk["content"], $context);
        }

        return true;
    }

    public static function OnEmptyLine($context, &$output)
    {
        $output = '';

        if (static::$isBegin) {
            $output .= '</p>';
            static::$isBegin = false;
        }

        return false;
    }

    public static function OnIndent($context, &$output)
    {
        $output = '';
        if(static::$isBegin){
            $output .= '</p>';
            static::$isBegin = false;
        }

        return false;
    }

    public static function OnUnindent($context, &$output)
    {
        $output = '';
        if(static::$isBegin){
            $output .= '</p>';
            static::$isBegin = false;
        }

        return false;
    }
}

class SectionElementParser extends ElementParser
{

    public static function OnIndent($context, &$output)
    {
        $output = "<div class='section'>";
        return true;
    }

    public static function OnUnindent($context, &$output)
    {
        $output = '</div>';
        return true;
    }
}

class ListElementParser extends ElementParser
{
    private static $isBegin = false;
    private static $listItemIndentLevelPrevious = 0;
    private static $listItemIndentLevel = 0;
    private static $endTagStack = [];

    public static function OnReset()
    {
        static::$isBegin = false;
        static::$listItemIndentLevel = 0;
        static::$endTagStack = [];
    }

    public static function OnEmptyLine($context, &$output)
    {
        $output = '';

        if (static::$isBegin) {
            $context->indentLevelPrevious -= static::$listItemIndentLevel;
            // $output .= static::$listItemIndentLevel;
            while (static::$listItemIndentLevel >= 0) {
                $output .= "</li>" . array_pop(static::$endTagStack);

                static::$listItemIndentLevel--;
            }

            static::$listItemIndentLevel = 0;
            static::$isBegin = false;
        }

        return false;
    }

    public static function OnIndent($context, &$output)
    {
        $output = '';

        if (static::$isBegin) {
            static::$listItemIndentLevel++;
            return true;
        }

        return false;
    }

    public static function OnUnindent($context, &$output)
    {
        $output = '';

        if (static::$isBegin) {
            static::$listItemIndentLevel--;
            return true;
        }

        return false;
    }

    public static function OnBeginLine($context, &$output)
    {
        $output = '';

        $currentChunk = $context->CurrentChunk();
        $isMatched = false;
        $offset = 0;
        $startTag = '<ul>';
        $endTag = '</ul>';

        if (preg_match("/^\* /", $currentChunk["content"])) {
            $isMatched = true;
            $startTag = '<ul>';
            $endTag = '</ul>';
            $offset = 2;

        } elseif (preg_match("/^\+ /", $currentChunk["content"])) {
            $isMatched = true;
            $startTag = "<ul class='tree'>";
            $endTag = '</ul>';
            $offset = 2;

        } elseif (preg_match("/^([0-9]+.)* /", $currentChunk["content"])) {
            $isMatched = true;
            $startTag = '<ol>';
            $endTag = '</ol>';
            $offset = strpos($currentChunk["content"], ' ') + 1;

        }

        if ($isMatched) {
            if (static::$isBegin) {

                if (static::$listItemIndentLevelPrevious == static::$listItemIndentLevel) {
                    $output .= '</li>';
                }

                if (static::$listItemIndentLevelPrevious < static::$listItemIndentLevel) {
                    while (static::$listItemIndentLevelPrevious < static::$listItemIndentLevel) {
                        $output .= $startTag;
                        static::$endTagStack[] = $endTag;
                        static::$listItemIndentLevelPrevious++;
                    }
                }

                if (static::$listItemIndentLevelPrevious > static::$listItemIndentLevel) {
                    while (static::$listItemIndentLevelPrevious > static::$listItemIndentLevel) {
                        $output .= '</li>' . array_pop(static::$endTagStack);
                        static::$listItemIndentLevelPrevious--;
                    }
                }

                static::$listItemIndentLevelPrevious = static::$listItemIndentLevel;
            } else {
                $output .= $startTag;
                static::$listItemIndentLevel = 0;
                static::$listItemIndentLevelPrevious = 0;
                static::$isBegin = true;

                static::$endTagStack[] = $endTag;
            }

            $output .= '<li>' . Parser::DecodeSpanElements(substr($currentChunk["content"], $offset), $context);

            return true;
        }

        if (static::$isBegin) {

            $output .= Parser::DecodeSpanElements($currentChunk["content"], $context);

            return true;
        }

        return false;
    }

}

class TableElementParser extends ElementParser
{
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

    public static function OnReset()
    {
        static::$isBegin = false;
        static::$columnHeadCount = 0;
        static::$isBeginBody = false;
        static::$isBeginRow = false;
    }

    public static function OnEmptyLine($context, &$output)
    {
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

    public static function OnPreBeginLine($context, &$output)
    {
        $output = '';

        if (static::$isBeginRow) {
            $output .= "</tr>";
            static::$isBeginRow = false;
        }

        return false;
    }

    public static function OnBeginLine($context, &$output)
    {
        $output = '';

        if (static::IsTableLine($context)) {

            if (!static::$isBegin) {
                $output .= '<table>';

                if (static::$isCaption) {
                    $output .= '<caption>' . Parser::DecodeSpanElements(static::$caption, $context) . '</caption>';
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

                    $output .= Parser::DecodeSpanElements(static::$tableRowContents[$col], $context);

                    if ((static::$isBeginBody && $col < static::$columnHeadingCount) || !static::$isBeginBody) {
                        $output .= '</th>';
                    } else {
                        $output .= '</td>';
                    }
                }

            }

            $context->JumpToEndOfLineChunk();

            return true;
        }

        return false;
    }

    private static function IsTableLine($context)
    {

        static::$tableRowContents = [];
        static::$caption = '';
        static::$isCaption = false;
        static::$columnHeadingCount = 0;
        static::$isHeadingAndBodySeparator = false;
        static::$isTableRow = false;

        $line = $context->CurrentLine();

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

class HeadingElementParser extends ElementParser
{
    private static $isBegin = false;
    private static $heading = '';
    private static $nextLineIsHorizontalLine = false;

    public static function OnReset()
    {
        static::$isBegin = false;
    }

    public static function OnEmptyLine($context, &$output)
    {
        $output = '';

        if (static::$isBegin) {
            $output .= '</h' . ($context->indentLevel + 2) . '>';
            static::$isBegin = false;
        }

        return false;
    }

    public static function OnPreBeginLine($context, &$output)
    {
        $output = '';

        if (static::$isBegin) {
            $output .= '</h' . ($context->indentLevel + 2) . '>';
            static::$isBegin = false;
        }

        return false;
    }

    public static function OnBeginLine($context, &$output)
    {

        $output = '';

        if (static::IsHeadingLine($context)) {

            $output .= '<h' . ($context->indentLevel + 2) . ' ';
            if ($context->indentLevel <= 0) {
                $output .= "class = 'section-title'>";
            } elseif ($context->indentLevel == 1) {
                $output .= "class = 'sub-section-title'>";
            } elseif ($context->indentLevel == 2) {
                $output .= "class = 'sub-sub-section-title'>";
            } else {
                $output .= "class = 'sub-sub-sub-section-title'>";
            }

            $output .= Parser::DecodeSpanElements(static::$heading, $context);

            if (static::$nextLineIsHorizontalLine) {
                $context->skipNextLineChunk = true;
            }

            static::$isBegin = true;
            return true;
        }

        return false;
    }

    private static function IsHeadingLine($context)
    {

        static::$heading = '';
        static::$nextLineIsHorizontalLine = false;

        $headingHasHash = false;

        $line = $context->CurrentChunk()['content'];
        $nextLine = "";
        if ($context->NextLineChunk() !== null && $context->CurrentChunk()['indentLevel'] == $context->NextLineChunk()['indentLevel']) {
            $nextLine = $context->NextLineChunk()['content'];
        }

        if (preg_match("/^\# /", $line)) {
            $headingHasHash = true;
        }

        if (preg_match("/^----*$/", $nextLine)
            || preg_match("/^====*$/", $nextLine)
            || preg_match("/^____*$/", $nextLine)) {

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

class Context
{

    public $chunks = array();

    public static function CreateChunk()
    {
        return ['indentLevel' => -1, 'spaceCount' => 0, 'isTagElement' => false, 'isCodeBlock' => false,
            'content' => '', 'nextLineChunkIndex' => -1, 'codeBlockAttribute' => '', 'isInlineCode' => false, 'isEmptyLine' => false];
    }

    private $isEndOfChunk = false;
    private $currentChunk = null;
    private $chunksCount = 0;
    private $currentChunkIndex = 0;
    private $nextLineChunk = null;
    private $nextLineChunkIndex = -1;
    private $nextChunk = null;

    private $currentLine = '';

    private $referenceCount = 0;
    private $referenceMap = [];

    public $indentLevelPrevious = -1;
    public $indentLevel = 0;

    public $skipNextLineChunk = false;

    public function CurrentChunk()
    {return $this->currentChunk;}

    public function CurrentLine()
    {return $this->currentLine;}

    public function NextLineChunk()
    {return $this->nextLineChunk;}

    public function IsEndOfChunk()
    {return $this->isEndOfChunk;}

    public function NextChunk()
    {return $this->nextChunk;}

    public function SetChunks($chunksToSet)
    {
        $this->skipNextLineChunk = false;

        $this->chunks = $chunksToSet;
        $this->chunksCount = count($this->chunks);
        $this->currentChunkIndex = -1;
        $this->isEndOfChunk = false;
        $this->IterateChunk();

        //var_dump($chunksToSet);
    }

    public function IterateChunk()
    {
        if ($this->isEndOfChunk) {
            return;
        }

        if ((++$this->currentChunkIndex) >= $this->chunksCount) {
            $this->currentChunk = null;
            $this->isEndOfChunk = true;
            $this->nextLineChunk = null;
            $this->nextChunk = null;
            return;
        }

        $this->currentChunk = $this->chunks[$this->currentChunkIndex];

        $this->nextLineChunkIndex = -1;
        $this->nextLineChunk = null;
        if ($this->currentChunk["nextLineChunkIndex"] != -1) {
            $this->nextLineChunkIndex = $this->currentChunk["nextLineChunkIndex"];
            $this->nextLineChunk = $this->chunks[$this->nextLineChunkIndex];
        }

        if ($this->currentChunk['indentLevel'] != -1) {
            // 文頭
            $this->currentLine = '';

            $endOfLineIndex = $this->nextLineChunkIndex != -1 ? $this->nextLineChunkIndex - 1 : $this->chunksCount - 1;

            for ($index = $this->currentChunkIndex; $index <= $endOfLineIndex; $index++) {
                $chunk = $this->chunks[$index];

                if ($chunk['isTagElement'] || $chunk['isCodeBlock'] || $chunk['isInlineCode']) {
                    $this->currentLine .= "\016{" . $index . "}\016";
                } else {
                    $this->currentLine .= $chunk['content'];
                }
            }

            // \Debug::Log($this->currentLine);
        }

        $this->nextChunk = ($this->currentChunkIndex < $this->chunksCount - 1) ? $this->chunks[$this->currentChunkIndex + 1] : null;

    }

    public function JumpToNextLineChunk()
    {

        $iterateCount = $this->nextLineChunkIndex === -1 ? $this->chunksCount - $this->currentChunkIndex : $this->nextLineChunkIndex - $this->currentChunkIndex;

        for ($cnt = 0; $cnt < $iterateCount; $cnt++) {
            $this->IterateChunk();
        }

        // \Debug::LogError($this->currentChunk['content']);
    }

    public function JumpToEndOfLineChunk()
    {

        $iterateCount = $this->nextLineChunkIndex === -1 ? $this->chunksCount - $this->currentChunkIndex : $this->nextLineChunkIndex - $this->currentChunkIndex;
        $iterateCount--;

        for ($cnt = 0; $cnt < $iterateCount; $cnt++) {
            $this->IterateChunk();
        }

        // \Debug::LogError($this->currentChunk['content']);
    }

    public function AddReference($key)
    {
        if (!array_key_exists($key, $this->referenceMap)) {
            $this->referenceMap[$key] = ["index" => -1, "content" => ""];
        }

        if ($this->referenceMap[$key]["index"] == -1) {
            $this->referenceMap[$key]["index"] = ++$this->referenceCount;
        }

        return $this->referenceMap[$key]["index"];
    }

    public function SetReference($key, $content)
    {
        if (!array_key_exists($key, $this->referenceMap)) {
            $this->referenceMap[$key] = ["index" => -1, "content" => ""];
        }

        $this->referenceMap[$key]["content"] = $content;

    }

    public function ReferenceList()
    {
        $list = [];

        foreach ($this->referenceMap as $key => $value) {
            if ($value["index"] != -1) {
                $list[$value["index"]] = ["content" => $value["content"], "key" => $key];
            }
        }
        //ksort($list);

        return $list;
    }
}

class Parser
{
    // === Parser Configuration ======================================
    private static $indentSpace = 4;

    private static $htmlTagList = [
        'li', 'dt', 'dd', 'p', 'tr', 'td', 'th', 'rt', 'rp', 'optgroup',
        'option', 'thead', 'tfoot',

        'tbody', 'colgroup',

        'script', 'noscript',

        'pre', 'ol', 'ul', 'dl', 'figure', 'figcaption', 'div',

        'a', 'em', 'strong', 'small', 's', 'cite', 'q', 'i', 'b', 'span',

        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',

        'table', 'caption',

        'form', 'button', 'textarea', 'mark',

        'code',

    ];

    private static $specialCharacterEscapeExclusionPattern =
        "/(<br *?>)|(<br *\/?>)|(<img.*?>)|(<img.*?\/>)|(<hr *?>)|(<hr *\/?>)|(<input.*?>)|(<input.*?\/>)/";

    private static $commentStartToken = '<!--';
    private static $commentEndToken = '-->';

    private static $onResetParserList = [
        'HeadingElementParser',
        'TableElementParser',
        'ListElementParser',
        'ParagraphElementParser',
        'BoxElementParser',
        'ReferenceListParser',
    ];

    private static $onPreBeginLineParserList = [
        'HeadingElementParser',
        'BoxElementParser',
        'TableElementParser',
    ];

    private static $onBeginLineParserList = [
        'BoxElementParser',
        'HeadingElementParser',
        'HorizontalLineElementParser',
        'ListElementParser',
        'FigureElementParser',
        'ReferenceListParser',
        'TableElementParser',
        'ParagraphElementParser',
    ];

    private static $onEmptyLineParserList = [
        'HeadingElementParser',
        'ParagraphElementParser',
        'ListElementParser',
        'TableElementParser',
        'ReferenceListParser',
    ];

    private static $onIndentParserList = [
        'ListElementParser',
        'ParagraphElementParser',
        'SectionElementParser',
    ];

    private static $onUnindentParserList = [
        'ListElementParser',
        'ParagraphElementParser',
        'SectionElementParser',
    ];

    private static $onUnchangedIndentParserList = [

    ];

    private static $spanElementPatternTable = [
        ["/\[(.*?)\]\((.*?)\)/", "<a href='{1}'>{0}</a>", null],
        ["/\*\*(.*?)\*\*/", "<strong>{0}</strong>", null],
        ["/\/\/(.*?)\/\//", "<em>{0}</em>", null],
        ["/__(.*?)__/", "<mark>{0}</mark>", null],
        ["/~~(.*?)~~/", "<del>{0}</del>", null],
        ["/\\\\\[(.*?)\]/", null, "DecodeReferenceElementCallbackFunction"],
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

    public static $onResetParserFuncList = [];
    public static $onPreBeginLineParserFuncList = [];
    public static $onBeginLineParserFuncList = [];
    public static $onEmptyLineParserFuncList = [];
    public static $onIndentParserFuncList = [];
    public static $onUnindentParserFuncList = [];
    public static $onUnchangedIndentParserFuncList = [];

    private static $blockSeparators;
    private static $patternTagBlockStartTag;
    private static $patternTagBlockEndTag;

    private static $isInitialized = false;

    public static function Init()
    {
        if (static::$isInitialized) {
            return;
        }

        // ブロックごとに区切るためのpattern
        static::$blockSeparators = '/';
        $blockTagCount = count(static::$htmlTagList);
        for ($i = 0; $i < $blockTagCount; $i++) {
            static::$blockSeparators .= '(<' . static::$htmlTagList[$i] . '\b.*?>)|(<\/' . static::$htmlTagList[$i] . ' *?>)';
            if ($i < $blockTagCount - 1) {
                static::$blockSeparators .= '|';
            }
        }

        static::$blockSeparators .= '|(`)';

        static::$blockSeparators .= '/i';

        static::$patternTagBlockStartTag = '/';
        $blockTagCount = count(static::$htmlTagList);
        for ($i = 0; $i < $blockTagCount; $i++) {
            static::$patternTagBlockStartTag .= '(<' . static::$htmlTagList[$i] . '\b.*?>)';
            if ($i < $blockTagCount - 1) {
                static::$patternTagBlockStartTag .= '|';
            }
        }

        static::$patternTagBlockStartTag .= '/i';

        static::$patternTagBlockEndTag = '/';
        $blockTagCount = count(static::$htmlTagList);
        for ($i = 0; $i < $blockTagCount; $i++) {
            static::$patternTagBlockEndTag .= '(<\/' . static::$htmlTagList[$i] . ' *?>)';
            if ($i < $blockTagCount - 1) {
                static::$patternTagBlockEndTag .= '|';
            }
        }

        static::$patternTagBlockEndTag .= '/i';

        foreach (static::$onBeginLineParserList as $parser) {
            static::$onBeginLineParserFuncList[] = ['OutlineText\\' . $parser, 'OnBeginLine'];
        }
        foreach (static::$onPreBeginLineParserList as $parser) {
            static::$onPreBeginLineParserFuncList[] = ['OutlineText\\' . $parser, 'OnPreBeginLine'];
        }
        foreach (static::$onEmptyLineParserList as $parser) {
            static::$onEmptyLineParserFuncList[] = ['OutlineText\\' . $parser, 'OnEmptyLine'];
        }
        foreach (static::$onResetParserList as $parser) {
            static::$onResetParserFuncList[] = ['OutlineText\\' . $parser, 'OnReset'];
        }
        foreach (static::$onIndentParserList as $parser) {
            static::$onIndentParserFuncList[] = ['OutlineText\\' . $parser, 'OnIndent'];
        }
        foreach (static::$onUnindentParserList as $parser) {
            static::$onUnindentParserFuncList[] = ['OutlineText\\' . $parser, 'OnUnindent'];
        }
        foreach (static::$onUnchangedIndentParserList as $parser) {
            static::$onUnchangedIndentParserFuncList[] = ['OutlineText\\' . $parser, 'OnUnchangedIndent'];
        }

        static::$isInitialized = true;
    }

    //
    // 処理の流れは以下の通り.
    //  1. Chunkに分ける
    //  2. Chunkごとにデコード処理を行う.
    //
    public static function Parse($plainText, &$context = null)
    {
        if (!static::$isInitialized) {
            static::Init();
        }

        //\Debug::Log($plainText);

        $output = '';

        // 前処理
        // 空行を追加する.
        // ファイルの終わりの処理と空行の処理を一緒にする.
        $plainText .= "\n";

        // 文脈用意
        if (is_null($context)) {
            $context = new Context();
        }

        // チャンクにわける
        $context->SetChunks(static::SplitToChunk($plainText));

        foreach (static::$onResetParserFuncList as $func) {
            call_user_func($func);
        }

        // --- 各チャンクごとに対して --------------------------------
        for (;!$context->IsEndOfChunk(); $context->IterateChunk()) {

            $currentChunk = $context->CurrentChunk();

            $out = '';
            if (static::DecodeExceptElements($currentChunk, $out)) {
                $output .= $out;
                continue;
            } elseif ($currentChunk['isEmptyLine']) {
                static::DoEmptyLine($context, $out);
                $output .= $out;
                continue;
            }

            // 文中
            elseif ($currentChunk["indentLevel"] == -1) {
                $output .= static::DecodeSpanElements($currentChunk["content"], $context);
                continue;
            }

            //
            // ここから下の処理対象は, 各行の先頭にあるチャンクであることが保証されている.
            //

            if ($context->skipNextLineChunk) {

                $context->skipNextLineChunk = false;

                continue;
            }

            // 行頭の前処理
            $output .= static::CallbackEventFuncs(static::$onPreBeginLineParserFuncList, $context);

            //
            // --- インデントレベルの変化を見る ----------------------
            $context->indentLevel = $currentChunk["indentLevel"];

            // 右へインデント
            if ($context->indentLevel > $context->indentLevelPrevious) {
                $levelDiff = $context->indentLevel - $context->indentLevelPrevious;

                while ($levelDiff > 0) {

                    $output .= static::CallbackEventFuncs(static::$onIndentParserFuncList, $context);

                    $levelDiff--;
                }

            }

            // 左へインデント
            if ($context->indentLevel < $context->indentLevelPrevious) {
                $levelDiff = $context->indentLevelPrevious - $context->indentLevel;

                while ($levelDiff > 0) {

                    $output .= static::CallbackEventFuncs(static::$onUnindentParserFuncList, $context);

                    $levelDiff--;
                }
            }

            // インデントそのまま
            if ($context->indentLevel == $context->indentLevelPrevious) {

                $output .= static::CallbackEventFuncs(static::$onUnchangedIndentParserFuncList, $context);

            }

            $context->indentLevelPrevious = $context->indentLevel;

            // End インデントの変化を見る ---

            // 空文字の時
            // インデント値はあるが, 空文字
            // その次がインラインコード, html要素のときに起こる.
            if ($currentChunk["content"] == "") {

                // 次がインラインコードのときは, このまま処理を続ける.
                // 次がインラインコードのとき, このまま処理を続けないと,
                // <p></p>で囲まれない.
                //
                // 逆にその次が, html要素のときは, <p></p>で囲まれてしまい,
                // <p></p>で囲めない要素が来た時によろしくない.
                if (($context->NextChunk() !== null) && $context->NextChunk()["isInlineCode"]) {

                } else {
                    // 次がhtml要素などはこのまま処理を続けない.
                    // \Debug::Log('asad');
                    continue;
                }
                //continue;
            }

            // 行頭の処理
            $output .= static::CallbackEventFuncs(static::$onBeginLineParserFuncList, $context);

        } // End 各チャンクごとに対して ----

        // すべてのチャンクの処理を終えた場合

        while ($context->indentLevelPrevious >= 0) {

            $output .= static::CallbackEventFuncs(static::$onUnindentParserFuncList, $context);

            $context->indentLevelPrevious--;
        }

        //Debug::Log($output);

        return $output;

    }

    private static function CallbackEventFuncs($funcs, $context)
    {
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

    public static function DoEmptyLine($context, &$output)
    {
        $output = static::CallbackEventFuncs(static::$onEmptyLineParserFuncList, $context);
    }

    // OutlineTextの文法外要素
    // InlineCode, CodeBlock, HTMLElements
    // が対象.
    private static function DecodeExceptElements($chunk, &$output)
    {

        if ($chunk['isTagElement']) {
            $output = $chunk["content"];
            return true;
        } elseif ($chunk['isCodeBlock']) {

            if ($chunk["codeBlockAttribute"] == "math") {
                $output = "<div>" .
                static::EscapeSpecialCharactersForce($chunk["content"]) .
                    "</div>";
                return true;
            } else {
                $output = "<pre class='brush: " . $chunk["codeBlockAttribute"] . ";'>" .
                static::EscapeSpecialCharactersForce($chunk["content"]) .
                    "</pre>";
                return true;
            }
        } elseif ($chunk['isInlineCode']) {
            $output = "<code>" . static::EscapeSpecialCharactersForce($chunk["content"]) . "</code>";
            return true;
        }

        return false;
    }

    public static function DecodeSpanElements($text, $context)
    {

        $spanElementPatternTableCount = count(static::$spanElementPatternTable);

        // --- マッチ情報の初期化 ------------------------------------
        $patternMatchInfos = array();

        for ($i = 0; $i < $spanElementPatternTableCount; $i++) {

            $patternMatchInfos[] = ["matches" => array(), "iteratorIndex" => 0, "matchedCount" => 0];

        }

        // end マッチ情報の初期化 ---

        // パターンのマッチ
        for ($i = 0; $i < $spanElementPatternTableCount; $i++) {
            preg_match_all(static::$spanElementPatternTable[$i][0], $text, $patternMatchInfos[$i]["matches"], PREG_OFFSET_CAPTURE);
            $patternMatchInfos[$i]["matchedCount"] = count($patternMatchInfos[$i]["matches"][0]);
        }

        //var_dump($patternMatchInfos);

        $currentPosition = 0;
        $output = "";

        for (;;) {

            // マッチしたパターンのうちパターン始まり位置が若いのを選ぶ
            $focusedPatternIndex = -1;
            for ($i = 0; $i < $spanElementPatternTableCount; $i++) {

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
                preg_match_all(static::$spanElementPatternTable[$focusedPatternIndex][0], $text, $patternMatchInfos[$focusedPatternIndex]["matches"], PREG_OFFSET_CAPTURE, $currentPosition);
                $patternMatchInfos[$focusedPatternIndex]["matchedCount"] = count($patternMatchInfos[$focusedPatternIndex]["matches"][0]);
                $patternMatchInfos[$focusedPatternIndex]["iteratorIndex"] = 0;
                continue;
            }

            $focusedPatternString = $patternMatchInfos[$focusedPatternIndex]["matches"][0][$focusedPatternIteratorIndex][0];

            //echo $focusedPatternStartPosition;

            // パターン前の文字列を出力
            $output .= static::EscapeSpecialCharacters(substr($text, $currentPosition, $focusedPatternStartPosition - $currentPosition));

            $spanString = "";

            if (static::$spanElementPatternTable[$focusedPatternIndex][1] != null) {
                $spanString = static::$spanElementPatternTable[$focusedPatternIndex][1];
                $capturedCount = count($patternMatchInfos[$focusedPatternIndex]["matches"]) - 1;
                for ($i = 0; $i < $capturedCount; $i++) {
                    $spanString = str_replace("{" . ($i) . "}", $patternMatchInfos[$focusedPatternIndex]["matches"][$i + 1][$focusedPatternIteratorIndex][0], $spanString);

                }
            }

            if (static::$spanElementPatternTable[$focusedPatternIndex][2] != null) {
                $matchCount = count($patternMatchInfos[$focusedPatternIndex]["matches"]);
                $matches = [];

                for ($i = 0; $i < $matchCount; $i++) {
                    $matches[] = $patternMatchInfos[$focusedPatternIndex]["matches"][$i][$focusedPatternIteratorIndex];

                }
                $spanString .= call_user_func(["OutlineText\Parser", static::$spanElementPatternTable[$focusedPatternIndex][2]], $matches, $context);

            }

            $output .= $spanString;

            $currentPosition = $focusedPatternStartPosition + strlen($focusedPatternString);

            $patternMatchInfos[$focusedPatternIndex]["iteratorIndex"]++;
            //break;
        }

        $output .= static::EscapeSpecialCharacters(substr($text, $currentPosition));

        $blocks = preg_split("/(\016{[0-9]+}\016)/", $output, -1, PREG_SPLIT_DELIM_CAPTURE);
        $blocksCount = count($blocks);
        for ($index = 0; $index < $blocksCount; $index++) {
            if (preg_match("/(\016{[0-9]+}\016)/", $blocks[$index])) {
                $chunkIndex = intval(substr($blocks[$index], 2, -2));
                // \Debug::LogError($chunkIndex);

                $chunk = $context->chunks[$chunkIndex];

                static::DecodeExceptElements($chunk, $blocks[$index]);

            }
        }
        // var_dump($blocks);

        return implode($blocks);

    }

    private static function DecodeReferenceElementCallbackFunction($matches, $context)
    {
        //\var_dump($matches);
        //\Debug::Log("as");
        $key = $matches[1][0];
        $index = $context->AddReference($key);

        return "<sup class='reference'><a href='#ref-" . $key . "'>[" . $index . "]</a></sup>";
        //\Debug::Log($key);
    }

    private static function EscapeSpecialCharacters($text)
    {

        $blocks = preg_split(static::$specialCharacterEscapeExclusionPattern, $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        //var_dump($blocks);

        $blocksCount = count($blocks);

        for ($i = 0; $i < $blocksCount; $i++) {
            if (!preg_match(static::$specialCharacterEscapeExclusionPattern, $blocks[$i])) {

                $blocks[$i] = static::EscapeSpecialCharactersForce($blocks[$i]);
            }
        }

        return implode($blocks);

    }

    private static function EscapeSpecialCharactersForce($text)
    {

        $text = str_replace('&', '&amp;', $text);
        $text = str_replace('<', '&lt;', $text);
        $text = str_replace('>', '&gt;', $text);

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
    // ExceptElementsにはインデント値を含めないこと.
    //
    private static function SplitToChunk($plainText)
    {

        $chunkList = array();
        $chunk = Context::CreateChunk();

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
                //echo "em";
            }

            $indentLevel = ($spaceCount - $startSpaceCount) / static::$indentSpace;
            //echo $startSpaceCount;

            // End indentLevelの計算 ------------------------

            // 現在コードブロック内のとき
            if ($isInCodeBlock) {

                // コードブロックから出る
                if ($codeBlockIndentLevel == $indentLevel && preg_match("/^ *```(.*)/", $lines[$i], $matches) === 1) {
                    $isInCodeBlock = false;

                    $chunkIndex++;
                    $chunkList[] = $chunk;

                    $chunk = Context::CreateChunk();

                    continue;
                }

                // コードブロック内の処理
                else {

                    $chunk["content"] .= $lines[$i] . "\n";

                    continue;
                }

            }

            // 現在コードブロックに入っていないとき
            else {

                // コードブロック内に入る
                if ($tagBlockLevel <= 0 && preg_match("/^ *```(.*)/", $lines[$i], $matches) === 1) {
                    $isInCodeBlock = true;
                    $codeBlockIndentLevel = $indentLevel;

                    $chunk["indentLevel"] = $indentLevel;
                    $chunk["spaceCount"] = $spaceCount;

                    $chunkIndex++;

                    $chunkList[] = $chunk;
                    $chunk = Context::CreateChunk();

                    $chunk["isCodeBlock"] = true;
                    $chunk["codeBlockAttribute"] = $matches[1];

                    continue;
                }
            }

            // 空白行のとき
            if ($isEmpty) {
                if ($tagBlockLevel > 0) {
                    $chunk["content"] .= "\n";
                }

                // タグブロック内ではない
                else {
                    $chunk["nextLineChunkIndex"] = $chunkIndex + 1;
                    $chunk["isEmptyLine"] = true;

                    $chunkList[] = $chunk;

                    $chunkIndex++;
                    $chunk = Context::CreateChunk();

                    $lineStartChunkIndex = $chunkIndex;

                }

                continue;
            }

            if ($tagBlockLevel <= 0) {
                $chunk["indentLevel"] = $indentLevel;
                $chunk["spaceCount"] = $spaceCount;
            }

            $blocks = preg_split(static::$blockSeparators, $lines[$i], -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            //var_dump($blocks);
            $blockCount = count($blocks);

            $beginInlineCode = false;

            // --- ブロックごとの処理 ----
            for ($j = 0; $j < $blockCount; $j++) {

                //echo $blocks[$j] . "\n";

                if ($beginInlineCode) {

                    // インラインコードから抜ける
                    if ($blocks[$j] == "`") {
                        $beginInlineCode = false;

                        $chunkIndex++;
                        $chunkList[] = $chunk;
                        //var_dump($chunk);

                        $chunk = Context::CreateChunk();

                        continue;
                    }

                    // インラインコード内
                    else {
                        $chunk["content"] .= $blocks[$j];
                        //echo $blocks[$j];
                        continue;
                    }

                } else {

                    // インラインコードに入る
                    if ($tagBlockLevel <= 0 && $blocks[$j] == "`") {
                        $chunkIndex++;

                        $chunkList[] = $chunk;
                        $chunk = Context::CreateChunk();

                        $beginInlineCode = true;

                        $chunk["isInlineCode"] = true;

                        //echo "34y";

                        continue;
                    }
                }

                if (preg_match(static::$patternTagBlockStartTag, $blocks[$j]) === 1) {

                    $tagBlockLevel++;
                }

                if (preg_match(static::$patternTagBlockEndTag, $blocks[$j]) === 1) {

                    $tagBlockLevel--;
                }

                if ($tagBlockLevel != $tagBlockLevelPrevious) {

                    // タグブロック内に入った
                    if ($tagBlockLevel > 0 && $tagBlockLevelPrevious <= 0) {
                        $chunkIndex++;

                        $chunkList[] = $chunk;
                        $chunk = Context::CreateChunk();
                        $chunk["isTagElement"] = true;

                        $chunk["content"] .= $blocks[$j];
                    }

                    //　タグブロックから出た
                    elseif ($tagBlockLevel <= 0 && $tagBlockLevelPrevious > 0) {
                        $chunkIndex++;

                        $chunk["content"] .= $blocks[$j];

                        $chunkList[] = $chunk;
                        $chunk = Context::CreateChunk();
                        $chunk["isTagElement"] = false;

                    }

                    // タグブロック内での変化
                    else {

                        $chunk["content"] .= $blocks[$j];
                    }

                    $tagBlockLevelPrevious = $tagBlockLevel;
                }

                // タグブロックの変化がない
                else {
                    if ($tagBlockLevel <= 0) {
                        if ($j == 0) {
                            $blocks[$j] = substr($blocks[$j], $spaceCount);
                        }

                        if ($j == $blockCount - 1) {
                            $blocks[$j] = static::RemoveTailSpaces($blocks[$j]);
                        }
                        $chunk["content"] .= $blocks[$j];
                    } else {

                        $chunk["content"] .= $blocks[$j];
                    }
                }

            } // End ブロックのごとの処理 ---

            // 行の終わり & タグブロック内ではないとき
            if ($tagBlockLevel <= 0) {
                $chunkIndex++;
                $chunkList[] = $chunk;

                for ($j = $lineStartChunkIndex; $j < $chunkIndex; $j++) {
                    $chunkList[$j]["nextLineChunkIndex"] = $chunkIndex;
                }
                $lineStartChunkIndex = $chunkIndex;

                // $chunkIndex++;

                // $chunkList[] = $chunk;
                $chunk = Context::CreateChunk();

            }

            // 行の終わり & タグブロック内のとき
            else {
                $chunk["content"] .= "\n";
            }
        } // End 各行ごとの処理 ---

        $chunkList[] = $chunk;

        return $chunkList;
    }

    private static function RemoveHeadSpaces($line)
    {
        $wordCount = strlen($line);
        $spaceCount = 0;

        for ($i = 0; $i < $wordCount; $i++) {
            if ($line[$i] != ' ') {
                break;
            }
            $spaceCount++;
        }

        return substr($line, $spaceCount);
    }

    private static function RemoveTailSpaces($line)
    {
        $wordCount = strlen($line);
        $spaceCount = 0;

        for ($i = $wordCount - 1; $i >= 0; $i--) {
            if ($line[$i] != ' ') {
                break;
            }
            $spaceCount++;
        }

        return substr($line, 0, $wordCount - $spaceCount);
    }

} // End class Parser
