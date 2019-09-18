<?php

/**
 * ex)
 *  /Master/Root -> /Master/Contents/Root
 */
function URI2Path($uri){
    $insertPosition = strpos($uri, '/', 1);
    if($insertPosition === false) $insertPosition = strlen($uri);

    $path = substr($uri, 0, $insertPosition) . '/Contents' . substr($uri, $insertPosition);
    return $path; 
}

/**
 * ex)
 *  ./Master/Contents/Root -> /Master/Root
 *  /Master/Contents/Root -> /Master/Root
 */
function Path2URI($path){
    $path = preg_replace("/^\./", "", $path);
    $path = preg_replace("/^(\/[^\/]*)(\/Contents)(\/.*)?/", "$1$3", $path);

    return $path;
}

/**
 * ex)
 *  ./Master/Contents/Root -> ./Master
 *  /Master/Contents/Root -> /Master
 *  /Master/Root -> /Master
 */
function GetTopDirectory($path){
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