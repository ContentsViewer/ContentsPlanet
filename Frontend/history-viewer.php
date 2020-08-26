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

$contentTitle = NotBlankText([$currentContent->title, basename($vars['contentPath'])]);

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

$rev = $_GET['rev'] ?? false;
$diff = $_GET['diff'] ?? false;

if($rev === false && $diff === false) {
    $summary = '';
    $summary .= Localization\Localize('history.', '');

    $body = '';

    $body .= '<ul style="list-style-type: none;">';
    foreach($revisions as $ts => $content) {
        $body .= '<li>';
        $body .= '<input type="checkbox">';
        $body .= '<span>' . date('Y-m-d H:i', $ts) . '</span>';
        $body .= '</li>';
    }
    $body .= '</ul>';
    $vars['contentBody'] = $body;
    
    $stopwatch->Stop();
    $vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;
    require(FRONTEND_DIR . '/viewer.php');
    exit();
}

if($rev !== false && $diff === false) {
    if(!array_key_exists($rev, $revisions)) {
        $summary = '<p>' . Localization\Localize('history.notFoundRevision', 'Not found revision.') . '</p>';
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
    height: 100vh;
    width: 100%;
}
</style>    
    ';
    $body = '';
    $body .= '<div id="source-view">'. H($revisions[$rev], ENT_QUOTES) . '</div>';
    $body .= '
<script>
var editor = ace.edit("source-view");
editor.setReadOnly(true);
editor.setTheme("ace/theme/textmate");

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
    $vars['contentBody'] = $body;

    $stopwatch->Stop();
    $vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;
    require(FRONTEND_DIR . '/viewer.php');
    exit();
}

if($rev !== false && $diff !== false) {
    if(!array_key_exists($rev, $revisions) || !array_key_exists($diff, $revisions)) {
        $summary = '<p>' . Localization\Localize('history.notFoundRevision', 'Not found revision.') . '</p>';
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
    height: 100vh;
    width: 100%;
}
</style>
    ';
    $body = '';
    
    $body .= '<input type="hidden" id="new-content" value="' . H($revisions[$rev], ENT_QUOTES) . '">';
    $body .= '<input type="hidden" id="old-content" value="' . H($revisions[$diff], ENT_QUOTES) . '">';
    $body .= '
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
        editable: true,
        copyLinkEnabled: false,
    },
    right: {
      content: newContent,
      editable: false,
      copyLinkEnabled: false,
    },
});

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
    $vars['contentBody'] = $body;
        
    $stopwatch->Stop();
    $vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;
    require(FRONTEND_DIR . '/viewer.php');
    exit();
}


$vars['errorMessage'] = Localization\Localize('invalidParameter', 'Invalid Parameter.');
require(FRONTEND_DIR . '/400.php');
exit();

