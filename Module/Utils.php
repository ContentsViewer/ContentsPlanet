<?php

/**
 * このモジュールは, システムで最も基本的なUtil関数を提供する. 
 * システム依存であるが他モジュールに依存しない. 
 */

require_once dirname(__FILE__) . "/../ContentsPlanet.php";


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
 * ex)
 *  /Master/Contents/Root.content -> '.content'
 *  /Master/Contents/Root -> ''
 */
function GetExtention($path){
    $basename = basename($path);
    $pos = strrpos($basename, '.');

    if($pos === false){
        return '';
    }

    return substr($basename, $pos);
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

/**
 * Normalize a file path string so that it can be checked safely.
 *
 * @param $path string
 *     The path to normalize.
 * @return string
 *    Normalized path or FALSE, if $path cannot be normalized (invalid).
 */
function NormalizePath($path) {
    // Skip invalid input.
    if (!isset($path)) {
      return FALSE;
    }
    if ($path === '') {
      return '';
    }
  
    // Attempt to avoid path encoding problems.
    $path = preg_replace("/[^\x20-\x7E]/", '', $path);
    $path = str_replace('\\', '/', $path);

    // Remember path root.
    $prefix = substr($path, 0, 1) === '/' ? '/' : '';

    // Process path components
    $stack = array();
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
        // No-op: skip empty part.
        } elseif ($part !== '..') {
        array_push($stack, $part);
        } elseif (!empty($stack)) {
        array_pop($stack);
        } else {
        return FALSE; // Out of the root.
        }
    }

    // Return the "clean" path
    $path = $prefix . implode('/', $stack);
    return $path;
}


function NotBlankText($texts){
    foreach($texts as $text){
        if($text != ''){
            return $text;
        }
    }

    return end($texts);
}


function SetCookieSecure(string $name, string $value='', $expires = 0, string $path = '') {
    setcookie($name, $value, $expires, $path, '', !empty($_SERVER["HTTPS"]));
}