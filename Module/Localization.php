<?php

namespace Localization;
require_once(dirname(__FILE__) . "/Debug.php");

if (!defined('LOCALES_DIR')) {
    define('LOCALES_DIR', getcwd() . '/Locales');
}

/**
 * [
 *  'name'
 * ]
 */
$_LOCALE = [];
$_loadedTranslationsFiles = [];

/**
 * Set the locale.
 * 
 * Same locale name php script must exists in the LOCALES_DIR.
 */
function SetLocale($locale){
    global $_LOCALE;

    $header = LoadJson($locale . '/' . $locale . '.json');
    if($header === false){
        \Debug::LogError("[Localization\SetLocale] Cannot load json file. locale: " . $locale);
        return false;
    } 
    
    InitLocale();
    $_LOCALE = $header;
    $_LOCALE['name'] = $locale;

    $coreTranslations = LoadJson($locale . '/translations.core.json');
    if($coreTranslations === false){
        // \Debug::LogWarning("[Localization\SetLocale] Cannot load core translations pack. locale: " . $locale);
    }
    else{
        $_LOCALE['translations'] = $coreTranslations;
    }

    return true;
}

function GetLocale(){
    global $_LOCALE;
    if(isset($_LOCALE)){
        return $_LOCALE;
    }

    return false;
}

function PeekLocale($locale){
    return LoadJson($locale . '/' . $locale . '.json');
}

/**
 * Initialize the locale.
 */
function InitLocale(){
    global $_LOCALE, $_loadedTranslationsFiles;
    $_LOCALE = [];
    $_loadedTranslationsFiles = [];
}

/**
 * Localize a message.
 *
 * `message` can contain `{n}` notation where it is replaced by the nth value in `...args`
 * For example, `Localize('sayHello', 'hello {0}', name)`
 */
function Localize($key, $message, ...$args){
    global $_LOCALE;

    if(!\array_key_exists('translations', $_LOCALE) || 
    !\array_key_exists($key, $_LOCALE['translations'])){
        $namespace = GetNamespace($key);
        if($namespace !== false){
            LoadTranslations($namespace);
        }
    }

    if(array_key_exists('translations', $_LOCALE) &&
        array_key_exists($key, $_LOCALE['translations'])){
        $message = $_LOCALE['translations'][$key];
    }

    $result = '';
    if(count($args) === 0){
        return $message;
    }

    $message = preg_replace_callback("/\{(\d+)\}/", function($matches) use ($args){
        $index = $matches[1];
        if(array_key_exists($index, $args)){
            return $args[$index];
        }
        return $matches[0];
    }, $message);

    return $message;
}

function GetNamespace($key){
    $pos = strpos($key, '.');
    if($pos === false){
        return false;
    }

    return substr($key, 0, $pos);
}

function LoadTranslations($namespace){
    global $_LOCALE, $_loadedTranslationsFiles;

    if(!\array_key_exists('name', $_LOCALE)){
        return false;
    }

    if(\array_key_exists($namespace, $_loadedTranslationsFiles)){
        return $_loadedTranslationsFiles[$namespace];
    }

    $translations = LoadJson($_LOCALE['name'] . '/translations.' . $namespace . '.json');
    if($translations === false){
        // \Debug::LogError(
        //     "[Localization\LoadTranslations] Cannot load translations pack. locale: " . 
        //     $_LOCALE['name'] . '; namespace: '. $namespace
        // );
        $_loadedTranslationsFiles[$namespace] = false;
        return false;
    }

    foreach($translations as $key => $value){
        $_LOCALE['translations'][$namespace . '.' . $key] = $value;
    }
    $_loadedTranslationsFiles[$namespace] = true;
    return true;
}


function LoadJson($filename){
    $filename = \realpath(LOCALES_DIR . '/' . $filename);
    if($filename === false){
        return false;
    }

    $localesDir = \realpath(LOCALES_DIR);
    if($localesDir === false){
        return false;
    } 

    if(strpos($filename, $localesDir) !== 0) {
        return false;
    }

    $json = \file_get_contents($filename);
    if($json === false) {
        return false;
    }

    $array = \json_decode($json, true);
    if(is_null($array)){
        return false;
    }

    return $array;
}