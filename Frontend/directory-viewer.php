<?php
/**
 * 参照する変数
 *  $vars['directoryPath'] = '/Master/Contents/Directory'
 */
require_once(MODULE_DIR . "/Stopwatch.php");
require_once(MODULE_DIR . "/ContentsViewerUtils.php");
require_once(MODULE_DIR . '/ContentsDatabaseManager.php');


$vars['warningMessages'] = [];
$vars['pageBuildReport']['times']['build'] = ['displayName' => 'Build Time', 'ms' => 0];
$vars['pageBuildReport']['updates'] = [];

// 計測開始
$stopwatch = new Stopwatch();
$stopwatch->Start();

$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME . ContentsDatabaseManager::GetLayerSuffix($vars['layerName']);
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);

$parents = [];
$iter = $vars['directoryPath'];
for($i = 0; $i < 3; $i++){
    $iter = dirname($iter);
    if($vars['rootDirectory'] === $iter){
        break;
    }
    $parents[] = $iter;
}

$currentDirname = basename($vars['directoryPath']);
$vars['pageTitle'] = Localization\Localize('directory', 'Directory') . ': ' . $currentDirname;
$vars['pageHeading']['title'] = '<span style="word-wrap: break-word;">' . $vars['pageTitle'] . '</span>';
if(count($parents) > 0){
    $vars['pageTitle'] .= ' | ' . basename($parents[0]);
}

$result = GetFilesAndSubDirs($vars['directoryPath']);
$subDirs = $result['subDirs'];
$files = [];
$contents = [];
foreach($result['files'] as $file){
    if(GetExtention($file) == '.content'){
        $contents[] = $file;
    }
    else{
        $files[] = $file;
    }
}


$vars['pageHeading']['parents'] = [];
foreach($parents as $parent){
    $vars['pageHeading']['parents'][] = [
        'title' => basename($parent), 'path' =>  CreateDirectoryHREF($parent, $vars['language'])
    ];
}

$vars['navigator'] = CreateNavi($parents, $vars['directoryPath'], $subDirs, $vars['language']);
$vars['childList'] = [];

if(count($subDirs) > 0 || count($contents) > 0 || count($files) > 0){
    $vars['contentSummary'] = '<p>' .
        Localization\Localize('directory-viewer.foundItemsInTheDirectory', 
        'Found{0}{1}{2} in this "<span style="word-wrap: break-word;">Directory: {3}</span>".',
        (count($subDirs) > 0 ? ', <em>' . Localization\Localize('nsubdirectories', '{0} subdirectories', count($subDirs))  . '</em>' : ''),
        (count($contents) > 0 ? ', <em>' . Localization\Localize('ncontents', '{0} contents', count($contents)) . '</em>' : ''),
        (count($files) > 0 ? ', <em>' . Localization\Localize('nfiles', '{0} files', count($files))  . '</em>' : ''),
        $vars['directoryPath']) . '</p>';
    }
else{
    $vars['contentSummary'] = '<p>' .
        Localization\Localize('directory-viewer.directoryIsEmpty', 
        'This "<span style="word-wrap: break-word;">Direcotry: {0}</span>" is empty.', $vars['directoryPath']) . 
        '</p>';
}
$body = '';

if(count($subDirs) > 0){
    $body .= '<h3>' . Localization\Localize('subdirectories', 'Subdirectories') . '</h3>';
    $body .= '<div class="directory-container">';
    foreach($subDirs as $subDir){
        $body .= '<a class="directory" href="' . CreateDirectoryHREF($subDir, $vars['language']) . '">';
        $body .= '<div class="icon folder-icon"></div>';
        $body .= '<div class="name">' . basename($subDir) . '</div>';
        $body .= '</a>';
    }
    $body .= '</div>';
}

if(count($contents) > 0){
    $body .= '<h3>' . Localization\Localize('contents', 'Contents') . '</h3>';
    $body .= '<ul class="child-list">';

    foreach ($contents as $contentPath) {
        $content = new Content();
        if($content->SetContent('.' . RemoveExtention($contentPath))){
            $text = GetDecodedText($content);
            $body .= '<li><div><div class="child-title">' .
                '<a href="'. CreateContentHREF($content->path) . '">' . 
                NotBlankText([$content->title, basename($content->path)]) . '</a>' .
                '</div><div class="child-summary">' . $text['summary'] . '</div></div></li>';
        }
    }
    $body .= '</ul>';
}

if(count($files) > 0){
    $body .= '<h3>' . Localization\Localize('files', 'Files') . '</h3>';
    $body .= '<div class="file-container">';
    foreach($files as $file){
        $body .= '<a class="file" href="' . CreateFileHREF($file) . '">';
        $body .= '<div class="thumbnail">';

        if(in_array(GetExtention($file), array('.jpg', '.jpeg', '.JPG', '.JPEG', '.png', '.PNG', '.bmp'))){
            $body .= '<img src="' . CreateFileHREF($file) .'">';
        }
        
        $body .= '</div>';
        $body .= '<div class="file-title"><div class="icon file-icon"></div>';
        $body .= '<div class="name">' . basename($file) . '</div></div>';
        $body .= '</a>';
    }
    $body .= '</div>';
}
$vars['contentBody'] = $body;

$vars['canonialUrl'] = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . 
    $_SERVER["HTTP_HOST"] . $vars['subURI'] . '?hl=' . $vars['layerName'];

// ビルド時間計測 終了
$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;


require(FRONTEND_DIR . '/viewer.php');

function CreateNavi($parents, $current, $children, $language){
    $navi = '<nav class="navi"><ul>';

    $parentIndex = -1;
    if(count($parents) > 0){
        $parentIndex = count($parents) - 1;
        $stack[] = $parents[$parentIndex];
    }
    else{
        $stack[] = $current;
    }
    
    while(!is_null($path = array_pop($stack))){
        if($path === true){
            $navi .= '</ul>';
            continue;
        }

        if(strpos($current, $path) === 0){
            $navi .= '<li><a class = "selected" href="' . 
                CreateDirectoryHREF($path, $language) . '">' . 
                basename($path) . '</a></li>';
        }
        else{
            $navi .= '<li><a href="' . 
                CreateDirectoryHREF($path, $language) . '">' . 
                basename($path) . '</a></li>';
        }

        if($parentIndex >= 0 && $path === $parents[$parentIndex]){
            $result = GetFilesAndSubDirs($path);

            // ディレクトリを抜けたときの印
            $stack[] = true;

            $stack = array_merge($stack, array_reverse($result['subDirs']));
            
            $navi .= '<ul>';

            $parentIndex--;
        }

        if($path === $current){
            // ディレクトリを抜けたときの印
            $stack[] = true;
            
            $stack = array_merge($stack, array_reverse($children));
            
            $navi .= '<ul>';
        }
    }
    $navi .= '</ul></nav>';
    return $navi;
}

function GetFilesAndSubDirs($directoryPath){
    $subDirs = [];
    $files = [];

    $cdir = scandir(CONTENTS_HOME_DIR . $directoryPath);
    foreach($cdir as $c){
        if (!in_array($c,array(".",".."))){
            if (is_dir(CONTENTS_HOME_DIR . $directoryPath . '/' . $c)){
                $subDirs[] = $directoryPath . '/' . $c;
            }
            else{
                $files[] = $directoryPath . '/' . $c;
            }
        }
    }

    return ['subDirs' => $subDirs, 'files' => $files];
}

function RemoveExtention($path){
    return substr($path, 0, strrpos($path, '.'));
}