<?php

require_once dirname(__FILE__) . "/Authenticator.php";

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
        $newPathList = $tagMap["New"];
        $newPathListCount = count($newPathList);

        $content = new Content();
        for ($i = 0; $i < $newPathListCount; $i++) {
            if ($content->SetContent($newPathList[$i])) {

                $title = "[" . $content->UpdatedAt() . "] " . $content->Title();
                $newBoxElement .= "<li><a href='" . CreateContentHREF($content->Path()) . "'>" . $title . "</a></li>";
            }
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
