<?php

require_once dirname(__FILE__) . "/Debug.php";
require_once dirname(__FILE__) . "/OutlineText.php";
require_once dirname(__FILE__) . "/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/ContentsViewerUtils.php";
require_once dirname(__FILE__) . "/Utils.php";

/**
 * Content記法拡張
 */
class ContentTextParser {
    /**
     * [
     *  'path' => true, 'path' => true
     * ]
     */
    public static $contentLinks = [];
    public static $currentRootDirectory='';
    public static $isInitialized = false;

    public static function Init() {
        if(static::$isInitialized) return;

        OutlineText\Parser::$inlineElementPatternTable[] = [
            "/\[(.*?)\]/", null, ['ContentTextParser', 'ParseContentLink']
        ];
        OutlineText\Parser::Init();
        static::$isInitialized = true;
    }

    public static function Parse($text, $contentPath, &$context) {
        if(!static::$isInitialized) static::Init();
        static::$currentRootDirectory = substr(GetTopDirectory($contentPath), 1);
        return OutlineText\Parser::Parse($text, $context);
    }

    public static function CreateContext($contentPath) {
        $context = new OutlineText\Context();
        $context->pathMacros = static::CreatePathMacros($contentPath);
        return $context;
    }

    public static function ParseContentLink($matches, $context) {
        $contentPath = URI2Path(static::$currentRootDirectory . '/' . $matches[1][0]);
        $content = new Content();
        if(!$content->SetContent($contentPath)) {
            // if not exists, return the text that matched the full pattern.
            return $matches[0][0];
        }
        if(!array_key_exists($content->path, static::$contentLinks)) {
            static::$contentLinks[$content->path] = true;
        }
        $title = '';
        $parent = $content->Parent();
        if($parent !== false) {
            $title .= $parent->title . '-';
        }
        $title .= $content->title;
        $href = CreateContentHREF($content->path);
        return '<a href="' . $href .'">' . $title . '</a>';
    }

    public static function CreatePathMacros($contentPath) {
        return [
            ['CURRENT_DIR', 'ROOT_URI'],
            [ROOT_URI . Path2URI(dirname($contentPath)), ROOT_URI]
        ];
    }
}