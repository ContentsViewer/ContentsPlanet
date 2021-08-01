<?php
define('VERSION', '2021.08.31');
define('COPYRIGHT',
    '<b>ContentsPlanet ' . VERSION . '</b>' .
    ' &copy; 2016-2021' .
    ' <a href="https://contentsviewer.work/Master/ContentsPlanet/ContentsPlanet">ContentsPlanet Development Team</a>'
);

/**
 * Defines the default internal character encoding.
 * This deprecated feature will certainly be removed in the future.
 * <https://www.php.net/manual/en/mbstring.configuration.php>
 * 
 * Users should leave this empty and set default_charset instead.
 */
ini_set('mbstring.internal_encoding' , '');

ini_set('default_charset' , 'UTF-8');

define('ROOT_DIR'         , dirname(__FILE__));
define('MODULE_DIR'       , ROOT_DIR . DIRECTORY_SEPARATOR . 'Module');
define('CONTENTS_HOME_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'Home');
define('SERVICE_DIR'      , ROOT_DIR . DIRECTORY_SEPARATOR . 'Service');
define('CLIENT_DIR'       , ROOT_DIR . DIRECTORY_SEPARATOR . 'Client');
define('CACHE_DIR'        , ROOT_DIR . DIRECTORY_SEPARATOR . 'Cache');
define('FRONTEND_DIR'     , ROOT_DIR . DIRECTORY_SEPARATOR . 'Frontend');
define('LOCALES_DIR'      , ROOT_DIR . DIRECTORY_SEPARATOR . 'Locales');
define('LOG_DIR'          , ROOT_DIR);


$rootURI = str_replace(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '', str_replace('\\', '/', __DIR__));
if(strlen($rootURI) != 0 && strpos($rootURI, '/') !== 0){
    // パスがあって最初に'/'がないときは追加する.
    $rootURI = '/' . $rootURI;
}
/**
 * ex)
 *  /ContentsPlanet
 */
define('ROOT_URI', $rootURI);

define('CLIENT_URI', ROOT_URI . '/Client');
define('SERVICE_URI', ROOT_URI . '/Service');

/**
 * サブURIに何も設定されていないときに参照される.
 */
define('DEFAULT_SUB_URI', '/Master/Root');


define('DEFAULT_CONTENTS_FOLDER', './Master/Contents');
define('META_FILE_NAME', '.metadata');
define('ROOT_FILE_NAME', 'Root');
define('INDEX_FILE_NAME', '.index');
define('DEFAULT_LAYER_NAME', 'en');

define('REDIRECT_HTTPS_ENABLED', false);

define('USER_TABLE', [
    'master' => [
        'hashedPassword'   => '',
        'digest'           => '',
        'contentsFolder'   => './Master/Contents',
        'isPublic'         => true,
        'enableRemoteEdit' => false,
        'remoteURL'        => '',
        'notifyingList'    => [
            // ['type' => 'mail', 'destination' => 'your.mail@address'],
        ]
    ],
]);
