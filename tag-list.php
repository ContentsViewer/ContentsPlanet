<?php

require_once dirname(__FILE__) . "/Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/Module/ContentsViewerUtil.php";

$rootContentPath = ContentsDatabaseManager::DefalutRootContentPath();
$metaFileName = ContentsDatabaseManager::DefaultMetaFileName();

if (isset($_GET['group'])) {
    $metaFileName = urldecode($_GET['group']);
    $rootContentPath = ContentsDatabaseManager::GetRelatedRootFile($metaFileName);
}

if (Content::LoadGlobalTagMap($metaFileName) === false) {
    $rootContentPath = ContentsDatabaseManager::DefalutRootContentPath();
    $metaFileName = ContentsDatabaseManager::DefaultMetaFileName();
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

$content = new Content();
$contentTitlePathMap = array();
if ($detailMode) {
    foreach ($tagMap[$tagName] as $path) {
        if ($content->SetContent($path)) {
            $contentTitlePathMap[$content->Title()] = $path;
        }
    }

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
    ?>
    <div id="error-message-box">
    <h1>Unauthorized...</h1> <br/>
    対象のコンテンツに対するアクセス権がありません.<br/>
    アクセス権を持つアカウントに再度ログインしてください.<br/>
    <a href="./logout.php?token=<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>" target="_blank">&gt;&gt;再ログイン&lt;&lt;</a>
    </div>

    <?php
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
    foreach ($contentTitlePathMap as $title => $path) {
        echo '<li><a href="' . CreateContentHREF($path) . '">' . $title . '</a></li>';
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
foreach ($contentTitlePathMap as $title => $path) {
    echo "<div style='width:100%; display: table'>";

    echo "<div style='display: table-cell'>";

    echo '<a class="link-block-button" href ="' . CreateContentHREF($path) . '">';
    echo $title;
    echo '</a>';

    echo "</div>";
    echo "</div>";
}
echo "</div>";
?>

    </div>

    <div id='bottom-of-main-area-on-small-screen'>
        <div class="navi">
            <?php
echo $tagIndexListElement;
?>
        </div>

    </div>
    <div id="top-area">
        <?=$titleField?>
    </div>

    <div id='footer'>
        <a href='./login.php'>Manage</a><br/>
        <b>ConMAS 2018.</b>
    </div>

</body>
</html>