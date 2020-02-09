<?php

/**
 * 参照するグローバル変数
 *  ROOT_URI
 */
require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/Authenticator.php";
require_once dirname(__FILE__) . "/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/OutlineText.php";
require_once dirname(__FILE__) . "/CacheManager.php";
require_once dirname(__FILE__) . "/Utils.php";


function CreateContentHREF($contentPath) {
    return ROOT_URI . Path2URI($contentPath);
}

/**
 * ex)
 *  /CollabCMS/Master/TagList?name=Root
 * 
 * @param string $rootDirectory
 *  ex) /Master
 */
function CreateTagDetailHREF($tagName, $rootDirectory) {
    return ROOT_URI . $rootDirectory . '/TagList?name=' . urlencode($tagName);
}

function CreateTagNavigator($tag2path, $selectedTagName, $rootDirectory) {
    ksort($tag2path);
    $navigator = "<nav class='navi'><ul>";
    foreach ($tag2path as $name => $pathList) {
        $selectedStr = '';
        if ($name == $selectedTagName) {
            $selectedStr = ' class="selected" ';
        }
        $navigator .= '<li><a href="' . CreateTagDetailHREF($name, $rootDirectory) . '"' . $selectedStr . '>' . $name . '</a></li>';
    }
    $navigator .= '</ul></nav>';

    return $navigator;
}

/**
 * @param array $latestContents
 *  array of Content
 */
function CreateNewBox($latestContents) {
    $newBoxElement = "<div class='new-box'><ol class='new-list'>";

    $displayCount = count($latestContents);
    if($displayCount > 16) $displayCount = 16;

    for($i = 0; $i < $displayCount; $i++){
        $content = $latestContents[$i];
        $parent = $content->Parent();
        $title = "[" . date("Y-m-d", $content->modifiedTime) . "] " . NotBlankTitle($content->title) .
                    ($parent === false ? '' : ' | ' . NotBlankTitle($parent->title));
        $newBoxElement .= "<li><a href='" . CreateContentHREF($content->path) . "'>" . $title . "</a></li>";
    }

    $newBoxElement .= "</ol></div>";
    return $newBoxElement;
}

function CreateTagListElement($tag2path, $rootDirectory) {
    ksort($tag2path);
    $listElement = '<ul class="tag-list">';

    foreach ($tag2path as $name => $pathList) {
        $listElement .= '<li><a href="' . CreateTagDetailHREF($name, $rootDirectory) . '">' . $name . '<span>' . count($pathList) . '</span></a></li>';
    }
    $listElement .= '</ul>';

    return $listElement;
}

function CreateHeaderArea($rootContentPath, $showRootChildren, $showPrivateIcon) {
    $rootDirectory = substr(GetTopDirectory($rootContentPath), 1); // 最初の'.'は除く

    $header = '
            <header id="header">
                <div class="logo"><a href="' . CreateContentHREF($rootContentPath) . '">ContentsViewer</a></div>
                <button id="search-button" onclick="OnClickSearchButton()" aria-label="検索"><div class="search-icon"><div class="circle"></div><div class="rectangle"></div></div></button>
                <button id="pull-down-menu-button" class="pull-updown-button" onclick="OnClickPullDownButton()" aria-haspopup="true" aria-controls="pull-down-menu"><div class="pull-down-icon"></div></button>
                <button id="pull-up-menu-button" class="pull-updown-button" onclick="OnClickPullUpButton()" aria-haspopup="true" aria-controls="pull-down-menu"><div class="pull-up-icon"></div></button>
                <div id="pull-down-menu" class="pull-down-menu" aria-hidden="true">
                <nav class="pull-down-menu-top">
                    <a class="header-link-button" href="' . CreateContentHREF($rootContentPath) . '">フロントページ</a>
                    <a class="header-link-button" href="' . CreateTagDetailHREF('',$rootDirectory) . '">タグ一覧</a>
                </nav>
                <nav class="pull-down-menu-content">
            ';
    if($showRootChildren){
        $rootContent = new Content();
        $rootContent->SetContent($rootContentPath);
        if($rootContent !== false){
            $childrenPathList = $rootContent->childPathList;
            $childrenPathListCount = count($childrenPathList);

            for ($i = 0; $i < $childrenPathListCount; $i++) {
                $child = $rootContent->Child($i);
                if ($child !== false) {
                    $header .= '<a class="header-link-button" href="' . CreateContentHREF($child->path) . '">' . NotBlankTitle($child->title) .'</a>';
                }
            }
        }
    }
    
    $header .= '</nav><div class="toolbar"><button class="icon adjust-icon" title="テーマ切り替え" onclick="OnClickThemeChangeButton()"></button></div></div>';
    if($showPrivateIcon){
        $header .= '<div class="icon private-icon" title="非公開のコンテンツ"></div>';
    }

    $header .= '</header>';
    return $header;
}

function CreateSearchOverlay(){
    return "
    <div id='search-overlay'>
        <div class='overlay-mask'></div>
        <div class='overlay-header'>
            <form class='search-box' onsubmit='document.activeElement.blur(); return false;'>
                <input id='search-box-input' type='search' autocomplete='off' placeholder='ContentsViewer内を検索' aria-label='ContentsViewer内を検索' oninput='OnInputSearchBox()'>
                <button id='search-box-input-clear-button' type='button' class='clear' onclick='OnClickSearchBoxInputClearButton()' aria-label='消去'><div class='icon clear-icon'></div></button>
            </form>
            <button id='header-close-button' onclick='OnClickSearchOverlayCloseButton()' aria-label='閉じる'>
                <div class='close-icon'><span class='lines line-1'></span><span class='lines line-2'></span></div>
            </button>
        </div>
        <div class='overlay-content'>
            <div class='search-results-view'>
                <div id='search-results' class='results'>
                </div>
            </div>
        </div>
    </div>";
}

function CreatePageHeading($title, $parents) {
    $heading = '<div id="page-heading">';

    $parentsCount = count($parents);
    if($parentsCount > 0){
        //親コンテンツ
        $heading .= '<ul class="breadcrumb" itemscope itemtype="http://schema.org/BreadcrumbList">';

        for ($i = $parentsCount - 1; $i >= 0; $i--) {
            $heading .= 
                '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">' .
                '<a href ="' . $parents[$i]['path'] . '" itemprop="item">' .
                '<span itemprop="name">' . $parents[$i]['title'] . '</span></a>' .
                '<meta itemprop="position" content="' . ($parentsCount - $i) .'" /></li>';
        }
        $heading .= '</ul>';
    }
    
    //タイトル欄
    $heading .= '<h1 id="first-heading">' . $title . '</h1>';

    $heading .= '</div>';
    return $heading;
}

function GetMessages($contentPath) {
    $rootContentsFolder = ContentsDatabaseManager::GetRootContentsFolder($contentPath);
    $messageContent = new Content();
    $messageContent->SetContent($rootContentsFolder . '/Messages');
    if($messageContent === false)
        return [];

    $body = trim($messageContent->body);
    $body = str_replace("\r", "", $body);
    $lines = explode("\n", $body);
    $messages = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if(substr($line, 0, 2) != '//' && $line != ''){
            $messages[] = $line;
        }
    }
    // Debug::Log(count($messages));
    return $messages;
}

function GetTip() {
    $tipsContent = new Content();

    $tipsContent->SetContent(DEFAULT_CONTENTS_FOLDER . '/Tips');

    if($tipsContent === false)
        return "";
    
    $body = trim($tipsContent->body);
    $body = str_replace("\r", "", $body);
    $tips = explode("\n", $body);

    $tipsCount = count($tips);
    if($tipsCount <= 0){
        return "";
    }

    return $tips[rand(0, $tipsCount - 1)];
}

function GetTextHead($text, $wordCount) {
    return mb_substr($text, 0, $wordCount) . (mb_strlen($text) > $wordCount ? '...' : '');
}

/**
 * @return array array['summary'], array['body']
 */
function GetDecodedText($content) {
    OutlineText\Parser::Init();

    // キャッシュの読み込み
    $cache = new Cache;
    $cache->Connect($content->path);
    
    $cache->Lock(LOCK_SH);
    $cache->Fetch();
    $cache->Unlock();

    if (
        is_null($cache->data) || 
        !array_key_exists('text', $cache->data) ||
        !array_key_exists('textUpdatedTime', $cache->data) ||
        ($cache->data['textUpdatedTime'] < $content->modifiedTime)
    ) {
        
        $text = [];
        $context = new OutlineText\Context();
        $context->pathMacros = ContentsDatabaseManager::CreatePathMacros($content->path);

        $text['summary'] = OutlineText\Parser::Parse($content->summary, $context);
        $text['body'] = OutlineText\Parser::Parse($content->body, $context);

        $cache->data['text'] = $text;
        
        // 読み込み時の時間を使う
        // 読み込んでからの変更を逃さないため
        $cache->data['textUpdatedTime'] = $content->OpenedTime();
        
        $cache->Lock(LOCK_EX);
        $cache->Apply();
        $cache->Unlock();
    }

    $cache->Disconnect();
    
    return $cache->data['text'];
}

function NotBlankTitle($title){
    return $title === '' ? 'No Title' : $title;
}