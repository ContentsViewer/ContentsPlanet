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

$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME;
$vars['rootDirectory'] = substr(GetTopDirectory($vars['rootContentPath']), 1);

$currentDirname = basename($vars['directoryPath']);
$vars['pageTitle'] = 'ディレクトリ: ' . $currentDirname;
$vars['pageHeading']['title'] = '<span style="word-wrap: break-word;">' . $vars['pageTitle'] . '</span>';

$parents = [];
$iter = $vars['directoryPath'];
for($i = 0; $i < 3; $i++){
    $iter = dirname($iter);
    if($vars['rootDirectory'] === $iter){
        break;
    }
    $parents[] = $iter;
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
    $vars['pageHeading']['parents'][] = ['title' => basename($parent), 'path' =>  CreateDirectoryHREF($parent)];
}

$vars['navigator'] = CreateNavi($parents, $vars['directoryPath'], $subDirs);
$vars['childList'] = [];

if(count($subDirs) <= 0 && count($contents) <= 0 && count($files) <= 0){
    $vars['contentSummary'] = '「<span style="word-wrap: break-word;">ディレクトリ: ' . $vars['directoryPath'] . '</span>」内で何も見つかりませんでした. ';
    
}
else{
    $vars['contentSummary'] = '「<span style="word-wrap: break-word;">ディレクトリ: ' . $vars['directoryPath'] . '</span>」内で' . 
    (count($subDirs) > 0 ? ', <em>' . count($subDirs) . '件のサブディレクトリ</em>' : '') . 
    (count($contents) > 0 ? ', <em>' . count($contents) . '件のコンテンツ</em>' : '') . 
    (count($files) > 0 ? ', <em>' . count($files) . '件のファイル</em>' : '') . 
    ' が見つかりました. ';
}
$body = '';

if(count($subDirs) > 0){
    $body .= '<h3>サブディレクトリ</h3>';
    $body .= '<div class="directory-container">';
    foreach($subDirs as $subDir){
        $body .= '<a class="directory" href="' . CreateDirectoryHREF($subDir) . '">';
        $body .= '<div class="icon folder-icon"></div>';
        $body .= '<div class="name">' . basename($subDir) . '</div>';
        $body .= '</a>';
    }
    $body .= '</div>';
}

if(count($contents) > 0){
    $body .= '<h3>コンテンツ</h3>';
    $body .= '<ul class="child-list">';

    foreach ($contents as $contentPath) {
        $content = new Content();
        if($content->SetContent('.' . RemoveExtention($contentPath))){
            $text = GetDecodedText($content);
            $body .= '<li><div><div class="child-title">' .
                '<a href="'. CreateContentHREF($content->path) . '">' . NotBlankTitle($content->title) . '</a>' .
                '</div><div class="child-summary">' . $text['summary'] . '</div></div></li>';
        }
    }
    $body .= '</ul>';
}

if(count($files) > 0){
    $body .= '<h3>ファイル</h3>';
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

// ビルド時間計測 終了
$stopwatch->Stop();
$vars['pageBuildReport']['times']['build']['ms'] = $stopwatch->Elapsed() * 1000;


require(FRONTEND_DIR . '/viewer.php');

function CreateNavi($parents, $current, $children){
    $navi = '<nav class="navi"><ul>';

    $parentIndex = -1;
    if(count($parents) > 0){
        $parentIndex = count($parents) - 1;
        $stack[] = $parents[$parentIndex];
    }
    else{
        $stack[] = $current;
    }

    $stackCount = 1;
    
    while($stackCount > 0){
        $path = array_pop($stack);
        $stackCount--;

        if($path === true){
            $navi .= '</ul>';
            continue;
        }

        if(strpos($current, $path) === 0){
            $navi .= '<li><a class = "selected" href="' . 
                CreateDirectoryHREF($path) . '">' . 
                basename($path) . '</a></li>';
        }
        else{
            $navi .= '<li><a href="' . 
                CreateDirectoryHREF($path) . '">' . 
                basename($path) . '</a></li>';
        }

        if($parentIndex >= 0 && $path === $parents[$parentIndex]){
            $result = GetFilesAndSubDirs($path);

            // ディレクトリを抜けたときの印
            $stack[] = true;

            $stack = array_merge($stack, array_reverse($result['subDirs']));
            $stackCount += count($result['subDirs']);
            
            $navi .= '<ul>';

            $parentIndex--;
        }

        if($path === $current){
            // ディレクトリを抜けたときの印
            $stack[] = true;
            
            $stack = array_merge($stack, array_reverse($children));
            $stackCount += count($children);
            
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