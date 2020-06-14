<?php
namespace ServiceUtils;

require_once dirname(__FILE__) . "/Authenticator.php";

/**
 * ensure that REQUEST_METHOD is POST after calling this function
 */
function RequirePostMethod() {
    if($_SERVER['REQUEST_METHOD'] !== 'POST') {
        SendErrorResponseAndExit('Bad request.');
    }
}

function RequireParams(...$names) {
    foreach($names as $name) {
        if(!isset($_POST[$name])) {
            SendErrorResponseAndExit('Few parameters.');
        }
    }
}

function ValidateCsrfToken() {
    if(!isset($_POST['token']) || !\Authenticator::ValidateCsrfToken($_POST['token'])) {
        SendErrorResponseAndExit('Invalid token.');
    }
}

function RequireLoginedSession() {
    // セッション開始
    @session_start();

    // ログイン状態ではないときloginページに遷移
    if (!isset($_SESSION['username'])) {
        SendErrorResponseAndExit('Unlogined session.');
    }
}

/**
 * Validate these below conditions are satisfied.
 *   * public content
 *   * if non-public
 *     * logined user matches with owner
 *     * validate token (optional)
 * 
 * if not satified, exit with error.
 */
function ValidateAccessPrivilege($filePath, $validateCsrfToken=true, &$owner=null, &$isPublic=null) {
    if(is_null($owner)) {
        $owner=\Authenticator::GetFileOwnerName($filePath);
    }
    if($owner === false) {
        SendErrorResponseAndExit('No owner.');
    }
    
    if(is_null($isPublic)) {
        $isPublic=false;
        if(!\Authenticator::GetUserInfo($owner, 'isPublic', $isPublic)) {
            SendErrorResponseAndExit('Internal error.');
        }
    }

    if($isPublic === true) {
        // OK
    }
    elseif($isPublic === false) {
        // セッション開始
        @\session_start();

        if($validateCsrfToken) { ValidateCsrfToken(); }

        $loginedUser=\Authenticator::GetLoginedUsername();
        if($loginedUser !== $owner) {
            SendErrorResponseAndExit('Permission denied.');
        }
    }
    else {
        SendErrorResponseAndExit('Internal error.');
    }
}

/**
 * send ['error' => $error ], and exit
 */
function SendErrorResponseAndExit($error) {
    SendResponseAndExit(['error' => $error]);
}

/**
 * convert response into json strings, send string, and exit
 */
function SendResponseAndExit($response) {
    echo json_encode($response);
    exit;
}
