<?php

/**
 * 参照するグローバル変数
 *  ROOT_URI
 */
require_once dirname(__FILE__) . "/../LinkageCMS.php";
require_once dirname(__FILE__) . "/Authenticator.php";
require_once dirname(__FILE__) . "/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/ContentTextParser.php";
require_once dirname(__FILE__) . "/CacheManager.php";
require_once dirname(__FILE__) . "/Utils.php";
require_once dirname(__FILE__) . "/Localization.php";


/**
 * './Master/Contents/Root' -> '{ROOT_URI}/Master/Root'
 * './Master/Contents/Root_en' -> '{ROOT_URI}/Master/Root_en'
 * 
 */
function CreateContentHREF($contentPath) {
    return ROOT_URI . Path2URI($contentPath);

    // $pathInfo = ContentsDatabaseManager::GetContentPathInfo(Path2URI($contentPath));
    // $layer = $pathInfo['layername'];
    // if($layer === false){
    //     $layer = DEFAULT_LAYER_NAME;
    // }

    // return ROOT_URI . $pathInfo['dirname'] . '/' . 
    //     $pathInfo['filename'] . implode('.', array_merge([''], $pathInfo['extentions'])) . '?hl=' . $layer;
}

/**
 * ex)
 *  {ROOT_URI}/Master/TagMap/TagA/TagB,TagC/TagD
 * 
 * @param array $tagPathParts
 *  ex) [['TagA'], ['TagB', 'TagC'], ['TagD']]
 * @param string $rootDirectory
 *  ex) /Master
 */
function CreateTagMapHREF($tagPathParts, $rootDirectory, $layerName) {
    $tagPath = '';
    foreach($tagPathParts as $part){
        $tagPath .= '/';
        foreach($part as $tag){
            $tagPath .= urlencode($tag) . ',';
        }
        // 後ろの','を取り除く
        $tagPath = substr($tagPath, 0, -1);
    }

    return ROOT_URI . $rootDirectory . '/TagMap' . $tagPath . '?layer=' . $layerName;
}

/**
 * ex) 
 *  /Master/Contents/Directory -> {ROOT_URI}/Master/Directory?hl={language}
 * 
 * @param string $directoryPath
 */
function CreateDirectoryHREF($directoryPath, $language){
    return ROOT_URI . Path2URI($directoryPath) . '?hl=' . $language;
}

/**
 * ex) 
 *  /Master/Contents/Directory/image.jpg -> <ROOT_URI>/Master/Directory/image.jpg
 */
function CreateFileHREF($filePath){
    return ROOT_URI . Path2URI($filePath);
}

/**
 * @param array $latestContents
 *  array of Content
 */
function CreateRecentList($latestContents) {
    $html = '<div class="recent-list">';
    $html .= '<h3>' . Localization\Localize('recentChanges', 'Recent Changes') . '</h3>';

    $displayCount = count($latestContents);
    if($displayCount > 16) $displayCount = 16;

    /**
     * [
     *  '2020-06-08' => [Content, Content, ...],
     *  ...
     * ]
     */
    $dateGroup = [];
    for($i = 0; $i < $displayCount; $i++) {
        $content = $latestContents[$i];
        $date = date("Y-m-d", $content->modifiedTime);
        if(!array_key_exists($date, $dateGroup)) {
            $dateGroup[$date] = [];
        }
        $dateGroup[$date][] = $content;
    }
    $html .= '<div>';
    foreach($dateGroup as $date => $group) {
        $html .= '<h4>' . $date . '</h4>';
        $html .= '<ul>';
        foreach($group as $content) {
            $title = '';
            $parent = $content->Parent();
            if($parent !== false) {
                $title .= NotBlankText([$parent->title, basename($parent->path)]) . '/';
            }
            $title .= NotBlankText([$content->title, basename($content->path)]);
            $html .= '<li><a href="' . CreateContentHREF($content->path) . '">' . $title . '</a></li>';
        }
        $html .= '</ul>';
    }
    $html .= '</div></div>';
    return $html;
}

function CreateTagListElement($tag2path, $rootDirectory, $layerName, $parentTagPathParts = []) {
    $counter = 0;
    ksort($tag2path);
    $listElement = '<ul class="tag-list">';
    foreach ($tag2path as $name => $pathList) {
        $counter++;
        $listElement .= '<li><a href="' . 
            CreateTagMapHREF(array_merge($parentTagPathParts, [[$name]]), $rootDirectory, $layerName) .
            '">' . $name . '<span>' . count($pathList) . '</span></a></li>';
    }
    $listElement .= '</ul>';

    if($counter <= 0){
        return '<div style="margin-left: 1em;">' . Localization\Localize('noTags', 'There are no Tags.') . '</div>';
    }

    return $listElement;
}

function CreateHeaderArea($rootContentPath, $showRootChildren, $showPrivateIcon) {
    $rootDirectory = substr(GetTopDirectory($rootContentPath), 1); // 最初の'.'は除く
    $layerName = ContentsDatabaseManager::GetRelatedLayerName($rootContentPath);
    if($layerName === false){
        $layerName = DEFAULT_LAYER_NAME;
    }

    $header = 
        '<header id="header">'.
          '<div class="logo"><a href="' . CreateContentHREF($rootContentPath) . '">ContentsViewer</a></div>'.
          '<button id="search-button" onclick="OnClickSearchButton()" aria-label="' . Localization\Localize('search', 'Search') . '"><div class="search-icon"><div class="circle"></div><div class="rectangle"></div></div></button>'.
          '<button id="pull-down-menu-button" class="pull-updown-button" onclick="OnClickPullDownButton(event)" aria-haspopup="true" aria-controls="pull-down-menu"><div class="pull-down-icon"></div></button>'.
          '<button id="pull-up-menu-button" class="pull-updown-button" onclick="OnClickPullUpButton()" aria-haspopup="true" aria-controls="pull-down-menu"><div class="pull-up-icon"></div></button>'.
          '<div id="pull-down-menu" class="pull-down-menu" aria-hidden="true">'.
            '<nav class="pull-down-menu-top">'.
              '<a class="header-link-button" href="' . CreateContentHREF($rootContentPath) . '">' . Localization\Localize('frontpage', 'FrontPage') . '</a>'.
              '<a class="header-link-button" href="' . CreateTagMapHREF([], $rootDirectory, $layerName) . '">' . Localization\Localize('tagmap', 'TagMap') . '</a>'.
            '</nav>'.
            '<nav class="pull-down-menu-content">';

    if($showRootChildren){
        $rootContent = new Content();
        $rootContent->SetContent($rootContentPath);
        if($rootContent !== false){
            $childrenPathList = $rootContent->childPathList;
            $childrenPathListCount = count($childrenPathList);

            for ($i = 0; $i < $childrenPathListCount; $i++) {
                $child = $rootContent->Child($i);
                if ($child !== false) {
                    $header .= '<a class="header-link-button" href="' . CreateContentHREF($child->path) . '">' . NotBlankText([$child->title, basename($child->path)]) .'</a>';
                }
            }
        }
    }
    
    $header .= '</nav>';
    
    $header .= 
        '<div class="toolbar">' . 
        '<button class="icon adjust-icon" title="' . Localization\Localize('changeTheme', 'Change Theme') . '" onclick="OnClickThemeChangeButton()"></button>' .
        '<a class="icon login-icon" href="' . ROOT_URI . '/Login" target="FileManager" title="' . Localization\Localize('login', 'Log in') . '"></a>'. 
        '</div>';

    $header .= '</div>';

    if($showPrivateIcon){
        $header .= '<div class="icon private-icon" title="' . 
            Localization\Localize('privateContent', 'Private Content') . '"></div>';
    }

    $header .= '</header>';
    return $header;
}

function CreateSearchOverlay(){
    return 
    "<div id='search-overlay'>".
        "<div class='overlay-mask'></div>".
        "<div class='overlay-header'>".
            "<form class='search-box' onsubmit='document.activeElement.blur(); return false;'>".
                "<input id='search-box-input' type='search' autocomplete='off' placeholder='" . Localization\Localize('searchContentsViewer', 'Search ContentsViewer') . "' aria-label='" . Localization\Localize('searchContentsViewer', 'Search ContentsViewer') . "' oninput='OnInputSearchBox()'>".
                "<button id='search-box-input-clear-button' type='button' class='clear' onclick='OnClickSearchBoxInputClearButton()' aria-label='" . Localization\Localize('close', 'Close') . "'><div class='icon clear-icon'></div></button>".
            "</form>".
            "<button id='header-close-button' onclick='OnClickSearchOverlayCloseButton()' aria-label='" . Localization\Localize('close', 'Close') . "'>".
                "<div class='close-icon'><span class='lines line-1'></span><span class='lines line-2'></span></div>".
            "</button>".
        "</div>".
        "<div class='overlay-content'>".
            "<div class='search-results-view'>".
                "<div id='search-results' class='results'>".
                "</div>".
            "</div>".
        "</div>".
    "</div>";
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

function CreateBreadcrumbList($items) {
    if(empty($items)){
        return '';
    }

    $html = '<ul class="breadcrumb" itemscope itemtype="http://schema.org/BreadcrumbList">';
    foreach($items as $i => $item) {
        $html .= 
            '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">' .
            '<a href ="' . $item['path'] . '" itemprop="item">' .
            '<span itemprop="name">' . $item['title'] . '</span></a>' .
            '<meta itemprop="position" content="' . ($i + 1) .'" /></li>';
    }
    $html .= '</ul>';
    return $html;
}

function GetMessages($contentPath) {
    $layerName = ContentsDatabaseManager::GetRelatedLayerName($contentPath);
    $layerSuffix = ContentsDatabaseManager::GetLayerSuffix($layerName);
    $rootContentsFolder = ContentsDatabaseManager::GetRootContentsFolder($contentPath);
    $messageContent = new Content();
    $messageContent->SetContent($rootContentsFolder . '/Messages' . $layerSuffix);
    if($messageContent === false){
        return [];
    }

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

    return $messages;
}

function GetTip($layerSuffix) {
    $tipsContent = new Content();

    $tipsContent->SetContent(DEFAULT_CONTENTS_FOLDER . '/Tips' . $layerSuffix);

    if($tipsContent === false){
        return "";
    }
    
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
    ContentTextParser::Init();

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
        $context = ContentTextParser::CreateContext($content->path);
        ContentTextParser::$contentLinks = [];
        $text['summary'] = ContentTextParser::Parse($content->summary, $content->path, $context);
        $text['body'] = ContentTextParser::Parse($content->body, $content->path, $context);
        
        $cache->data['contentLinks'] = ContentTextParser::$contentLinks;
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


function NotBlankText($texts){
    foreach($texts as $text){
        if($text != ''){
            return $text;
        }
    }

    return end($texts);
}

function UpdateLayerNameAndResetLocalization($contentPath, $nowLayerName, $nowLanguage){
    $layerName = ContentsDatabaseManager::GetRelatedLayerName($contentPath);
    if($layerName === false){
        // contentPath に layerName が含まれていない
        // -> default に戻す
        $nowLayerName = DEFAULT_LAYER_NAME; 
    }
    else{
        $nowLayerName = $layerName;
    }
    setcookie('layer', $nowLayerName, time()+(60*60*24*30*6), '/'); // 有効時間 6カ月

    if(Localization\SetLocale($nowLayerName)){
        $nowLanguage = $nowLayerName;
    }
    else{
        $nowLanguage = 'en';
        Localization\SetLocale($nowLanguage);
    }
    setcookie('language', $nowLanguage, time()+(60*60*24*30*6), '/'); // 有効時間 6カ月
    
    return ['layerName' => $nowLayerName, 'language' => $nowLanguage];
}

function CreateRelatedLayerSelector($contentPath){
    $selector = ['layers' => [], 'selectedLayer' => ''];
    $pathInfo = ContentsDatabaseManager::GetContentPathInfo($contentPath);

    if(($layers = ContentsDatabaseManager::GetRelatedLayers($contentPath)) !== false){
        foreach($layers as $layer){
            $layerName = ($layer === false) ? DEFAULT_LAYER_NAME : $layer;
            $localizedLayerName = $layerName;
            $locale = Localization\PeekLocale($localizedLayerName);
            if($locale !== false && array_key_exists('localizedLanguageName', $locale)){
                $localizedLayerName = $locale['localizedLanguageName'];
            }

            $selected = ($layer === $pathInfo['layername']);
            if($selected){
                $selector['selectedLayer'] = $localizedLayerName;
            }

            $layerPath = ($layer === false) ? '' : ('_' . $layer);

            $url = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . 
                CreateContentHREF(
                    $pathInfo['dirname'] . '/' . $pathInfo['filename'] . $layerPath . implode('.', array_merge([''], $pathInfo['extentions']))
                );

            $selector['layers'][] = [
                'name' => $localizedLayerName, 'hreflang' => $layerName, 'selected' => $selected, 'url' => $url
            ];
        }
    }

    return $selector;
}

function CreateContentCard($title, $summary, $href){
    return 
        '<div class="card-item"><div class="inner"><a class="title" href="' . $href . '">'
        . $title . '</a><div class="summary">'. $summary . '</div></div><a class="hover-link" href="'.$href.'"></a></div>';
}

function CreateTagCard($title, $href) {
    return
        '<a class="card-item head tag" href="' . $href . '"><div class="inner"><div class="title">' . $title . "</div>" .
        '<div class="tag-icon icon"></div></div></a>';
}

function MakeOgpDescription($summaryHtml) {
    $description = str_replace('<', ' <', $summaryHtml);
    $description = strip_tags($description);
    // $description = str_replace('  ', ' ', $description);
    $description = preg_replace('/\s\s+/', ' ', $description);
    $description = htmlspecialchars($description);
    $description = mb_strimwidth($description, 0, 115, '...');
    return $description;
}