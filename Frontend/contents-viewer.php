<?php

require_once(MODULE_DIR . '/ContentDatabase.php');

$contentPath = $vars['contentPath'];

// コンテンツの取得
// 存在しないコンテンツ確認
$currentContent = new Content();
if (!$currentContent->SetContent($contentPath)) {
    require(FRONTEND_DIR . '/404.php');
    exit();
}


require_once(MODULE_DIR . '/OutlineText.php');
require_once(MODULE_DIR . '/ContentDatabaseContext.php');
require_once(MODULE_DIR . '/ContentDatabaseControls.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');
require_once(MODULE_DIR . '/Stopwatch.php');
require_once(MODULE_DIR . '/Debug.php');
require_once(MODULE_DIR . '/CacheManager.php');
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/SearchEngine.php');

use ContentDatabaseControls as DBControls;
use ContentsViewerUtils as CVUtils;


$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['parse'] = ['displayName' => 'Parse Time', 'ms' => 0];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates']['navigator'] = ['displayName' => 'Nav', 'updated' => false];


$parentsMaxCount = 3;
$parents = [];
$children = [];
$leftContent = null;
$rightContent = null;


$stopwatch = new Stopwatch();


$vars['rootContentPath'] = DBControls\GetRelatedRootFile($contentPath);
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);

Authenticator::GetUserInfo($vars['owner'], 'enableRemoteEdit',  $enableRemoteEdit);

$dbContext = new ContentDatabaseContext($contentPath);

// layerの再設定
$out = CVUtils\UpdateLayerNameAndResetLocalization($contentPath, $vars['layerName'], $vars['language']);
$vars['layerName'] = $out['layerName'];
$vars['language'] = $out['language'];
$vars['layerSelector'] = CVUtils\CreateRelatedLayerSelector($contentPath);
$layerSuffix = DBControls\GetLayerSuffix($vars['layerName']);

// テキストの読み込み
$stopwatch->Start();

$text = CVUtils\GetDecodedText($currentContent);
$currentContent->summary = $text['summary'];
$currentContent->body = $text['body'];

$vars['pageBuildReport']['times']['parse']['ms'] = $stopwatch->Elapsed() * 1000;

// ChildContentsの取得
foreach ($currentContent->childPathList as $i => $childPath) {
    $child = $currentContent->Child($i);
    if ($child !== false && $dbContext->IsInContentsFolder($child->path)) {
        $children[] = $child;
    } else {
        $vars['warningMessages'][] = Localization\Localize('contents-viewer.invalidChild', "Child Content '{0}' is not found or invalid path.", $childPath);
    }
}

// Parentsの取得
$parent = $currentContent->Parent();
$parentPath = $currentContent->parentPath;
for ($i = 0; $i < $parentsMaxCount; $i++) {
    if ($parent === false || !$dbContext->IsInContentsFolder($parent->path)) {
        if ($parentPath !== '') {
            $vars['warningMessages'][] = Localization\Localize('contents-viewer.invalidParent', "Parent Content '{0}' is not found or invalid path.", $parentPath);
        }
        break;
    }
    $parents[] = $parent;
    $parentPath = $parent->parentPath;
    $parent = $parent->Parent();
}

// LeftContent, RightContentの取得
if (isset($parents[0])) {
    $parent = $parents[0];
    $brothers = $parent->childPathList;

    if (($myIndex = $currentContent->MyIndex()) >= 0) {
        if ($myIndex > 0) {
            $leftContent = $parent->Child($myIndex - 1);
            if ($leftContent !== false && !$dbContext->IsInContentsFolder($leftContent->path)) {
                $leftContent = false;
            }
        }

        if ($myIndex < count($brothers) - 1) {
            $rightContent = $parent->Child($myIndex + 1);
            if ($rightContent !== false && !$dbContext->IsInContentsFolder($rightContent->path)) {
                $rightContent = false;
            }
        }
    }
}

// メタデータの読み込み
$dbContext->LoadMetadata();

// --- navigator作成 -------------------------------------------------
// naviの更新条件
// 
// 現在のコンテンツがコンテンツフォルダよりも新しいとき
// コンテンツ間関係が古い可能性あり．
//
// キャッシュがそもそもないとき
// キャッシュ作成のためにnavi作成
//
// キャッシュのnavi更新時間がコンテンツの更新時間の前のとき
// キャッシュが古いので更新
//
$contentsIsChanged =
    (!array_key_exists('contentsChangedTime', $dbContext->database->metadata) ||
        $currentContent->modifiedTime > $dbContext->database->metadata['contentsChangedTime']);

$cache = new Cache;
$cache->Connect($currentContent->path);
$cache->Lock(LOCK_SH);
$cache->Fetch();
$cache->Unlock();

if (
    $contentsIsChanged ||
    is_null($cache->data) ||
    !array_key_exists('navigator', $cache->data) ||
    !array_key_exists('navigatorUpdateTime', $cache->data) ||
    ($cache->data['navigatorUpdateTime'] < $dbContext->database->metadata['contentsChangedTime'])
) {
    $navigator = "<nav class='navi'><ul>";
    CreateNavHelper($dbContext, $parents, count($parents) - 1, $currentContent, $children, $navigator);
    $navigator .= '</ul></nav>';
    $cache->data['navigator'] = $navigator;

    // 読み込み時の時間を使う
    // 読み込んでからの変更を逃さないため
    $cache->data['navigatorUpdateTime'] = $currentContent->OpenedTime();

    $cache->Lock(LOCK_EX);
    $cache->Apply();
    $cache->Unlock();

    $vars['pageBuildReport']['updates']['navigator']['updated'] = true;
}

$navigator = $cache->data['navigator'];
$cache->Disconnect();

// End navigator 作成 ------------------------------------------------

// メタデータの更新
// contentsChangedTime がここで更新される
$dbContext->RegisterToMetadata($currentContent);
$dbContext->SaveMetadata();

// 更新後のtag2pathを取得
$tag2path = $dbContext->database->metadata['tag2path'] ?? [];

$suggestedTags = DBControls\GetSuggestedTags($currentContent, $tag2path);

// インデックスの読み込み
$dbContext->LoadIndex();

// インデックスの更新
$dbContext->RegisterToIndex($currentContent);
$dbContext->ApplyIndex();


// === ページ内容設定 =======================================================

// title作成
$title = "";
$title .= NotBlankText([$currentContent->title, basename($currentContent->path)]);
if (isset($parents[0])) {
    $title .= " | " . NotBlankText([$parents[0]->title, basename($parents[0]->path)]);
}
$vars['pageTitle'] = $title;

// 追加ヘッダ
$vars['additionalHeadScript'] = '';
if ($currentContent->IsEndpoint()) {
    $vars['additionalHeadScript'] = file_get_contents(CLIENT_DIR . "/Common/EndpointCommonHead.html");
}

// pageHeading の作成
$vars['pageHeading']['title'] = NotBlankText([$currentContent->title, basename($currentContent->path)]);
$parentTitlePathList = [];
foreach ($parents as $parent) {
    if ($parent === false) break;
    $parentTitlePathList[] = ['title' => NotBlankText([$parent->title, basename($parent->path)]), 'path' => CVUtils\CreateContentHREF($parent->path)];
}
$vars['pageHeading']['parents'] = $parentTitlePathList;

// Left, Right Content の設定
if (!is_null($leftContent) && $leftContent !== false) {
    $vars['leftContent'] = ['title' => NotBlankText([$leftContent->title, basename($leftContent->path)]), 'url' => CVUtils\CreateContentHREF($leftContent->path)];
}

if (!is_null($rightContent) && $rightContent !== false) {
    $vars['rightContent'] = ['title' => NotBlankText([$rightContent->title, basename($rightContent->path)]), 'url' => CVUtils\CreateContentHREF($rightContent->path)];
}

// navigator の設定
$vars['navigator'] = $navigator;

// file date の設定
$vars['fileDate'] = ['createdTime' => $currentContent->createdTime, 'modifiedTime' => $currentContent->modifiedTime];

// tagline の設定
$vars['tagline']['tags'] = $currentContent->tags;
$vars['tagline']['suggestedTags'] = $suggestedTags;

// content summary の設定
$vars['contentSummary'] = $currentContent->summary;

// tagList と 最新のコンテンツ 設定
if (DBControls\GetContentPathInfo($currentContent->path)['filename'] === ROOT_FILE_NAME) {
    $vars['tagList'] = DBControls\GetMajorTags($tag2path);
    $vars['addMoreTag'] = true;
    $recent = $dbContext->database->metadata['recent'] ?? [];
    $notFounds = [];
    $vars['recentContents'] = DBControls\GetSortedContentsByUpdatedTime(array_keys($recent), $notFounds);

    if ($dbContext->DeleteContentsFromMetadata($notFounds)) {
        $dbContext->SaveMetadata();
    }
}

// content body の設定
$vars['contentBody'] = $currentContent->body;

// child list の設定
$vars['childList'] = []; // [ ['title' => '', 'summary' => '', 'url' => ''], ... ]

foreach ($children as $child) {
    $vars['childList'][] = [
        'title' => NotBlankText([$child->title, basename($child->path)]),
        'summary' => CVUtils\GetDecodedText($child)['summary'],
        'url' => CVUtils\CreateContentHREF($child->path)
    ];
}

// plainText リンクの追加
$vars['addPlainTextLink'] = true;

// page-tabの追加
$vars['leftPageTabs'] = [
    ['selected' => true, 'innerHTML' => '<a href="' . CVUtils\CreateContentHREF($currentContent->path) . '">' . Localization\Localize('content', 'Content') . '</a>'],
    ['selected' => false, 'innerHTML' => '<a href="' . CVUtils\CreateContentHREF($currentContent->path . '.note') . '">' . Localization\Localize('note', 'Note') . '</a>'],
    ['selected' => false, 'innerHTML' => '<a href="' . CVUtils\CreateDirectoryHREF(dirname($contentPath), $vars['language']) . '">' . Localization\Localize('directory', 'Directory') . '</a>'],
];

$vars['rightPageTabs'] = [];
$vars['rightPageTabs'][] = [
    'selected' => false,
    'innerHTML' =>
    '<a href="?cmd=history">'
        . Localization\Localize('history', 'History') . '</a>'
];
$vars['rightPageTabs'][] = [
    'selected' => false,
    'innerHTML' => '<a href="?cmd=edit"' . ($enableRemoteEdit ? ' target="_blank"' : '')
        . '>' . Localization\Localize('edit', 'Edit') . '</a>'
];

$vars['pageBottomHTML'] =
    "<div id='related-view'><h3>"
    . Localization\Localize('related', 'Related')
    . "</h3><div id='related-results'></div></div>";

$vars['mainFooterHTML'] =
    "<div id='content-survey'>"
    . "<div class='title'>"
    . Localization\Localize('contents-viewer.surveyTitle', 'Was this helpful?')
    . "</div>"
    . "<div class='how-improve'>"
    . Localization\Localize('contents-viewer.howImprove', 'How can we improve it?')
    . "</div>"
    . "<div class='any-feedback'>"
    . Localization\Localize('contents-viewer.anyFeedback', 'Any additional feedback?')
    . "</div>"
    . "<div class='button-group'>"
    . "<button data-value='5' type='button' onclick='ContentsViewer.sendRating(this)'>" . Localization\Localize('yes', 'Yes') . "</button>"
    . "<button data-value='1' type='button' onclick='ContentsViewer.sendRating(this)'>" . Localization\Localize('no', 'No') . "</button>"
    . "</div>"
    . "<input type='hidden' name='thanks' value='" . Localization\Localize('contents-viewer.feedbackThanks', 'Thanks for your feedback!') . "'/>"
    . "<input type='hidden' name='sorry' value='" . Localization\Localize('contents-viewer.feedbackSorry', 'Sorry about that') . "'/>"
    . "</div>";

$vars['canonialUrl'] = (empty($_SERVER["HTTPS"]) ? "http://" : "https://")
    . $_SERVER["HTTP_HOST"] . CVUtils\CreateContentHREF($contentPath);

$vars['htmlLang'] = $vars['layerName'];
$vars['otpRequired'] = true;
$vars['additionalHeadScript'] .= '
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": "' . $vars['pageHeading']['title'] . '",
    "datePublished": "' . date('Y-m-d\TH:i:s\Z',  $vars['fileDate']['createdTime']) . '",
    "dateModified": "' . date('Y-m-d\TH:i:s\Z',  $vars['fileDate']['modifiedTime']) . '",
    "image": [
        "' . (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] . CLIENT_URI . '/Common/ogp-image.png ' . '"
      ]
}
</script>
';
// ビルド時間計測 終了
$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;

// 警告表示設定

// $vars['warningMessages'][] = "Hello world";
$vars['warningMessages'] = array_merge($vars['warningMessages'], CVUtils\GetMessages($currentContent->path));

if ($vars['pageBuildReport']['times']['build']['ms'] > 1500) {
    Debug::LogWarning(
        "
    Performance Note:
        Page Title: {$currentContent->title}
        Page Path: {$currentContent->path}
        --- Build Report ---
" . print_r($vars['pageBuildReport'], true) . "
        --------------------"
    );

    $vars['warningMessages'][] = Localization\Localize(
        'sorryLongTimeToGeneratePageAndReport',
        'Sorry... m(. _ . )m<br> It took long time to generate this page. It will be reported for best user experience.'
    );
}

require(FRONTEND_DIR . '/viewer.php');


function CreateNavHelper(
    ContentDatabaseContext $dbContext,
    $parents,
    $parentsIndex,
    Content $currentContent,
    $children,
    &$navigator
) {
    if ($parentsIndex < 0) {
        $navigator .= '<li><a class = "selected" href="'
            . CVUtils\CreateContentHREF($currentContent->path) . '">'
            . NotBlankText([$currentContent->title, basename($currentContent->path)]) . '</a><ul>';

        foreach ($children as $c) {
            $navigator .= '<li><a href="' . CVUtils\CreateContentHREF($c->path) . '">'
                . NotBlankText([$c->title, basename($c->path)]) . '</a></li>';
        }

        $navigator .= "</ul></li>";
        return;
    }

    $childrenCount = $parents[$parentsIndex]->ChildCount();

    $navigator .= '<li><a class = "selected" href="'
        . CVUtils\CreateContentHREF($parents[$parentsIndex]->path) . '">'
        . NotBlankText([$parents[$parentsIndex]->title, basename($parents[$parentsIndex]->path)])
        . '</a><ul>';

    if ($parentsIndex == 0) {
        $currentContentIndex = $currentContent->MyIndex();
        for ($i = 0; $i < $childrenCount; $i++) {

            $child = $parents[$parentsIndex]->Child($i);
            if ($child === false || !$dbContext->IsInContentsFolder($child->path)) {
                continue;
            }

            if ($i == $currentContentIndex) {
                $navigator .= '<li><a class = "selected" href="'
                    . CVUtils\CreateContentHREF($child->path) . '">'
                    . NotBlankText([$child->title, basename($child->path)]) . '</a><ul>';

                foreach ($children as $c) {
                    $navigator .= '<li><a href="'
                        . CVUtils\CreateContentHREF($c->path) . '">'
                        . NotBlankText([$c->title, basename($c->path)])
                        . '</a></li>';
                }
                $navigator .= "</ul></li>";
            } else {
                $navigator .= '<li><a href="'
                    . CVUtils\CreateContentHREF($child->path) . '">'
                    . NotBlankText([$child->title, basename($child->path)])
                    . '</a></li>';
            }
        }
    } else {
        $nextParentIndex = $parents[$parentsIndex - 1]->MyIndex();
        for ($i = 0; $i < $childrenCount; $i++) {
            if ($i == $nextParentIndex) {
                CreateNavHelper($dbContext, $parents, $parentsIndex - 1, $currentContent, $children, $navigator);
            } else {
                $child = $parents[$parentsIndex]->Child($i);
                if ($child === false || !$dbContext->IsInContentsFolder($child->path)) {
                    continue;
                }
                $navigator .= '<li><a href="'
                    . CVUtils\CreateContentHREF($child->path) . '">'
                    . NotBlankText([$child->title, basename($child->path)])
                    . '</a></li>';
            }
        }
    }
    $navigator .= "</ul></li>";
    return;
}
