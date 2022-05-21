<?php

// FIXME: This module could be divided by functionalities.


/**
 * このモジュールは, システムで最も基本的なUtil関数を提供する. 
 * システム依存であるが他モジュールに依存しない. 
 */

require_once dirname(__FILE__) . "/../ContentsPlanet.php";


/**
 * ex)
 *  /Master/Root -> /Master/Contents/Root
 */
function URI2Path($uri)
{
    $insertPosition = strpos($uri, '/', 1);
    if ($insertPosition === false) $insertPosition = strlen($uri);

    $path = substr($uri, 0, $insertPosition) . '/Contents' . substr($uri, $insertPosition);
    return $path;
}

/**
 * ex)
 *  './Master/Contents/Root' -> '/Master/Root'
 *  '/Master/Contents/Root' -> '/Master/Root'
 *  'Master/Contents/Root' -> '/Master/Root'
 */
function Path2URI($path)
{
    // remove first './' or '/'.
    //  './Master/Contents/Root' -> 'Master/Contents/Root'
    //  '/Master/Contents/Root' -> 'Master/Contents/Root'
    //  'Master/Contents/Root' -> 'Master/Contents/Root'
    $path = preg_replace("/^(\.\/|\/)/", '', $path);

    // remove second part '/Contents'.
    //  'Master/Contents/Root' -> 'Master/Root'
    $path = preg_replace("/^([^\/]*)(\/Contents)(\/.*)?/", "$1$3", $path);

    // Append '/' at the head.
    return '/' . $path;
}

/**
 * Deprecated.
 * 
 * WARNING: This function does not correctly handle paths 
 *  that do not begin with a `/` except `.`. 
 *  ex) Master/Contents/Root -> (expect) Master
 * 
 * NOTE: What means 'Top'?
 *  This implementations is very strange...
 * 
 * ex)
 *  ./Master/Contents/Root -> ./Master
 *  /Master/Contents/Root -> /Master
 *  /Master/Root -> /Master
 */
function GetTopDirectory($path)
{
    return  preg_replace("/^(.?\/[^\/]*)(\/.*)/", "$1", $path);
}


/**
 * ", ' もエスケープする.
 */
function H($var)
{
    if (is_array($var)) {
        return array_map('H', $var);
    } else {
        return htmlspecialchars($var, ENT_QUOTES, 'UTF-8');
    }
}

function NotBlankText($texts)
{
    foreach ($texts as $text) {
        if ($text != '') {
            return $text;
        }
    }

    return end($texts);
}


function setcookieSecure(string $name, string $value = '', $expires = 0, string $path = '', string $domain = '')
{
    setcookie($name, $value, $expires, $path, $domain, !empty($_SERVER["HTTPS"]));
}
