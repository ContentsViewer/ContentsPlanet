<?php
define('VERSION', '2019. Sep.18.');

define('ROOT_DIR', dirname(__FILE__));
define('MODULE_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'Module');
define('CONTENTS_HOME_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'Home');
define('SERVICE_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'Service');
define('CLIENT_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'Client');
define('CACHE_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'Cache');
define('FRONTEND_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . 'Frontend');

/**
 * ex)
 *  /CollabCMS
 */
define('ROOT_URI', str_replace(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '', str_replace('\\', '/', __DIR__)));
define('CLIENT_URI', ROOT_URI . '/Client');
define('SERVICE_URI', ROOT_URI . '/Service');

/**
 * サブURIに何も設定されていないときに参照される.
 */
define('DEFAULT_SUB_URI', '/Master/Root');


define('DEFAULT_CONTENTS_FOLDER', './Master/Contents');
define('TAG_MAP_META_FILE_NAME', 'TagMap.meta');
define('ROOT_FILE_NAME', 'Root');

define('USER_TABLE', [
    'master' => [
        'hashedPassword' => '$2y$10$F4p8eQuuhvB5WMFZsJM4ouQLWXsnCesb3HUiPpGKPrUWNk2mbyNiq',
        'digest' => 'acdb000a8fe73ee48aaf4f80442d6182',
        'contentsFolder' => './Master/Contents',
        'isPublic' => true,
        'enableGitEdit' => false,
        'gitRemoteRootUrl' => 'https://gitlab.com/arl-master/labwiki-contents/blob/master',
    ],
    'debugger' => [
        'hashedPassword' => '$2y$10$7QcYIo5gnALcmY3pM3uIMOrHWrXU5jeny.Z/Ib4Ea5sDzuMQuql46',
        'digest' => 'f2f0a813e88ab67cfa661f08922530e9',
        'contentsFolder' => './Debugger/Contents',
        'isPublic' => false,
        'enableGitEdit' => false,
        'gitRemoteRootUrl' => '',
    ],
    'dronepole' => [
        'hashedPassword' => '$2y$10$.yhSA6GNcRnqcPZJMICaVOolGSYnaHZxQVuai4gtMVyRhOji2SO3e',
        'digest' => 'b49aa68046706044fe92c734359768eb',
        'contentsFolder' => './DronePole/Contents',
        'isPublic' => true,
        'enableGitEdit' => false,
        'gitRemoteRootUrl' => '',
    ],
]);


define('MAIL_TO', 'fivetwothreesix@gmail.com'); // to@example.com
