<?php

require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/Authenticator.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";
require_once dirname(__FILE__) . "/../Module/ContentDatabase.php";
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . '/../Module/PathUtils.php';

set_error_handler('ErrorHandling\PlainErrorHandler');

ServiceUtils\RequireLoginedSession();
ServiceUtils\RequirePostMethod();
ServiceUtils\ValidateCsrfToken();
ServiceUtils\RequireParams('cmd');
$cmd = $_POST['cmd'];
$username = Authenticator::GetLoginedUsername();

if ($cmd === 'GetFileList') {
    ServiceUtils\RequireParams('directoryPath', 'pattern');
    $directoryPath = $_POST['directoryPath'];
    $pattern = $_POST['pattern'];

    $fileList = [];
    $response = ['isOk' => false, 'fileList' => []];

    if (!Authenticator::IsFileOwner($directoryPath, $username)) {
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = ContentPathUtils::RealPath($directoryPath);
    if ($realPath === false) {
        ServiceUtils\SendErrorResponseAndExit('Not exists.');
    }

    foreach (glob($realPath . '/' . $pattern, GLOB_BRACE) as $file) {
        if (is_file($file)) {
            $fileList[] = ContentPathUtils::RelativePath($file);
        }
    }

    $response['isOk'] = true;
    $response['fileList'] = $fileList;

    ServiceUtils\SendResponseAndExit($response);
} elseif ($cmd === 'GetDirectoryList') {
    ServiceUtils\RequireParams('directoryPath');
    $directoryPath = $_POST['directoryPath'];

    $directoryList = [];
    $response = ['isOk' => false, 'directoryList' => []];

    if (!Authenticator::IsFileOwner($directoryPath, $username)) {
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = ContentPathUtils::RealPath($directoryPath);
    if ($realPath === false) {
        ServiceUtils\SendErrorResponseAndExit('Not exists.');
    }

    foreach (glob($realPath . '/{*,.[!.]*,..?*}', GLOB_ONLYDIR | GLOB_BRACE) as $directory) {
        $directoryList[] = ContentPathUtils::RelativePath($directory);
    }

    $response['isOk'] = true;
    $response['directoryList'] = $directoryList;

    ServiceUtils\SendResponseAndExit($response);
} elseif ($cmd === 'CreateNewFile') {
    ServiceUtils\RequireParams('filePath');
    try {
        $filePath = PathUtils\canonicalize($_POST['filePath']);
    } catch (Exception $error) {
        ServiceUtils\SendErrorResponseAndExit('Invalid Arguments');
    }

    if (!Authenticator::IsFileOwner($filePath, $username)) {
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = ContentPathUtils::RealPath($filePath, false);
    if (file_exists($realPath)) {
        ServiceUtils\SendErrorResponseAndExit('File Already exists.');
    }

    if (@file_put_contents($realPath, '') === false) {
        ServiceUtils\SendErrorResponseAndExit('Failed to create a file( ' . $filePath . ' ).');
    }

    ServiceUtils\SendResponseAndExit([
        'isOk' => true,
        'filePath' => $filePath
    ]);
} elseif ($cmd === 'CreateNewDirectory') {
    ServiceUtils\RequireParams('directoryPath');
    try {
        $directoryPath = PathUtils\canonicalize($_POST['directoryPath']);
    } catch (Exception $error) {
        ServiceUtils\SendErrorResponseAndExit('Invalid Arguments');
    }

    if (!Authenticator::IsFileOwner($directoryPath, $username)) {
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = ContentPathUtils::RealPath($directoryPath, false);
    if (file_exists($realPath)) {
        ServiceUtils\SendErrorResponseAndExit('Directory Already exists.');
    }

    if (!@mkdir($realPath)) {
        ServiceUtils\SendErrorResponseAndExit('Failed to create New Directory( ' . $directoryPath . ' ).');
    }

    ServiceUtils\SendResponseAndExit([
        'isOk' => true,
        'directoryPath' => $directoryPath
    ]);
} elseif ($cmd === 'DeleteFile') {
    ServiceUtils\RequireParams('filePath');
    try {
        $filePath = PathUtils\canonicalize($_POST['filePath']);
    } catch (Exception $error) {
        ServiceUtils\SendErrorResponseAndExit('Invalid Arguments');
    }

    if (!Authenticator::IsFileOwner($filePath, $username)) {
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = ContentPathUtils::RealPath($filePath, false);
    if (!file_exists($realPath)) {
        ServiceUtils\SendErrorResponseAndExit('File not exists.');
    }

    if (!unlink($realPath)) {
        ServiceUtils\SendErrorResponseAndExit('Cannot delete file( ' . $filePath . ' ).');
    }

    ServiceUtils\SendResponseAndExit([
        'isOk' => true,
        'filePath' => $filePath
    ]);
} elseif ($cmd === 'DeleteDirectory') {
    ServiceUtils\RequireParams('directoryPath');
    try {
        $directoryPath = PathUtils\canonicalize($_POST['directoryPath']);
    } catch (Exception $error) {
        ServiceUtils\SendErrorResponseAndExit('Invalid Arguments');
    }

    if (!Authenticator::IsFileOwner($directoryPath, $username)) {
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = ContentPathUtils::RealPath($directoryPath, false);
    if (!file_exists($realPath)) {
        ServiceUtils\SendErrorResponseAndExit('Directory Not Exists.');
    }

    if (!@rmdir($realPath)) {
        ServiceUtils\SendErrorResponseAndExit('Cannot Delete Directory( ' . $directoryPath . ' ).');
    }

    ServiceUtils\SendResponseAndExit([
        'isOk' => true,
        'directoryPath' => $directoryPath
    ]);
} elseif ($cmd === 'Rename') {
    ServiceUtils\RequireParams('oldName', 'newName');
    try {
        $oldName = PathUtils\canonicalize($_POST['oldName']);
        $newName = PathUtils\canonicalize($_POST['newName']);
    } catch (Exception $error) {
        ServiceUtils\SendErrorResponseAndExit('Invalid Arguments');
    }

    if (!Authenticator::IsFileOwner($newName, $username) || !Authenticator::IsFileOwner($oldName, $username)) {
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $oldRealPath = ContentPathUtils::RealPath($oldName);
    
    if ($oldRealPath === false) {
        ServiceUtils\SendErrorResponseAndExit('Not exists.');
    }

    $newRealPath = ContentPathUtils::RealPath($newName, false);

    if (!@rename($oldRealPath, $newRealPath)) {
        ServiceUtils\SendErrorResponseAndExit("Cannot rename. $oldName -> $newName");
    }

    ServiceUtils\SendResponseAndExit([
        'isOk' => true,
        'oldName' => $oldName,
        'newName' => $newName
    ]);
} elseif ($cmd === 'UploadFile') {
    ServiceUtils\RequireParams('directoryPath');
    $directoryPath = $_POST['directoryPath'];

    $response = ['isOk' => false, 'filePath' => ''];

    if (!is_uploaded_file($_FILES['upFile']['tmp_name'])) {
        ServiceUtils\SendErrorResponseAndExit('No upload file.');
    }

    $filePath = $directoryPath . "/" . $_FILES['upFile']['name'];
    if (!Authenticator::IsFileOwner($filePath, $username)) {
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $realPath = ContentPathUtils::RealPath($filePath, false);
    if (!move_uploaded_file($_FILES['upFile']['tmp_name'], $realPath)) {
        ServiceUtils\SendErrorResponseAndExit('Cannot upload.');
    }

    $response['filePath'] = $filePath;
    $response['isOk'] = true;

    ServiceUtils\SendResponseAndExit($response);
}

ServiceUtils\SendErrorResponseAndExit('Unrecognized command.');
