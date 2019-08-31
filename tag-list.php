<?php

require_once dirname(__FILE__) . "/Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/Module/ContentsViewerUtil.php";
require_once dirname(__FILE__) . "/Module/Stopwatch.php";

$stopwatch = new Stopwatch();
$stopwatch->Start();

$rootContentPath = ContentsDatabaseManager::DefalutRootContentPath();
$metaFileName = ContentsDatabaseManager::DefaultTagMapMetaFilePath();

if (isset($_GET['group'])) {
    $metaFileName = urldecode($_GET['group']);
    $metaFileName = ContentsDatabaseManager::GetRelatedTagMapMetaFileName($metaFileName);
    $rootContentPath = ContentsDatabaseManager::GetRelatedRootFile($metaFileName);
}

if (Content::LoadGlobalTagMap($metaFileName) === false) {
    $rootContentPath = ContentsDatabaseManager::DefalutRootContentPath();
    $metaFileName = ContentsDatabaseManager::DefaultTagMapMetaFilePath();
    Content::LoadGlobalTagMap($metaFileName);
}

$tagMap = Content::GlobalTagMap();
$tagMapCount = count($tagMap);

$tagName = '';
$detailMode = false;
if (isset($_GET['name'])) {
    $tagName = urldecode($_GET['name']);

    if (array_key_exists($tagName, $tagMap)) {
        $detailMode = true;
    }
}

$sortedContents = [];
if($detailMode){
    $sortedContents = GetSortedContentsByUpdatedTime($tagMap[$tagName]);
}

$tagIndexListElement = CreateTagIndexListElement($tagMap, $tagName, $metaFileName);

// 権限確認
$authInfo = GetContentAuthInfo($rootContentPath);
$isAuthorized = $authInfo['isAuthorized'];
$isPublicContent = $authInfo['isPublicContent'];

if (!$isAuthorized) {
    header("HTTP/1.1 401 Unauthorized");
}

?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <?php readfile("Client/Common/CommonHead.html");?>

    <link rel="shortcut icon" href="Client/Common/favicon.ico" type="image/vnd.microsoft.icon" />

    <link rel="stylesheet" href="Client/OutlineText/OutlineTextStandardStyle.css" />
    <link rel="stylesheet" href="Client/ContentsViewer/ContentsViewerStandard.css" />
    <script type="text/javascript" src="Client/ContentsViewer/ContentsViewerStandard.js"></script>

    <?php
    if (!$isAuthorized) {
        echo '<title>Unauthorized...</title>';
    }

    if ($isAuthorized) {
        echo '<title>' . ($detailMode ? $tagName . ' | ' : '') . 'タグ一覧</title>';
    }
    ?>

</head>
<body>

    <?php
    
    echo CreateHeaderArea($rootContentPath, $metaFileName, $isAuthorized);

    if (!$isAuthorized) {
        echo CreateUnauthorizedMessageBox();
        exit;
    }

    if (!$isPublicContent) {
        echo '<div id="secret-icon">🕶</div>';
    }

    ?>
    <div class='menu-open-button-wrapper'>
        <input type="checkbox" href="#" class="menu-open" name="menu-open" id="menu-open" onchange="OnChangeMenuOpen(this)"/>
        <label class="menu-open-button" for="menu-open">
        <span class="lines line-1"></span>
        <span class="lines line-2"></span>
        <span class="lines line-3"></span>
        </label>
    </div>
    <div id="left-side-area-responsive">
        <div class="navi"><?=$tagIndexListElement;?></div>
    </div>

    <div id ='left-side-area'>
        <div class="navi"><?=$tagIndexListElement;?></div>
    </div>


    <div id = 'right-side-area'>
        目次
        <div class='navi'>
            <?php
            if ($detailMode) {
                echo '<ul>';
                foreach($sortedContents as $content){
                    echo '<li><a href="' . CreateContentHREF($content->Path()) . '">' .
                         $content->Title() .
                         '</a></li>';
                }
                echo '</ul>';
            } else {
                echo '　ありません';
            }
            ?>
        </div>
    </div>

    <?php
    $titleField = '';
    if($detailMode){
        $titleField = CreateTitleField($tagName,
         [['title' => 'タグ一覧', 'path' => CreateTagDetailHREF('', $metaFileName)]]);
    }
    else{
        $titleField = CreateTitleField('タグ一覧',[]);
    }

    ?>

    <div id="main-area">
        <?php
        echo $titleField;
    
        echo '<div id="summary-field" class="summary">';
        echo CreateNewBox($tagMap);

        echo "<h2>タグ一覧</h2>";
        echo CreateTagListElement($tagMap, $metaFileName);

        echo "</div>";

        echo '<div id="child-list"><ul>';
        foreach($sortedContents as $content){
            $parent = $content->Parent();
            ?>
            <li><div>
                <div class='child-title'>
                    <a href ='<?=CreateContentHREF($content->Path())?>'><?=$content->Title() . ($parent === false ? '' : ' | ' . $parent->Title())?></a>
                </div>
                <div class='child-summary'>
                    <?=GetDecodedText($content)['summary']?>
                </div>
            </div></li>
            <?php
        }
        echo "</ul></div>";
        ?>
            
        <div id='printfooter'>
            「<?=(empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];?>」から取得
        </div>
    </div>

    <div id='bottom-of-main-area-on-small-screen'></div>

    <div id='footer'>
        <ul id='footer-info'>
            <li id='footer-info-editlink'><a href='javascript:window.open("./login.php", "FileManager")'>Manage</a></li>
            <li id='footer-info-cms'>
                Powered by <b>CollabCMS <?=VERSION?></b>
            </li>
            <li id='footer-info-build-report'>
                Page Build Time: <?=sprintf("%.2f[ms]", $stopwatch->Elapsed() * 1000);?>;
            </li>
        </ul>
    </div>

    <div id='sitemask' onclick='OnClickSitemask()'></div>

</body>
</html>
