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

    /**
     * ./Master/Contents
     */
    public static $currentRootDirectory='';
    public static $currentDirectory='';
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
        static::$currentRootDirectory = ContentsDatabaseManager::GetRootContentsFolder($contentPath);
        static::$currentDirectory = dirname($contentPath);
        return OutlineText\Parser::Parse($text, $context);
    }

    public static function CreateContext($contentPath) {
        $context = new OutlineText\Context();
        $context->pathMacros = static::CreatePathMacros($contentPath);
        return $context;
    }

    public static function ParseContentLink($matches, $context) {
        $path = $matches[1][0];
        $contentPath = '';
        if(strpos($path, '/') === 0){
            // To navigate from the root directory
            $contentPath = static::$currentRootDirectory . $path;
        }
        else {
            // To navigate from the current directory
            $contentPath = static::$currentDirectory . '/' . $path;
        }
        // Debug::Log($contentPath);
        $content = new Content();
        if(!$content->SetContent($contentPath)) {
            // if not exists, return the text that matched the full pattern.
            return $matches[0][0];
        }

        if(strpos($content->path, static::$currentRootDirectory . '/') !== 0){
            // not start with current root directory.
            // Debug::Log('Permission denied.');
            return $matches[0][0];
        }
        
        if(!array_key_exists($content->path, static::$contentLinks)) {
            static::$contentLinks[$content->path] = true;
        }
        $title = '';
        $parent = $content->Parent();
        if($parent !== false) {
            $title .= NotBlankText([$parent->title, basename($parent->path)]) . '/';
        }
        $title .= NotBlankText([$content->title, basename($content->path)]);
        $href = CreateContentHREF($content->path);
        return '<a href="' . $href .'">[' . $title . ']</a>';
    }

    public static function CreatePathMacros($contentPath) {
        return [
            ['CURRENT_DIR', 'ROOT_URI'],
            [ROOT_URI . Path2URI(dirname($contentPath)), ROOT_URI]
        ];
    }
}