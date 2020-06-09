<?php

require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/Authenticator.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";

set_error_handler('ErrorHandling\PlainErrorHandler');


Authenticator::RequireLoginedSession();
$username = Authenticator::GetLoginedUsername();

require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';

ServiceUtils\RequirePostMethod();
ServiceUtils\ValidateCsrfToken();
ServiceUtils\RequireParams('cmd');
$cmd = $_POST['cmd'];

if($cmd === 'GetFileList') {
    ServiceUtils\RequireParams('directoryPath', 'pattern');
    $directoryPath = $_POST['directoryPath'];
    $pattern = $_POST['pattern'];

    $fileList = [];
    $response = ['isOk' => false, 'fileList' => []];

    if(!Authenticator::IsFileOwner($directoryPath, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = Content::RealPath($directoryPath, '');
    if($realPath === false){
        ServiceUtils\SendErrorResponseAndExit('Not exists.');
    }
    
    foreach(glob($realPath . '/' . $pattern, GLOB_BRACE) as $file){
        if(is_file($file) && ValidateFileName($file)){
            $fileList[] = Content::RelativePath($file);
        }
    }
    
    $response['isOk'] = true;
    $response['fileList'] = $fileList;

    ServiceUtils\SendResponseAndExit($response);
}

elseif($cmd === 'GetDirectoryList') {
    ServiceUtils\RequireParams('directoryPath');
    $directoryPath = $_POST['directoryPath'];

    $directoryList = [];
    $response = ['isOk' => false, 'directoryList' => []];
    
    if(!Authenticator::IsFileOwner($directoryPath, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = Content::RealPath($directoryPath, '');
    if($realPath === false){
        ServiceUtils\SendErrorResponseAndExit('Not exists.');
    }

    foreach(glob($realPath . '/*', GLOB_ONLYDIR) as $directory){
        $directoryList[] = Content::RelativePath($directory);
    }

    $response['isOk'] = true;
    $response['directoryList'] = $directoryList;
    
    ServiceUtils\SendResponseAndExit($response);
}

elseif($cmd === 'CreateNewFile') {
    ServiceUtils\RequireParams('filePath');
    $filePath = $_POST['filePath'];

    $response = ['isOk' => false, 'filePath' => $filePath];

    if(!Authenticator::IsFileOwner($filePath, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    if(!ValidateFileName($filePath)){
        ServiceUtils\SendErrorResponseAndExit('Invalid extention.');
    }
    
    $realPath = Content::RealPath($filePath, '', false);
    if(file_exists($realPath)){
        ServiceUtils\SendErrorResponseAndExit('File Already exists.');
    }

    if(file_put_contents($realPath, '') === false){
        ServiceUtils\SendErrorResponseAndExit('Failed to create a file( ' . $filePath . ' ).');
    }

    $response['filePath'] = Content::RelativePath($realPath);
    $response['isOk'] = true;
    
    ServiceUtils\SendResponseAndExit($response);
}

elseif($cmd === 'CreateNewDirectory') {
    ServiceUtils\RequireParams('directoryPath');
    $directoryPath = $_POST['directoryPath'];

    $response = ['isOk' => false, 'directoryPath' => $directoryPath];

    if(!Authenticator::IsFileOwner($directoryPath, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = Content::RealPath($directoryPath, '', false);
    if(file_exists($realPath)){
        ServiceUtils\SendErrorResponseAndExit('Directory Already exists.');
    }

    if(!@mkdir($realPath)){
        ServiceUtils\SendErrorResponseAndExit('Failed to create New Directory( ' . $directoryPath . ' ).');
    }

    $response['directoryPath'] = Content::RelativePath($realPath);
    $response['isOk'] = true;

    ServiceUtils\SendResponseAndExit($response);
}

elseif($cmd === 'DeleteFile') {
    ServiceUtils\RequireParams('filePath');
    $filePath = $_POST['filePath'];

    $response = ['isOk' => false, 'filePath' => $filePath];

    if(!Authenticator::IsFileOwner($filePath, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    if(!ValidateFileName($filePath)){
        ServiceUtils\SendErrorResponseAndExit('Invalid extention.');
    }
    
    $realPath = Content::RealPath($filePath, '', false);
    if(!file_exists($realPath)){
        ServiceUtils\SendErrorResponseAndExit('File not exists.');
    }

    if(!unlink($realPath)){
        ServiceUtils\SendErrorResponseAndExit('Cannot delete file( ' . $filePath . ' ).');
    }
    
    $response['filePath'] = Content::RelativePath($realPath);
    $response['isOk'] = true;

    ServiceUtils\SendResponseAndExit($response);
}

elseif($cmd === 'DeleteDirectory') {
    ServiceUtils\RequireParams('directoryPath');
    $directoryPath = $_POST['directoryPath'];

    $response = ['isOk' => false, 'directoryPath' => $directoryPath];

    if(!Authenticator::IsFileOwner($directoryPath, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = Content::RealPath($directoryPath, '', false);
    if(!file_exists($realPath)){
        ServiceUtils\SendErrorResponseAndExit('Directory Not Exists.');
    }

    if(!@rmdir($realPath)){
        ServiceUtils\SendErrorResponseAndExit('Cannot Delete Directory( ' . $directoryPath . ' ).');
    }

    $response['directoryPath'] = Content::RelativePath($realPath);
    $response['isOk'] = true;
    
    ServiceUtils\SendResponseAndExit($response);
}

elseif($cmd === 'Rename') {
    ServiceUtils\RequireParams('oldName', 'newName');
    $oldName = $_POST['oldName'];
    $newName = $_POST['newName'];

    $response = ['isOk' => false, 'newName' => $newName, 'oldName' => $oldName];

    if(!Authenticator::IsFileOwner($newName, $username) || !Authenticator::IsFileOwner($oldName, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $oldRealPath = Content::RealPath($oldName, '');
    if($oldRealPath === false){
        ServiceUtils\SendErrorResponseAndExit('Not exists.');
    }

    $newRealPath = Content::RealPath($newName, '', false);
    if(is_file($oldRealPath) && (!ValidateFileName($oldName) || !ValidateFileName($newName))){
        ServiceUtils\SendErrorResponseAndExit('Invalid extention.');
    }

    if(!@rename($oldRealPath, $newRealPath)){
        ServiceUtils\SendErrorResponseAndExit("Cannot rename. $oldName -> $newName");
    }

    $response['oldName'] = Content::RelativePath($oldRealPath);
    $response['newName'] = Content::RelativePath($newRealPath);
    $response['isOk'] = true;
        
    ServiceUtils\SendResponseAndExit($response);
}

elseif($cmd === 'UploadFile') {
    ServiceUtils\RequireParams('directoryPath');
    $directoryPath = $_POST['directoryPath'];

    $response = ['isOk' => false, 'filePath' => ''];

    if(!is_uploaded_file($_FILES['upFile']['tmp_name'])){
        ServiceUtils\SendErrorResponseAndExit('No upload file.');
    }

    $filePath = $directoryPath . "/" .$_FILES['upFile']['name'];
    if(!Authenticator::IsFileOwner($filePath, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    if(!ValidateFileName($filePath)){
        ServiceUtils\SendErrorResponseAndExit('Invalid Extention.');
    }
    
    $realPath = Content::RealPath($filePath, '', false);
    if(!move_uploaded_file($_FILES['upFile']['tmp_name'], $realPath)){
        ServiceUtils\SendErrorResponseAndExit('Cannot upload.');
    }

    $response['filePath'] = $filePath;
    $response['isOk'] = true;

    ServiceUtils\SendResponseAndExit($response);
}

ServiceUtils\SendErrorResponseAndExit('Unrecognized command.');

function ValidateFileName($fileName){
    return true;
}

function IsContentFile($fileName){
    return GetExtention($fileName) === '.content';
}
