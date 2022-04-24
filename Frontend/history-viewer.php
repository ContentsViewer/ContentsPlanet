<?php

require_once(MODULE_DIR . '/ContentDatabaseControls.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');
require_once(MODULE_DIR . '/ContentHistory.php');
require_once(MODULE_DIR . '/Localization.php');
require_once(MODULE_DIR . '/Stopwatch.php');
require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/Utils.php');

use ContentDatabaseControls as DBControls;
use ContentsViewerUtils as CVUtils;


$stopwatch = new Stopwatch();
$stopwatch->Start();

$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates'] = [];
$vars['contentSummary'] = '';
$vars['contentBody'] = '';
$vars['childList'] = [];

Authenticator::GetUserInfo($vars['owner'], 'enableRemoteEdit',  $enableRemoteEdit);

$currentContentPathInfo = DBControls\GetContentPathInfo($vars['contentPath']);
$articleContentPath = $currentContentPathInfo['dirname']
    . '/' . $currentContentPathInfo['filename']
    . DBControls\GetLayerSuffix($currentContentPathInfo['layername']);
$isNoteFile = in_array('note', $currentContentPathInfo['extentions']);

$currentContent = new Content();
$existsCurrentContent = $currentContent->SetContent($vars['contentPath']);

$articleContent = new Content();
$existsArticleContent = $articleContent->SetContent($articleContentPath);

$contentTitle = NotBlankText([$articleContent->title, $currentContentPathInfo['filename']]);
if ($isNoteFile) {
    $contentTitle = Localization\Localize('note', 'Note') . ': ' . $contentTitle;
}

$history = ContentHistory\GetHistory($vars['contentPath']);
$revisions = $history['revisions'] ?? [];
krsort($revisions);

$vars['rootContentPath'] = DBControls\GetRelatedRootFile($vars['contentPath']);
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);
$vars['pageHeading']['parents'] = [];

$vars['navigator'] = '<nav class="navi"><ul><li>' . Localization\Localize('temporarilyUnavailable', 'Temporarily Unavailable') . '</li></ul></nav>';
if ($existsCurrentContent && CVUtils\GetNavigatorFromCache($articleContentPath, $navi)) {
    $vars['navigator'] = $navi;
} elseif (CVUtils\GetNavigatorFromCache($vars['rootContentPath'], $navi)) {
    $vars['navigator'] = $navi;
}

$vars['pageTitle'] = Localization\Localize('history.historyTitle', '{0}: Revision history', $contentTitle);
$vars['pageHeading']['title'] = $vars['pageTitle'];


if (!$existsCurrentContent && empty($revisions)) {
    require(FRONTEND_DIR . '/404.php');
    exit();
}

$vars['leftPageTabs'] = [];
$vars['leftPageTabs'][] = [
    'selected' => !$isNoteFile,
    'innerHTML' =>
    '<a href="'
        . CVUtils\CreateContentHREF($articleContentPath)
        . '">' . Localization\Localize('content', 'Content') . '</a>'
];
$vars['leftPageTabs'][] = [
    'selected' => $isNoteFile,
    'innerHTML' =>
    '<a href="'
        . CVUtils\CreateContentHREF($articleContentPath . '.note')
        . '">' . Localization\Localize('note', 'Note') . '</a>'
];
$vars['leftPageTabs'][] = [
    'selected' => false,
    'innerHTML' =>
    '<a href="'
        . CVUtils\CreateDirectoryHREF(dirname($articleContentPath), $vars['language'])
        . '">' . Localization\Localize('directory', 'Directory') . '</a>'
];
$vars['rightPageTabs'] = [];

$vars['rightPageTabs'][] = [
    'selected' => true,
    'innerHTML' =>
    '<a href="?cmd=history"' .
        '>' . Localization\Localize('history', 'History') . '</a>'
];
$vars['rightPageTabs'][] = [
    'selected' => false,
    'innerHTML' =>
    '<a href="?cmd=edit"' . ($enableRemoteEdit ? ' target="_blank"' : '') .
        '>' . Localization\Localize('edit', 'Edit') . '</a>'
];

if (empty($revisions)) {
    $summary = '<p>' . Localization\Localize('history.notFoundHistory', 'Not found history.') . '</p>';
    $vars['contentSummary'] = $summary;

    $stopwatch->Stop();
    $vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;
    require(FRONTEND_DIR . '/viewer.php');
    exit();
}

$summary = '';

if (!$existsCurrentContent) {
    $summary .= '<p>' . Localization\Localize('history.notFoundCurrentContent', 'The revision history is still there, but the actual content file "{0}" is missing. It might have been moved or deleted.', $currentContentPathInfo['basename'] . '.content') . '</p>';
}

if (isset($_GET['rev'])) {
    $rev = $_GET['rev'];
    if (!is_numeric($rev)) {
        ExitWithInvalidParameterError();
    }

    $vars['pageHeading']['parents'][] = [
        'title' => $vars['pageTitle'],
        'path' => '?cmd=history'
    ];

    $vars['pageHeading']['title'] = Localization\Localize('history.revTitle', 'Old revision');
    $vars['pageTitle'] = $vars['pageHeading']['title'] . ' | ' . $vars['pageTitle'];

    if (!array_key_exists($rev, $revisions)) {
        $summary .= '<p>' . Localization\Localize('history.notFoundRevision', 'The revision #{0} of the page named "{1}" does not exist.', $rev, $contentTitle) . '</p>';
        $vars['contentSummary'] = $summary;

        require(FRONTEND_DIR . '/viewer.php');
        exit();
    }

    $head = '';
    $head .= '<script src="' . CLIENT_URI . '/node_modules/ace-builds/src-min/ace.js" type="text/javascript" charset="utf-8"></script>';
    $head .= '
<style>
#source-view {
    position: relative;
    height: 90vh;
    width: 100%;
}
.revision-title {
    text-align: center;
    padding: 0.33em 0.5em;
    vertical-align: top;
}
</style>    
    ';
    $body = '';
    $body .= '<div class="revision-title">';
    $body .= '<h4>' . Localization\Localize('history.revisionTitle', 'Revision as of {0}', date('Y-m-d H:i', $rev)) . '</h4>';
    $body .= '<div>&nbsp;</div>';
    $body .= '</div>';
    $body .= '<div id="source-view">' . H($revisions[$rev], ENT_QUOTES) . '</div>';
    $body .= '
<script>
var editor = ace.edit("source-view");
editor.setReadOnly(true);
editor.setTheme("ace/theme/textmate");
editor.renderer.setShowGutter(false);

if(ThemeChanger) {
    onChangeTheme();
    ThemeChanger.onChangeThemeCallbacks.push(onChangeTheme);
}
function onChangeTheme() {
    if(ThemeChanger.getCurrentTheme() == "dark") {
        editor.setTheme("ace/theme/twilight");
    }
    else {
        editor.setTheme("ace/theme/textmate");
    }
}
</script>
    ';

    $vars['additionalHeadScript'] = $head;
    $vars['contentSummary'] = $summary;
    $vars['contentBody'] = $body;

    $stopwatch->Stop();
    $vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;
    require(FRONTEND_DIR . '/viewer.php');
    exit();
} elseif (isset($_GET['diff'])) {
    $diff = $_GET['diff'];
    if (count($diff) != 2 || !is_numeric($diff[0]) || !is_numeric($diff[1])) {
        ExitWithInvalidParameterError();
    }

    $oldRev = $diff[0];
    $newRev = $diff[1];
    if ($oldRev > $newRev) list($oldRev, $newRev) = array($newRev, $oldRev);

    $vars['pageHeading']['parents'][] = [
        'title' => $vars['pageTitle'],
        'path' => '?cmd=history'
    ];

    $vars['pageHeading']['title'] = Localization\Localize('history.diffTitle', 'Difference between revisions');
    $vars['pageTitle'] = $vars['pageHeading']['title'] . ' | ' . $vars['pageTitle'];

    $notFound = false;
    if (!array_key_exists($oldRev, $revisions)) {
        $summary .= '<p>' . Localization\Localize('history.notFoundRevision', 'The revision #{0} of the page named "{1}" does not exist.', $oldRev, $contentTitle) . '</p>';
        $notFound = true;
    }
    if (!array_key_exists($newRev, $revisions)) {
        $summary .= '<p>' . Localization\Localize('history.notFoundRevision', 'The revision #{0} of the page named "{1}" does not exist.', $newRev, $contentTitle) . '</p>';
        $notFound = true;
    }
    if ($notFound) {
        $vars['contentSummary'] = $summary;

        require(FRONTEND_DIR . '/viewer.php');
        exit();
    }

    $head = '';
    $head .= '<script src="' . CLIENT_URI . '/node_modules/ace-builds/src-min/ace.js" type="text/javascript" charset="utf-8"></script>';
    $head .= '<script src="' . CLIENT_URI . '/node_modules/ace-diff/dist/ace-diff.min.js"></script>';
    $head .= '<link href="' . CLIENT_URI . '/node_modules/ace-diff/dist/ace-diff.min.css" rel="stylesheet" id="diff-style-light">';
    $head .= '<link href="' . CLIENT_URI . '/node_modules/ace-diff/dist/ace-diff-dark.min.css" rel="stylesheet" id="diff-style-dark" disabled>';
    $head .= '
<style>
#diff {
    position: relative;
    height: 90vh;
    width: 100%;
}
.diff-heading-layout {
    display: flex;
}
.diff-heading-layout .gutter {
    flex-basis: 60px;
    text-align: center;
}
.diff-heading-layout .left,
.diff-heading-layout .right {
    flex-grow: 1;
}
.diff-heading-layout.diff-title {
    justify-content: space-around;
}
.diff-heading-layout.diff-fold-buttons {
    justify-content: center;
}
.diff-title .revision-title {
    text-align: center;
    padding: 0.33em 0.5em;
    vertical-align: top;
}
.diff-fold-buttons button {
    background: none;
    box-sizing: border-box;
    width: 30px;
    height: 25px;
    border-radius: 2px;
    padding: 2.5px 5px 2.55px 5px;
    cursor: pointer;
    border: none;
    margin: 0;
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    color: white;
}
.diff-fold-buttons button:hover {
    background-color: rgba(127, 127, 127, 0.1);
}
.diff-fold-buttons .left-fold-icon::before {
    background-image: url("data:image/svg+xml;charset=utf8,%3Csvg%20aria-hidden%3D%22true%22%20focusable%3D%22false%22%20data-prefix%3D%22fas%22%20data-icon%3D%22chevron-left%22%20class%3D%22svg-inline--fa%20fa-chevron-left%20fa-w-10%22%20role%3D%22img%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%20320%20512%22%3E%3Cpath%20fill%3D%22gray%22%20d%3D%22M34.52%20239.03L228.87%2044.69c9.37-9.37%2024.57-9.37%2033.94%200l22.67%2022.67c9.36%209.36%209.37%2024.52.04%2033.9L131.49%20256l154.02%20154.75c9.34%209.38%209.32%2024.54-.04%2033.9l-22.67%2022.67c-9.37%209.37-24.57%209.37-33.94%200L34.52%20272.97c-9.37-9.37-9.37-24.57%200-33.94z%22%3E%3C%2Fpath%3E%3C%2Fsvg%3E")
}
.diff-fold-buttons .right-fold-icon::before {
    background-image: url("data:image/svg+xml;charset=utf8,%3Csvg%20aria-hidden%3D%22true%22%20focusable%3D%22false%22%20data-prefix%3D%22fas%22%20data-icon%3D%22chevron-right%22%20class%3D%22svg-inline--fa%20fa-chevron-right%20fa-w-10%22%20role%3D%22img%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%20320%20512%22%3E%3Cpath%20fill%3D%22gray%22%20d%3D%22M285.476%20272.971L91.132%20467.314c-9.373%209.373-24.569%209.373-33.941%200l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505%20256%2034.484%20101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373%2024.569-9.373%2033.941%200L285.475%20239.03c9.373%209.372%209.373%2024.568.001%2033.941z%22%3E%3C%2Fpath%3E%3C%2Fsvg%3E")
}
.diff-fold-buttons .justify-icon::before {
    background-image: url("data:image/svg+xml;charset=utf8,%3Csvg%20aria-hidden%3D%22true%22%20focusable%3D%22false%22%20data-prefix%3D%22fas%22%20data-icon%3D%22align-justify%22%20class%3D%22svg-inline--fa%20fa-align-justify%20fa-w-14%22%20role%3D%22img%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%20448%20512%22%3E%3Cpath%20fill%3D%22gray%22%20d%3D%22M432%20416H16a16%2016%200%200%200-16%2016v32a16%2016%200%200%200%2016%2016h416a16%2016%200%200%200%2016-16v-32a16%2016%200%200%200-16-16zm0-128H16a16%2016%200%200%200-16%2016v32a16%2016%200%200%200%2016%2016h416a16%2016%200%200%200%2016-16v-32a16%2016%200%200%200-16-16zm0-128H16a16%2016%200%200%200-16%2016v32a16%2016%200%200%200%2016%2016h416a16%2016%200%200%200%2016-16v-32a16%2016%200%200%200-16-16zm0-128H16A16%2016%200%200%200%200%2048v32a16%2016%200%200%200%2016%2016h416a16%2016%200%200%200%2016-16V48a16%2016%200%200%200-16-16z%22%3E%3C%2Fpath%3E%3C%2Fsvg%3E")
}
.diff-fold-buttons .left {
    text-align: right;
}
.diff-fold-buttons .gutter {
    text-align: center;
}
.diff-fold-buttons .right {
    text-align: left;
}
</style>
    ';
    $body = '';

    $body .= '<input type="hidden" id="new-content" value="' . H($revisions[$newRev], ENT_QUOTES) . '">';
    $body .= '<input type="hidden" id="old-content" value="' . H($revisions[$oldRev], ENT_QUOTES) . '">';
    $body .= '
<div class="diff-title diff-heading-layout">
    <div class="revision-title left">
        <h4><a href="?cmd=history&rev=' . $oldRev . '">' . Localization\Localize('history.revisionTitle', 'Revision as of {0}', date('Y-m-d H:i', $oldRev)) . '</a></h4>
    </div>
    <div class="gutter">
    </div>
    <div class="revision-title right">
        <h4><a href="?cmd=history&rev=' . $newRev . '">' . Localization\Localize('history.revisionTitle', 'Revision as of {0}', date('Y-m-d H:i', $newRev)) . '</a></h4>
    </div>
</div>
<div class="diff-fold-buttons diff-heading-layout">
    <div class="left">
        <button class="icon left-fold-icon" onclick="foldDiffLeft()"></button>
    </div>
    <div class="gutter">
        <button class="icon justify-icon" onclick="justifyDiffView()"></button>
    </div>
    <div class="right">
        <button class="icon right-fold-icon" onclick="foldDiffRight()"></button>
    </div>
</div>
<div id="diff"></div>
<script>

var newContent = document.getElementById("new-content").value;
var oldContent = document.getElementById("old-content").value;
var diffStyleLight =  document.getElementById("diff-style-light");
var diffStyleDark =  document.getElementById("diff-style-dark");

var differ = new AceDiff({
    element: "#diff",
    left: {
        content: oldContent,
        editable: false,
        copyLinkEnabled: false,
    },
    right: {
      content: newContent,
      editable: false,
      copyLinkEnabled: false,
    },
});
differ.getEditors().left.renderer.setShowGutter(false);
differ.getEditors().right.renderer.setShowGutter(false);

if(ThemeChanger) {
    onChangeTheme();
    ThemeChanger.onChangeThemeCallbacks.push(onChangeTheme);
}
function onChangeTheme() {
    if(ThemeChanger.getCurrentTheme() == "dark") {
        differ.getEditors().left.setTheme("ace/theme/twilight");
        differ.getEditors().right.setTheme("ace/theme/twilight");
        diffStyleLight.disabled = true;
        diffStyleDark.disabled = false;
    }
    else {
        differ.getEditors().left.setTheme("ace/theme/textmate");
        differ.getEditors().right.setTheme("ace/theme/textmate");
        diffStyleLight.disabled = false;
        diffStyleDark.disabled = true;
    }
}

function setDisplay(element, value) {
    if(element) element.style.display = value;
}
function foldDiffLeft() {
    setDisplay(document.querySelector(".diff-title .left"), "none");
    setDisplay(document.querySelector(".diff-fold-buttons .left"), "none");
    setDisplay(document.querySelector("#diff .acediff__left"), "none");

    setDisplay(document.querySelector(".diff-title .right"), "block");
    setDisplay(document.querySelector(".diff-fold-buttons .right"), "block");
    setDisplay(document.querySelector("#diff .acediff__right"), "block");
}
function foldDiffRight() {
    setDisplay(document.querySelector(".diff-title .left"), "block");
    setDisplay(document.querySelector(".diff-fold-buttons .left"), "block");
    setDisplay(document.querySelector("#diff .acediff__left"), "block");

    setDisplay(document.querySelector(".diff-title .right"), "none");
    setDisplay(document.querySelector(".diff-fold-buttons .right"), "none");
    setDisplay(document.querySelector("#diff .acediff__right"), "none");
}
function justifyDiffView() {
    setDisplay(document.querySelector(".diff-title .left"), "block");
    setDisplay(document.querySelector(".diff-fold-buttons .left"), "block");
    setDisplay(document.querySelector("#diff .acediff__left"), "block");
    
    setDisplay(document.querySelector(".diff-title .right"), "block");
    setDisplay(document.querySelector(".diff-fold-buttons .right"), "block");
    setDisplay(document.querySelector("#diff .acediff__right"), "block");
}
</script>
    ';

    $vars['additionalHeadScript'] = $head;
    $vars['contentSummary'] = $summary;
    $vars['contentBody'] = $body;

    $stopwatch->Stop();
    $vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;
    require(FRONTEND_DIR . '/viewer.php');
    exit();
}

$head = '';
$head .= '
<style>
#revisions-form button[disabled] {
    opacity: .5;
    cursor: auto;
}
#revisions-form button {
    color: inherit;
    font: inherit;
    border: 1px solid #cccccc;
    border-radius: 2px;
    padding: 2px 12px;
    cursor: pointer;
    background-color: rgba(239, 239, 239, .1);
}
#revisions-form button:not([disabled]):hover {
    filter: brightness(90%);
}

#revisions-form ul.rev-list {
    list-style-type: none;
    margin: 0.3em 0;
}

#revisions-form ul.rev-list li {
    margin: 0;
    padding: 0.3em 0;
}
#revisions-form ul.rev-list li span.list-marker {
    display: table-cell;
    padding-left: 0;
    padding-right: 0.5em;
}
#revisions-form ul.rev-list li span.list-content {
    display: table-cell;
}
span.rev-date {
    display: inline-block;
    padding-right: 1em;
}
a.rev-link {
    display: inline-block;
}
span.diff-bytes {
    font-size: 80%;
    display: inline-block;
    color: #7a7c7d;
}
span.diff-bytes.positive {
    color: #28a745;
}
span.diff-bytes.negative {
    color: #d73a49;
}
</style>
';
$body = '';

$body .= '<h3>' . Localization\Localize('history.revisions', 'Revisions') . '</h3>';
$body .= '<form id="revisions-form" method="GET">';
$body .= '<input type="hidden" name="cmd" value="history">';
$body .= '<ul class="rev-list">';

$prevBytes = 0;
$diffBytes = [];
foreach (array_reverse($revisions, true) as $ts => $content) {
    $bytes = strlen($content);
    $diffBytes[$ts] = $bytes - $prevBytes;
    $prevBytes = $bytes;
}
foreach ($revisions as $ts => $content) {
    $body .= '<li>';

    $body .= '<span class="list-marker">';
    $body .= '<input type="checkbox" name="diff[]" value="' . $ts . '">';
    $body .= '</span>';

    $body .= '<span class="list-content">';
    $body .= '<span class="rev-date">' . date('Y-m-d H:i', $ts) . '</span>';
    $body .= '<a class="rev-link" href="?cmd=history&rev=' . $ts . '">' . $contentTitle . '</a> ';
    if ($diffBytes[$ts] == 0) {
        $body .= '<span class="diff-bytes">';
        $body .= '±' . $diffBytes[$ts];
    } elseif ($diffBytes[$ts] > 0) {
        $body .= '<span class="diff-bytes positive">';
        $body .= '+' . $diffBytes[$ts];
    } else {
        $body .= '<span class="diff-bytes negative">';
        $body .= $diffBytes[$ts];
    }
    $body .= ' B</span>';
    $body .= '</span>'; // End list-content
    $body .= '</li>';
}
$body .= '</ul>';
$body .= '<button type="submit" disabled>' . Localization\Localize('history.compare', 'Compare selected revisions') . '</button>';
$body .= '</form>';
$body .= '
<script>
var revCheckboxs = document.querySelectorAll("#revisions-form input[type=checkbox]");
var revCompareButton = document.querySelector("#revisions-form button")

revCheckboxs.forEach(checkbox => {
    checkbox.addEventListener("change", updateSelectedRevision);
});

window.addEventListener("load", updateSelectedRevision);

function updateSelectedRevision() {
    let count = countCheckedRev();
    let readyCompare = (count == 2);
    revCompareButton.disabled = !readyCompare;
    if(readyCompare) {
        lockRevCheckboxs();
    }
    else {
        unlockAllRevCheckboxs();
    }
}
function lockRevCheckboxs() {
    revCheckboxs.forEach(checkbox => {
        if(!checkbox.checked) {
            checkbox.disabled = true;
        }
    });
}
function unlockAllRevCheckboxs() {
    revCheckboxs.forEach(checkbox => {
        checkbox.disabled = false;
    });
}
function countCheckedRev() {
    let count = 0;
    revCheckboxs.forEach(checkbox => {
        if(checkbox.checked) count++;
    });
    return count;
}
</script>
';

$vars['additionalHeadScript'] = $head;
$vars['contentSummary'] = $summary;
$vars['contentBody'] = $body;

$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;
require(FRONTEND_DIR . '/viewer.php');
exit();


function ExitWithInvalidParameterError()
{
    global $vars;
    $vars['errorMessage'] = Localization\Localize('invalidParameter', 'Invalid Parameter.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}
