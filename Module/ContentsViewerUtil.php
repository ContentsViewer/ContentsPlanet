<?php

require_once dirname(__FILE__) . "/Authenticator.php";
require_once dirname(__FILE__) . "/ContentsDatabaseManager.php";


function GetContentAuthInfo($contentPath)
{
    $isAuthorized = true;
    $isPublicContent = true;
    $ownerName = Authenticator::GetOwnerNameFromContentPath($contentPath);

    if ($ownerName !== false) {
        $isPublicContent = Authenticator::GetIsPublic($ownerName);
    }

    if (!$isPublicContent) {
        // Authenticator::RequireLoginedSession();
        // セッション開始
        @session_start();
        if (Authenticator::GetLoginedUsername() !== $ownerName) {
            $isAuthorized = false;
        }
    }

    return ['ownerName' => $ownerName, 'isPublicContent' => $isPublicContent, 'isAuthorized' => $isAuthorized];
}

//
// サーバーとクライアント間の出入口をしっかり.
function H($text)
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function CreateContentHREF($contentPath)
{
    return './?content=' . urlencode($contentPath);
}

function CreateTagDetailHREF($tagName, $metaFileName)
{
    // $url  = empty($_SERVER["HTTPS"]) ? "http://" : "https://";
    // $url .= $_SERVER["HTTP_HOST"]."/tag-list.php";
    $tagName = urlencode($tagName);
    $metaFileName = urlencode($metaFileName);
    return './tag-list.php?group=' . $metaFileName . '&name=' . $tagName;
}

function CreateTagIndexListElement($tagMap, $selectedTagName, $metaFileName)
{
    $listElement = '<ul>';
    foreach ($tagMap as $name => $pathList) {

        $selectedStr = '';
        if ($name == $selectedTagName) {
            $selectedStr = ' class="selected" ';
        }
        $listElement .= '<li><a href="' . CreateTagDetailHREF($name, $metaFileName) . '"' . $selectedStr . '>' . $name . '</a></li>';

    }
    $listElement .= '</ul>';

    return $listElement;
}

function CreateNewBox($tagMap)
{
    $newBoxElement = "<div class='new-box'><ol class='new-list'>";

    if (array_key_exists("New", $tagMap)) {
        $newContents = GetSortedContentsByUpdatedTime($tagMap['New']);
        foreach($newContents as $content){
            $title = "[" . $content['updatedAt'] . "] " . $content['title'] .
                     ($content['parentTitle'] === '' ? '' : ' | ' . $content['parentTitle']);
            $newBoxElement .= "<li><a href='" . CreateContentHREF($content['path']) . "'>" . $title . "</a></li>";
        }
    }

    $newBoxElement .= "</ol></div>";
    return $newBoxElement;
}

function CreateTagListElement($tagMap, $metaFileName)
{
    $listElement = '<ul class="tag-list">';

    foreach ($tagMap as $name => $pathList) {
        $listElement .= '<li><a href="' . CreateTagDetailHREF($name, $metaFileName) . '">' . $name . '<span>' . count($pathList) . '</span></a></li>';
    }
    $listElement .= '</ul>';

    return $listElement;
}

function CreateUnauthorizedMessageBox(){
    echo '<div id="error-message-box"><h1>Unauthorized...</h1> <br/>'.
         '対象のコンテンツに対するアクセス権がありません.<br/>'.
         'アクセス権を持つアカウントに再度ログインしてください.<br/>'.
         '<a href="./logout.php?token=' . Authenticator::H(Authenticator::GenerateCsrfToken()) . 
         '" target="_blank">&gt;&gt;再ログイン&lt;&lt;</a></div>';
}

function GetSortedContentsByUpdatedTime($pathList){
    $content = new Content();
    $sorted = [];
    foreach($pathList as $path){
        if(!$content->SetContent($path)){
            continue;
        }

        $parent = $content->Parent();
        $parentTitle = '';
        if($parent !== false){
            $parentTitle = $parent->Title();
        }
        $sorted[] = ['path' => $content->Path(), 'updatedTime' => $content->UpdatedAtTimestamp(),
                     'title' => $content->Title(), 'updatedAt' => $content->UpdatedAt(),
                     'parentTitle' => $parentTitle];
    }

    usort($sorted, function($a, $b){return $b['updatedTime'] - $a['updatedTime'];});
    return $sorted;
}
