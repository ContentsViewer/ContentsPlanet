<?php
require_once(MODULE_DIR . "/ContentDatabaseControls.php");
require_once(MODULE_DIR . "/Authenticator.php");

use ContentDatabaseControls as DBControls;


$vars['rootContentPath'] = DBControls\DefalutRootContentPath();
$vars['showRootChildren'] = false;
$vars['showPrivateIcon'] = false;

$layerSuffix = DBControls\GetLayerSuffix($vars['layerName']);

if(!isset($vars['owner']) || $vars['owner'] === false){
    // ownerが設定されていないとき
    // ログインユーザのページを使う
    // 無ければ, defalutを使う

    // セッション開始
    @session_start();
    $loginedUser = Authenticator::GetLoginedUsername();

    if($loginedUser !== false 
       && Authenticator::GetUserInfo($loginedUser, 'contentsFolder', $contentsFolder)){
        $vars['rootContentPath'] = $contentsFolder . '/' . ROOT_FILE_NAME . $layerSuffix;
        $vars['showRootChildren'] = true; // すでにログイン済み

        $isPublic = false;
        Authenticator::GetUserInfo($loginedUser, 'isPublic', $isPublic);
        $vars['showPrivateIcon'] = !$isPublic;
    }
    else{
        $isAuthorized = true;
        $isPublic = true;

        $owner = Authenticator::GetFileOwnerName($vars['rootContentPath']);
        if($owner !== false){
            Authenticator::GetUserInfo($owner, 'isPublic', $isPublic);
        }
        
        if (!$isPublic) {
            if ($loginedUser !== $owner) {
                $isAuthorized = false;
            }
        }

        if($isPublic || $isAuthorized){
            $vars['showRootChildren'] = true;
        }

        $vars['showPrivateIcon'] = !$isPublic;
    }
}

if(isset($vars['owner']) && $vars['owner'] !== false){
    $vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME . $layerSuffix;
    $vars['showRootChildren'] = false;
        
    if(isset($vars['isPublic']) && $vars['isPublic']){
        $vars['showRootChildren'] = true;
    }
    if(isset($vars['isAuthorized']) && $vars['isAuthorized']){
        $vars['showRootChildren'] = true;
    }
    if(isset($vars['isPublic'])){
        $vars['showPrivateIcon'] = !$vars['isPublic'];
    }
}

if(!isset($vars['errorMessage'])) $vars['errorMessage'] = '';
