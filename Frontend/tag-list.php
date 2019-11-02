<?php

require_once(MODULE_DIR . "/ContentsDatabaseManager.php");
require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . "/Stopwatch.php");


$stopwatch = new Stopwatch();
$stopwatch->Start();

$rootContentPath = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME;
$metaFileName = ContentsDatabaseManager::GetRelatedMetaFileName($rootContentPath);

ContentsDatabaseManager::LoadRelatedMetadata($rootContentPath);

$rootDirectory = substr(GetTopDirectory($rootContentPath), 1);

$latestContents = ContentsDatabase::$metadata['latestContents'];
$tagMap = ContentsDatabase::$metadata['globalTagMap'];
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

$tagIndexListElement = CreateTagIndexListElement($tagMap, $tagName, $rootDirectory);

?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

    <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-viewer.ico" type="image/vnd.microsoft.icon" />

    <link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/OutlineTextStandardStyle.css" />
    <link rel="stylesheet" href="<?=CLIENT_URI?>/ContentsViewer/ContentsViewerStandard.css" />
    <script type="text/javascript" src="<?=CLIENT_URI?>/ContentsViewer/ContentsViewerStandard.js"></script>
    <title><?=($detailMode ? $tagName . ' | ' : '')?>タグ一覧</title>
</head>
<body>
    <?php
    echo CreateHeaderArea($rootContentPath, true); // このスクリプトに入るときは必ず認証されている.

    if (!$vars['isPublic']) {
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
         [['title' => 'タグ一覧', 'path' => CreateTagDetailHREF('', $rootDirectory)]]);
    }
    else{
        $titleField = CreateTitleField('タグ一覧',[]);
    }

    ?>

    <div id="main-area">
        <?php
        echo $titleField;
    
        echo '<div id="content-summary">';
        echo CreateNewBox($latestContents);

        echo "<h2>タグ一覧</h2>";
        echo CreateTagListElement($tagMap, $rootDirectory);

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
            <li id='footer-info-editlink'><a href='javascript:window.open("<?=ROOT_URI?>/Login", "FileManager")'>Manage</a></li>
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
