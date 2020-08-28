<?php

require_once(MODULE_DIR . '/ContentsDatabaseManager.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');
require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/ContentHistory.php');
require_once(MODULE_DIR . '/Localization.php');
require_once(MODULE_DIR . '/Stopwatch.php');
require_once(MODULE_DIR . '/Authenticator.php');

$stopwatch = new Stopwatch();
$stopwatch->Start();

$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates'] = [];
$vars['contentSummary'] = '';
$vars['contentBody'] = '';
$vars['childList'] = [];

Authenticator::GetUserInfo($vars['owner'], 'enableRemoteEdit',  $enableRemoteEdit);

$currentContentPathInfo = ContentsDatabaseManager::GetContentPathInfo($vars['contentPath']);
$articleContentPath = $currentContentPathInfo['dirname'] . '/' . $currentContentPathInfo['filename'] . ContentsDatabaseManager::GetLayerSuffix($currentContentPathInfo['layername']);
$isNoteFile = in_array('note', $currentContentPathInfo['extentions']);

$currentContent = new Content();
$existsCurrentContent = $currentContent->SetContent($vars['contentPath']);

$articleContent = new Content();
$existsArticleContent = $articleContent->SetContent($articleContentPath);

$contentTitle = NotBlankText([$articleContent->title, $currentContentPathInfo['filename']]);
if($isNoteFile) {
    $contentTitle = Localization\Localize('note', 'Note') . ': ' . $contentTitle;
}

$history = ContentHistory\GetHistory($vars['contentPath']);
$revisions = $history['revisions'] ?? [];
krsort($revisions);

$vars['rootContentPath'] = ContentsDatabaseManager::GetRelatedRootFile($vars['contentPath']);
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);
$vars['pageHeading']['parents'] = [];

$vars['navigator'] = '<nav class="navi"><ul><li>' . Localization\Localize('temporarilyUnavailable', 'Temporarily Unavailable') . '</li></ul></nav>';
if($existsCurrentContent && GetNavigatorFromCache($articleContentPath, $navi)) {
    $vars['navigator'] = $navi;
}
elseif(GetNavigatorFromCache($vars['rootContentPath'], $navi)) {
    $vars['navigator'] = $navi;
}

$vars['pageTitle'] = Localization\Localize('history.historyTitle', '{0}: Revision history', $contentTitle);
$vars['pageHeading']['title'] = $vars['pageTitle'];


if(!$existsCurrentContent && empty($revisions)) {
    require(FRONTEND_DIR . '/404.php');
    exit();
}

$vars['leftPageTabs'] = [];
$vars['leftPageTabs'][] = [
    'selected' => !$isNoteFile, 
    'innerHTML' => 
        '<a href="' . 
        CreateContentHREF($articleContentPath) .
        '">' . Localization\Localize('content', 'Content') . '</a>'
];
$vars['leftPageTabs'][] = [
    'selected' => $isNoteFile, 
    'innerHTML' => 
        '<a href="' . 
        CreateContentHREF($articleContentPath . '.note') .
        '">' . Localization\Localize('note', 'Note') . '</a>'
];
$vars['leftPageTabs'][] = [
    'selected' => false, 
    'innerHTML' => 
        '<a href="' . 
        CreateDirectoryHREF(dirname($vars['subURI']), $vars['language']) . 
        '">' . Localization\Localize('directory', 'Directory') . '</a>'
];
$vars['rightPageTabs'] = [];

$vars['rightPageTabs'][] = [
    'selected' => true,
    'innerHTML' => 
        '<a href="?cmd=history"' .
        '>' . Localization\Localize('history', 'History') .'</a>'
];
$vars['rightPageTabs'][] = [
    'selected' => false,
    'innerHTML' => 
        '<a href="?cmd=edit"' . ($enableRemoteEdit ? ' target="_blank"' : '') .
        '>' . Localization\Localize('edit', 'Edit') .'</a>'
];

if(empty($revisions)) {
    $summary = '<p>' . Localization\Localize('history.notFoundHistory', 'Not found history.') . '</p>';
    $vars['contentSummary'] = $summary;
    
    $stopwatch->Stop();
    $vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;
    require(FRONTEND_DIR . '/viewer.php');
    exit();
}

$summary = '';

if(!$existsCurrentContent) {
    $summary .= '<p>' . Localization\Localize('history.notFoundCurrentContent', 'The revision history is still there, but the actual content file "{0}" is missing. It might have been moved or deleted.', $currentContentPathInfo['basename'] . '.content') .'</p>';
}

if(isset($_GET['rev'])) {
    $rev = $_GET['rev'];
    if(!is_numeric($rev)) {
        ExitWithInvalidParameterError();
    }
    if(!array_key_exists($rev, $revisions)) {
        $summary .= '<p>' . Localization\Localize('history.notFoundRevision', 'The revision #{0} of the page named "{1}" does not exist.', $rev, $contentTitle) . '</p>';
        $vars['contentSummary'] = $summary;
        
        require(FRONTEND_DIR . '/viewer.php');
        exit();
    }
    
    $head = '';
    $head .= '<script src="' . CLIENT_URI . '/ace/src-min/ace.js" type="text/javascript" charset="utf-8"></script>';
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
    $body .= '<div id="source-view">'. H($revisions[$rev], ENT_QUOTES) . '</div>';
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
}
elseif(isset($_GET['diff'])) {
    $diff = $_GET['diff'];
    if(count($diff) != 2 || !is_numeric($diff[0]) || !is_numeric($diff[1]) ) {
        ExitWithInvalidParameterError();
    }
    $oldRev = $diff[0];
    $newRev = $diff[1];
    if($oldRev > $newRev) list($oldRev, $newRev) = array($newRev, $oldRev);

    $vars['pageTitle'] = Localization\Localize('history.diffTitle', '{0}: Difference between revisions', $contentTitle);
    $vars['pageHeading']['title'] = $vars['pageTitle'];

    $notFound = false;
    if(!array_key_exists($oldRev, $revisions)) {
        $summary .= '<p>' . Localization\Localize('history.notFoundRevision', 'The revision #{0} of the page named "{1}" does not exist.', $oldRev, $contentTitle) . '</p>';
        $notFound = true;
    }
    if(!array_key_exists($newRev, $revisions)) {
        $summary .= '<p>' . Localization\Localize('history.notFoundRevision', 'The revision #{0} of the page named "{1}" does not exist.', $newRev, $contentTitle) . '</p>';
        $notFound = true;
    }
    if($notFound) {
        $vars['contentSummary'] = $summary;
        
        require(FRONTEND_DIR . '/viewer.php');
        exit();
    }

    $head = '';
    $head .= '<script src="' . CLIENT_URI . '/ace/src-min/ace.js" type="text/javascript" charset="utf-8"></script>';
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
.diff-title {
    display: flex;
    justify-content: space-around;
}
.revision-title {
    text-align: center;
    padding: 0.33em 0.5em;
    vertical-align: top;
}
</style>
    ';
    $body = '';
    
    $body .= '<input type="hidden" id="new-content" value="' . H($revisions[$newRev], ENT_QUOTES) . '">';
    $body .= '<input type="hidden" id="old-content" value="' . H($revisions[$oldRev], ENT_QUOTES) . '">';
    $body .= '
<div class="diff-title">
    <div class="revision-title">
        <h4><a href="?cmd=history&rev=' . $oldRev . '">' . Localization\Localize('history.revisionTitle', 'Revision as of {0}', date('Y-m-d H:i', $oldRev)) . '</a></h4>
        <div>&nbsp;</div>
    </div>
    <div class="revision-title">
        <h4><a href="?cmd=history&rev=' . $newRev . '">' . Localization\Localize('history.revisionTitle', 'Revision as of {0}', date('Y-m-d H:i', $newRev)) . '</a></h4>
        <div>&nbsp;</div>
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


$body = '';

$body .= '<h3>' . Localization\Localize('history.revisions', 'Revisions') . '</h3>';
$body .= '<form id="rev-list" method="GET">';
$body .= '<input type="hidden" name="cmd" value="history">';
$body .= '<ul style="list-style-type: none;">';

$prevBytes = 0;
$diffBytes = [];
foreach(array_reverse($revisions, true) as $ts => $content) {
    $bytes = strlen($content);
    $diffBytes[$ts] = $bytes - $prevBytes;
    $prevBytes = $bytes;
}
foreach($revisions as $ts => $content) {
    $body .= '<li>';
    $body .= '<input type="checkbox" name="diff[]" value="' . $ts . '">';
    $body .= ' <span>' . date('Y-m-d H:i', $ts) . '</span> <span style="font-weight:bold;">–</span> ';
    $body .= '<a href="?cmd=history&rev=' . $ts . '">' . $contentTitle . '</a> ';
    if($diffBytes[$ts] == 0) {
        $body .= '<span style="font-size: 80%; color: #7a7c7d">';
        $body .= '±' . $diffBytes[$ts];
    }
    elseif($diffBytes[$ts] > 0) {
        $body .= '<span style="font-size: 80%; color: #28a745">';
        $body .= '+' . $diffBytes[$ts];
    }
    else {
        $body .= '<span style="font-size: 80%; color: #d73a49">';
        $body .= $diffBytes[$ts];
    }
    $body .= ' B</span> ';
    $body .= '</li>';
}
$body .= '</ul>';
$body .= '<button type="submit" disabled>' . Localization\Localize('history.compare', 'Compare selected revisions') . '</button>';
$body .= '</form>';
$body .= '
<script>
var revCheckboxs = document.querySelectorAll("#rev-list input[type=checkbox]");
var revCompareButton = document.querySelector("#rev-list button")

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
$vars['contentSummary'] = $summary;
$vars['contentBody'] = $body;

$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;
require(FRONTEND_DIR . '/viewer.php');
exit();


function ExitWithInvalidParameterError() {
    global $vars;
    $vars['errorMessage'] = Localization\Localize('invalidParameter', 'Invalid Parameter.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}