<?php

require_once dirname(__FILE__) . "/Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/Module/ContentsViewerUtil.php";
require_once dirname(__FILE__) . "/Module/Stopwatch.php";

$stopwatch = new Stopwatch();
$stopwatch->Start();

$rootContentPath = ContentsDatabaseManager::DefalutRootContentPath();
$metaFileName = ContentsDatabaseManager::DefaultTagMapMetaFileName();

if (isset($_GET['group'])) {
    $metaFileName = urldecode($_GET['group']);
    $rootContentPath = ContentsDatabaseManager::GetRelatedRootFile($metaFileName);
}

if (Content::LoadGlobalTagMap($metaFileName) === false) {
    $rootContentPath = ContentsDatabaseManager::DefalutRootContentPath();
    $metaFileName = ContentsDatabaseManager::DefaultTagMapMetaFileName();
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

    <div id="header-area">
        <a href="<?=CreateContentHREF($rootContentPath)?>">ContentsViewer</a>
    </div>


    <?php
    if (!$isAuthorized) {
        CreateUnauthorizedMessageBox();
        exit;
    }

    if (!$isPublicContent) {
        echo '<div class="secret-icon">🕶</div>';
    }

    ?>
    <div id ='left-side-area'>
        <div class="navi">
            <?=$tagIndexListElement;?>
        </div>
    </div>


    <div id = 'right-side-area'>
        Index
        <div class='navi'>
            <?php
            if ($detailMode) {
                echo '<ul>';
                foreach($sortedContents as $content){
                    echo '<li><a href="' . CreateContentHREF($content['path']) . '">' .
                         $content['title'] . ($content['parentTitle'] === '' ? '' : ' | ' . $content['parentTitle']) .
                         '</a></li>';
                }
                echo '</ul>';
            } else {
                echo '目次がありません';
            }
            ?>
        </div>
    </div>

    <?php
    $titleField = '<div class="title-field"><ul class="breadcrumb">';
    if ($detailMode) {
        $titleField .= '<li><a href="' . CreateTagDetailHREF('', $metaFileName) . '">タグ一覧</a></li>';
    }
    $titleField .= '</ul><h1 class="title">' . ($detailMode ? $tagName : 'タグ一覧') . '</h1></div>';
    ?>

    <div id="print-title">
        <?=$titleField?>
    </div>

    <div id="main-area">
        <?php
        echo '<div id="summary-field" class="summary">';
        echo CreateNewBox($tagMap);

        echo "<h2>タグ一覧</h2>";
        echo CreateTagListElement($tagMap, $metaFileName);

        echo "</div>";

        echo '<div id="children-field">';
        foreach($sortedContents as $content){
            echo '<div style="width:100%; display: table">'.
                 '<div style="display: table-cell">'.
                 '<a class="link-block-button" href ="' . CreateContentHREF($content['path']) . '">'.
                 $content['title'] . ($content['parentTitle'] === '' ? '' : ' | ' . $content['parentTitle']) .
                 '</a></div></div>';
        }
        echo "</div>";
        ?>
    </div>

    <div id='bottom-of-main-area-on-small-screen'>
        <div class="navi">
            <?=$tagIndexListElement;?>
        </div>
    </div>

    <div id="top-area">
        <?=$titleField?>
    </div>

    <div id='footer'>
        <a href='./login.php'>Manage</a><br/>
        <b>ConMAS 2019.</b> Page Build Time: <?=sprintf("%.2f[ms]", $stopwatch->Elapsed() * 1000);?>;
    </div>

</body>
</html>
