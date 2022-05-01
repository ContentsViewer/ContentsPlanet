<?php

require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . '/ContentDatabaseControls.php');
require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/Debug.php');
require_once(MODULE_DIR . '/CacheManager.php');
require_once(MODULE_DIR . '/Stopwatch.php');
require_once(MODULE_DIR . '/Authenticator.php');

use ContentDatabaseControls as DBControls;
use ContentsViewerUtils as CVUtils;


$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['parse'] = ['displayName' => 'Parse Time', 'ms' => 0];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates'] = [];


if (basename($vars['subURI']) == '.note') {
    // '.note'のみで, そのあとにコンテンツ名が無い場合
    $vars['errorMessage'] = Localization\Localize('invalidURL', 'Invalid URL.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}

// 計測開始
$stopwatch = new Stopwatch();
$stopwatch->Start();

$vars['rootContentPath'] = DBControls\GetRelatedRootFile($vars['contentPath']);
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);

Authenticator::GetUserInfo($vars['owner'], 'enableRemoteEdit',  $enableRemoteEdit);

// layerの再設定
$out = CVUtils\UpdateLayerNameAndResetLocalization($vars['contentPath'], $vars['layerName'], $vars['language']);
$vars['layerName'] = $out['layerName'];
$vars['language'] = $out['language'];

$relatedContentPath = dirname($vars['contentPath']) . '/' . basename($vars['contentPath'], '.note');

$note = new Content();
$noteExists = $note->SetContent($vars['contentPath']);

$vars['leftPageTabs'] = [];

$content = new Content();
if ($relatedContentExists = $content->SetContent($relatedContentPath)) {

    $vars['pageTitle'] = Localization\Localize('note', 'Note') . ': ' . NotBlankText([$content->title, basename($content->path)]);

    // page-tabの追加
    $vars['leftPageTabs'][] = [
        'selected' => false,
        'innerHTML' => '<a href="' . CVUtils\CreateContentHREF($content->path) . '">' . Localization\Localize('content', 'Content') . '</a>'
    ];

    $vars['navigator'] = '<nav class="navi"><ul><li>' . Localization\Localize('temporarilyUnavailable', 'Temporarily Unavailable') . '</li></ul></nav>';
    if (CVUtils\GetNavigatorFromCache($content->path, $navi)) {
        $vars['navigator'] = $navi;
    }

    $vars['contentSummary'] = '<p>' .
        Localization\Localize(
            'note-viewer.theNoteRelatedContent',
            'The Note related with "Content: <a href="{0}">{1}</a>".',
            CVUtils\CreateContentHREF($content->path),
            NotBlankText([$content->title, basename($content->path)])
        )
        . '</p>';
} else {
    // コンテンツがない場合

    if (!$noteExists) {
        // Noteファイルもないとき

        require(FRONTEND_DIR . '/404.php');
        exit();
    }


    $vars['pageTitle'] = Localization\Localize('note', 'Note') . ': ' . basename($relatedContentPath);

    $vars['navigator'] = '<nav class="navi"><ul><li>' . Localization\Localize('temporarilyUnavailable', 'Temporarily Unavailable') . '</li></ul></nav>';
    if (CVUtils\GetNavigatorFromCache($vars['rootContentPath'], $navi)) {
        $vars['navigator'] = $navi;
    }

    $vars['contentSummary'] = '<p>' .
        Localization\Localize(
            'note-viewer.noteExistsButContentNotFound',
            'The Note exists, but not found the related Content "{0}.content".',
            basename($relatedContentPath)
        ) .
        '</p>';

    $query = Path2URI($relatedContentPath);
    $query = DBControls\ReduceURI($query);
    $vars['contentSummary'] .=
        Localization\Localize(
            'note-viewer.optionsForFindingOfTheContent',
            '<ul><li>Find "{0}.content" on <a href="{1}">the same direcotry.</a></li>' .
                '<li><a href="javascript:void(0);" onclick="ContentsViewer.onClickSearchButton(\'{2}\')">Search</a> for "{0}.content".</li></ul>',
            basename($relatedContentPath),
            ROOT_URI . dirname($vars['subURI']),
            H($query)
        );
}

$vars['pageHeading']['parents'] = [];
$vars['pageHeading']['title'] = $vars['pageTitle'];

$vars['leftPageTabs'][] = [
    'selected' => true,
    'innerHTML' =>
    '<a href="' . CVUtils\CreateContentHREF($vars['contentPath']) . '">' . Localization\Localize('note', 'Note') . '</a>'
];
$vars['leftPageTabs'][] = [
    'selected' => false,
    'innerHTML' =>
    '<a href="' . CVUtils\CreateDirectoryHREF(dirname($vars['contentPath']), $vars['language']) . '">' . Localization\Localize('directory', 'Directory') . '</a>'
];

$vars['childList'] = [];

// ここまでで, コンテンツがあるが, Noteファイルがない
// or コンテンツがあり, Noteファイルもある
// or コンテンツがなく, Noteファイルがある


$body = '';

// Note:
//  同じディレクトリ内で, 同名のサブディレクトリとファイルは存在できない.
if ($noteExists) {
    $parsingStopwatch = new Stopwatch();
    $parsingStopwatch->Start();

    $text = CVUtils\GetDecodedText($note);

    $parsingStopwatch->Stop();

    $vars['pageBuildReport']['times']['parse']['ms'] = $parsingStopwatch->Elapsed() * 1000;

    $vars['fileDate'] = ['createdTime' => $note->createdTime, 'modifiedTime' => $note->modifiedTime];
    $vars['contentSummary'] .= $text['summary'];
    $body .= $text['body'];
} else {
    $body = '<p>' . Localization\Localize('note-viewer.noteFileDoesNotExist', 'The Note file does not exist.') . '</p>';
}

$vars['contentBody'] = $body;

if ($noteExists) {
    // plainText リンクの追加
    $vars['addPlainTextLink'] = true;

    $vars['rightPageTabs'] = [
        [
            'selected' => false,
            'innerHTML' => '<a href="?cmd=history">'
                . Localization\Localize('history', 'History') . '</a>'
        ],
        [
            'selected' => false,
            'innerHTML' => '<a href="?cmd=edit"' . ($enableRemoteEdit ? ' target="_blank"' : '')
                . '>' . Localization\Localize('edit', 'Edit') . '</a>'
        ],
        [
            'selected' => true,
            'innerHTML' => '<a href="'
                . CVUtils\CreateContentHREF($vars['contentPath'])
                . '">' . Localization\Localize('view', 'View') . '</a>'
        ],
    ];
}

$vars['htmlLang'] = $vars['layerName'];


// ビルド時間計測 終了
$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;

$vars['canonialUrl'] = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") .
    $_SERVER["HTTP_HOST"] . CVUtils\CreateContentHREF($vars['contentPath']);

require(FRONTEND_DIR . '/viewer.php');
exit();
