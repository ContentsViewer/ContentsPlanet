<?php

require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/Authenticator.php";


Authenticator::RequireLoginedSession();
$username = Authenticator::GetLoginedUsername();

require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";


$allowedExtentionMap = [
    '.txt' => null,
    '.jpg' => null,
    '.content' => null,
    '.png' => null,
    '.gif' => null,
    '.html' => null,
    '.bmp' => null,
    '.zip' => null,
    '.data' => null,
    '.pdf' => null
];

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    exit;
}


if(!isset($_POST['token']) || !Authenticator::ValidateCsrfToken($_POST['token'])){
    SendResponseAndExit(null);
}


if(!isset($_POST['cmd'])){
    SendResponseAndExit(null);
}


$cmd = $_POST['cmd'];

if($cmd === 'GetFileList' && 
    isset($_POST['directoryPath']) &&
    isset($_POST['pattern'])){

    $directoryPath = $_POST['directoryPath'];
    $pattern = $_POST['pattern'];
    $fileList = [];

    $response = ['isOk' => false, 'error' => '', 'fileList' => []];

    if(!Authenticator::IsFileOwner($directoryPath, $username)){
        SendErrorResponseAndExit($response, 'Permission denied.');
    }

    $realPath = Content::RealPath($directoryPath, '');
    if($realPath === false){
        SendErrorResponseAndExit($response, 'Not exists!');
    }
    
    foreach(glob($realPath . '/' . $pattern, GLOB_BRACE) as $file){
        if(is_file($file) && ValidateFileName($file)){
            //Debug::Log($file);
            //Debug::Log(Content::RelativePath($file));
            $fileList[] =  Content::RelativePath($file);
        }
    }
    
    $response['isOk'] = true;
    $response['fileList'] = $fileList;

    SendResponseAndExit($response);
}

elseif($cmd === 'GetDirectoryList' &&
       isset($_POST['directoryPath'])){

    $directoryPath = $_POST['directoryPath'];
    $directoryList = [];
    
    $response = ['isOk' => false, 'error' => '', 'directoryList' => []];
    
    if(!Authenticator::IsFileOwner($directoryPath, $username)){
        SendErrorResponseAndExit($response, 'Permission denied.');
    }

    $realPath = Content::RealPath($directoryPath, '');
    if($realPath === false){
        SendErrorResponseAndExit($response, 'Not exists!');
    }

    foreach(glob($realPath . '/*', GLOB_ONLYDIR) as $directory){
        $directoryList[] = Content::RelativePath($directory);
        //Debug::Log(RelativePath($directory));
    }

    $response['isOk'] = true;
    $response['directoryList'] = $directoryList;
    
    SendResponseAndExit($response);
}

elseif($cmd === 'CreateNewFile' &&
       isset($_POST['filePath']) ){
        
    $filePath = $_POST['filePath'];
    $response = ['isOk' => false, 'filePath' => $filePath, 'error' => ''];

    if(!Authenticator::IsFileOwner($filePath, $username)){
        SendErrorResponseAndExit($response, 'Permission denied.');
    }

    if(!ValidateFileName($filePath)){
        SendErrorResponseAndExit($response, 'Invalid extention.');
    }
    
    $realPath = Content::RealPath($filePath, '', false);

    if(file_exists($realPath)){
        SendErrorResponseAndExit($response, 'File Already exists.');
    }


    if(file_put_contents($realPath, '') === false){
        SendErrorResponseAndExit($response, 'Failed to create a file( ' . $filePath . ' ).');
    }

    $response['filePath'] = Content::RelativePath($realPath);
    $response['isOk'] = true;
    
    SendResponseAndExit($response);
}


elseif($cmd === 'CreateNewDirectory' &&
        isset($_POST['directoryPath']) ){
        
    $directoryPath = $_POST['directoryPath'];


    $response = ['isOk' => false, 'directoryPath' => $directoryPath, 'errors' => ''];

    if(!Authenticator::IsFileOwner($directoryPath, $username)){
        SendErrorResponseAndExit($response, 'Permission denied.');
    }

    $realPath = Content::RealPath($directoryPath, '', false);

    if(file_exists($realPath)){
        SendErrorResponseAndExit($response, 'Directory Already exists.');
    }

    if(!@mkdir($realPath)){
        SendErrorResponseAndExit($response, 'Failed to create New Directory( ' . $directoryPath . ' ).');
    }

    
    $response['directoryPath'] = Content::RelativePath($realPath);
    $response['isOk'] = true;

    SendResponseAndExit($response);

}
elseif($cmd === 'DeleteFile' &&
        isset($_POST['filePath'])){

    $filePath = $_POST['filePath'];
    //echo unlink($filePath);
    //exit;
    $response = ['isOk' => false, 'filePath' => $filePath, 'error' => ''];


    if(!Authenticator::IsFileOwner($filePath, $username)){
        SendErrorResponseAndExit($response, 'Permission denied.');
    }

    if(!ValidateFileName($filePath)){
        SendErrorResponseAndExit($response, 'Invalid extention.');
    }
    
    $realPath = Content::RealPath($filePath, '', false);

    if(!file_exists($realPath)){
        SendErrorResponseAndExit($response, 'File not exists.');
    }

    if(!unlink($realPath)){
        SendErrorResponseAndExit($response, 'Cannot delete file( ' . $filePath . ' ).');
    }
    
    $response['filePath'] = Content::RelativePath($realPath);
    $response['isOk'] = true;

    SendResponseAndExit($response);

}

elseif($cmd === 'DeleteDirectory' && 
        isset($_POST['directoryPath'])){
        
    $directoryPath = $_POST['directoryPath'];
    //echo unlink($filePath);
    //exit;
    $response = ['isOk' => false, 'directoryPath' => $directoryPath];

    if(!Authenticator::IsFileOwner($directoryPath, $username)){
        SendErrorResponseAndExit($response, 'Permission denied.');
    }

    $realPath = Content::RealPath($directoryPath, '', false);

    if(!file_exists($realPath)){
        SendErrorResponseAndExit($response, 'Directory Not Exists.');
    }

    if(!@rmdir($realPath)){
        SendErrorResponseAndExit($response, 'Cannot Delete Directory( ' . $directoryPath . ' ).');
    }

    $response['directoryPath'] = Content::RelativePath($realPath);
    $response['isOk'] = true;
    
    SendResponseAndExit($response);
}


elseif($cmd === 'Rename' && 
        isset($_POST['oldName']) &&
        isset($_POST['newName']) ){
        
    $oldName = $_POST['oldName'];
    $newName = $_POST['newName'];

    
    $response = ['isOk' => false, 'newName' => $newName, 'oldName' => $oldName];

    if(!Authenticator::IsFileOwner($newName, $username) || !Authenticator::IsFileOwner($oldName, $username)){
        SendErrorResponseAndExit($response, 'Permission denied.');
    }

    $oldRealPath = Content::RealPath($oldName, '');
    if($oldRealPath === false){
        SendErrorResponseAndExit($response, 'Not exists!');
    }

    $newRealPath = Content::RealPath($newName, '', false);

    if(is_file($oldRealPath) && (!ValidateFileName($oldName) || !ValidateFileName($newName))){
        SendErrorResponseAndExit($response, 'Invalid extention.');
    }


    if(!@rename($oldRealPath, $newRealPath)){
        SendErrorResponseAndExit($response, "Cannot rename. $oldName -> $newName");
    }

    $response['oldName'] = Content::RelativePath($oldRealPath);
    $response['newName'] = Content::RelativePath($newRealPath);
    $response['isOk'] = true;
        
    SendResponseAndExit($response);
}

elseif($cmd === 'UploadFile' &&
        isset($_POST['directoryPath'])){
    
    $directoryPath = $_POST['directoryPath'];

    $response = ['isOk' => false, 'filePath' => ''];


    if(!is_uploaded_file($_FILES['upFile']['tmp_name'])){
        SendErrorResponseAndExit($response, 'No upload file.');
    }

    $filePath = $directoryPath . "/" .$_FILES['upFile']['name'];

    if(!Authenticator::IsFileOwner($filePath, $username)){
        SendErrorResponseAndExit($response, 'Permission denied.');
    }

    if(!ValidateFileName($filePath)){
        SendErrorResponseAndExit($response, 'Invalid Extention.');
    }
    
    $realPath = Content::RealPath($filePath, '', false);

    if(!move_uploaded_file($_FILES['upFile']['tmp_name'], $realPath)){
        SendErrorResponseAndExit($response, 'Cannot upload.');
    }

    $response['filePath'] = $filePath;
    $response['isOk'] = true;

    SendResponseAndExit($response);

}

SendResponseAndExit(null);


function SendErrorResponseAndExit($response, $error){
    $response['error'] = $error;
    SendResponseAndExit($response);
}

function SendResponseAndExit($response){
    echo json_encode($response);
    exit;
}

function GetExtention($path){
    return substr($path, strrpos($path, '.'));
}

function ValidateFileName($fileName){
    return true;
    // global $allowedExtentionMap;
    // return array_key_exists(GetExtention($fileName), $allowedExtentionMap);
}

function IsContentFile($fileName){
    return GetExtention($fileName) === '.content';
}
