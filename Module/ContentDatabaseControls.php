<?php

namespace ContentDatabaseControls;

require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/ContentDatabase.php";
require_once dirname(__FILE__) . "/SearchEngine.php";
require_once dirname(__FILE__) . "/PathUtils.php";
require_once dirname(__FILE__) . "/Utils.php";

use Content;
use ContentDatabase;
use ContentPathUtils;
use SearchEngine;
use PathUtils\Path;

/**
 * DEFAULT_CONTENTS_FOLDER / ROOT_FILE_NAME
 * 
 * ex)
 *  ./Master/Contents/Root
 */
function DefalutRootContentPath()
{
    return DEFAULT_CONTENTS_FOLDER . '/' . ROOT_FILE_NAME;
}


/**
 * GetTopDirectory(DEFAULT_CONTENTS_FOLDER) / META_FILE_NAME
 * 
 * ex)
 *  ./Master/.metadata
 */
function DefaultMetaFilePath()
{
    return GetTopDirectory(DEFAULT_CONTENTS_FOLDER) . '/' . META_FILE_NAME;
}

/**
 * ex)
 *  './Master/Contents/Root' -> 'Master'
 *  'Master/Contents/Root' -> 'Master'
 */
function GetUserDirectory($path)
{
    // The first part of canonicalize path should be empty.
    //  './Master/Contents/Root' 
    //  -> 'Master/Contents/Root' (canonicalize())
    //  -> ['', 'Master', 'Contents', 'Root'] (split())
    //
    //  Why?
    //      The relative path does not have root directory.
    return explode('/', Path::from($path)->canonicalize()->split()[1])[0];
}

/**
 * ex)
 *  'Master/Contents'
 */
function GetContentsFolder($path)
{
    $userDirectory = GetUserDirectory($path);
    return $userDirectory . '/Contents';
}


function GetRelatedRootFile($contentPath)
{
    $rootFolder = GetContentsFolder($contentPath);
    $layerName = GetRelatedLayerName($contentPath);
    $layerName = ($layerName === false) ? '' : ('_' . $layerName);
    return $rootFolder . '/' . ROOT_FILE_NAME . $layerName;
}


function GetRelatedMetaFileName($contentPath)
{
    $userDirectory = GetUserDirectory($contentPath);
    $layerName = GetRelatedLayerName($contentPath);
    $layerSufix = GetLayerSuffix($layerName);

    return \PathUtils\join($userDirectory, META_FILE_NAME . $layerSufix);
}


/**
 * ex)
 *  '.index_ja'
 */
function GetRelatedIndexFileName($contentPath)
{
    $userDirectory = GetUserDirectory($contentPath);
    $layerName = GetRelatedLayerName($contentPath);
    $layerSufix = GetLayerSuffix($layerName);

    return \PathUtils\join(CONTENTS_HOME_DIR, $userDirectory, INDEX_FILE_NAME . $layerSufix);
}


/**
 * './Master/Contents/Root_ja.note' -> 'ja'
 * './Master/Contents/Root -> false
 * './Master/Root_en.note.content' -> 'en'
 */
function GetRelatedLayerName($contentPath)
{
    return GetContentPathInfo($contentPath)['layername'];
}


function GetLayerSuffix($layerName)
{
    if ($layerName === false || $layerName === DEFAULT_LAYER_NAME) {
        return '';
    }
    return '_' . $layerName;
}


/**
 * If the directory of the contentPath does not exist, returns false.
 * 
 * ex)
 *  $contentPath = './Master/Contents/Test/Sub'
 *  in same direcotry, 
 *  './Master/Contents/Test/Sub_en', './Master/Contents/Test/Sub_ch' exists
 * 
 *  -> ['en', 'ch', false]
 * 
 * @return false|array
 */
function GetRelatedLayers($contentPath)
{
    $pathInfo = GetContentPathInfo($contentPath);
    $realDirname = ContentPathUtils::RealPath($pathInfo['dirname']);

    if ($realDirname === false) {
        return false;
    }

    $layers = [];

    $concatExtentions = implode('.', array_merge($pathInfo['extentions'], ['content']));
    $pattern = $pathInfo['filename'] . '_*.' . $concatExtentions;
    $files = glob($realDirname . '/' . $pattern);
    foreach ($files as $file) {
        $info = GetContentPathInfo($file);
        if (
            $info['filename'] === $pathInfo['filename'] &&
            $concatExtentions === implode('.', $info['extentions'])
        ) {
            $layers[] = $info['layername'];
        }
    }

    // 最後に, layer が付いていないファイルを探す.
    // ex) ./Master/Contents/Test/Sub
    if (file_exists($realDirname . '/' . $pathInfo['filename'] . '.' . $concatExtentions)) {
        $layers[] = false; // layerがないことを示す
    }

    return $layers;
}


/**
 * ex)
 *  './Master/Contents/Sub/Test_en.note.content'
 *  ->
 *  [
 *      'dirname' => './Master/Contents/Sub',
 *      'basename' => 'Test_en.note.content',
 *      'filename' => 'Test'
 *      'layername' => 'en',
 *      'extentions' => ['note', 'content']
 *  ]
 */
function GetContentPathInfo($contentPath)
{
    $info = [];
    $info['dirname'] = dirname($contentPath);
    $info['basename'] = basename($contentPath);

    $extentions = [];
    $filename = $info['basename'];
    $layername = false;

    // 拡張子を取り除く
    while (($pos = strrpos($filename, '.')) != false) {
        array_unshift($extentions, substr($filename, $pos + 1));
        $filename = substr($filename, 0, $pos);
    }

    if (($pos = strrpos($filename, '_')) != false) {
        $layername = substr($filename, $pos + 1);
        // {言語コード(ISO 639-1)}
        // (
        //  (-{国名コード(ISO 3166-1 Alpha 2)}) |
        //  (-{文字体系(ISO 15924)}(-{国名コード(ISO 3166-1 Alpha 2)})?)
        // )?
        //
        // 言語コード(ISO 639-1): [a-z][a-z]
        // 国名コード(ISO 3166-1 Alpha 2): [A-Z][A-Z]
        // 文字体系(ISO 15924): [A-Z][a-z][a-z][a-z]
        // 
        if (IsValidLayerName($layername)) {
            $filename = substr($filename, 0, $pos);
        } else {
            $layername = false;
        }
    }

    $info['filename'] = $filename;
    $info['layername'] = $layername;
    $info['extentions'] = $extentions;

    return $info;
}

function IsValidLayerName($layerName)
{
    return (preg_match('/^[a-z][a-z]((-[A-Z][A-Z])|(-[A-Z][a-z][a-z][a-z](-[A-Z][A-Z])?))?$/', $layerName) === 1);
}


/**
 * Remove Top Directory, layer suffix, and extentions.
 * 
 * ex)
 *  /Master/Test/Sub_en.note -> Test/Sub
 *  /TagMap_en -> TagMap
 */
function ReduceURI($uri)
{
    $reduced = substr($uri, strlen(GetTopDirectory($uri)) + 1);

    if ($reduced === false) {
        // URI の仕様で, 最初に'/'があることは決まっている
        $reduced = substr($uri, 1);
    }

    // Test/Sub_en.note
    // TagMap_en

    $pathInfo = GetContentPathInfo($reduced);
    return (($pathInfo['dirname'] == '.') ? ('') : ($pathInfo['dirname'] . '/')) . $pathInfo['filename'];
}


/**
 * [Content, ...]
 * 
 * @param array $pathList
 * @param array $notFounds ['path', ...]
 * @return array [Content, ...]
 */
function GetSortedContentsByUpdatedTime($pathList, &$notFounds)
{
    $sorted = [];
    foreach ($pathList as $path) {
        $content = new Content();
        if (!$content->SetContent($path)) {
            $notFounds[] = $path;
            continue;
        }

        $sorted[] = $content;
    }

    usort($sorted, function ($a, $b) {
        return $b->modifiedTime - $a->modifiedTime;
    });
    return $sorted;
}


function GetSuggestedTags($content, $tag2path, $excludeOriginal = true, &$fullMatchTag = null)
{
    $suggestedTags = [];
    $title = NotBlankText(
        [$content->title, GetContentPathInfo($content->path)['filename']]
    );
    foreach ($tag2path as $tag => $paths) {
        $fullMatchTag = ($tag === $title) ? $tag : $fullMatchTag;
        if (
            strpos($title, $tag) !== false &&
            (!$excludeOriginal || !in_array($tag, $content->tags, true))
        ) {
            $suggestedTags[] = $tag;
        }
    }
    return $suggestedTags;
}


/**
 * 
 */
function GetMajorTags($tag2path)
{
    /**
     * instead of array_key_last() >=7.3.0
     */
    $array_key_last = function ($array) {
        end($array);
        $key = key($array);
        reset($array);
        return $key;
    };

    $tags = [];
    $nt = 0;
    $ts = [];
    foreach ($tag2path as $tag => $paths) {
        $count = count($paths);
        $tags[$tag] = $count;
        $ts[$count] = 0;
        $nt++;
    }
    if ($nt < 2) return [];
    ksort($ts);
    $first = key($ts);
    unset($ts[$first]);

    foreach ($ts as $thres => $s) {
        $u0 = 0;
        $u1 = 0;
        $v1 = 0;
        $n1 = 0;
        $u2 = 0;
        $v2 = 0;
        $n2 = 0;

        foreach ($tags as $tag => $count) {
            if ($count < $thres) {
                $n1++;
                $u1 += $count;
            } // class 1
            else {
                $n2++;
                $u2 += $count;
            } // class 2
            $u0 += $count;
        }
        $u0 /= ($n1 + $n2);
        $u1 /= $n1;
        $u2 /= $n2;

        foreach ($tags as $tag => $count) {
            if ($count < $thres) {
                $v1 += ($count - $n1) * ($count - $n1);
            } // class 1
            else {
                $v2 += ($count - $n2) * ($count - $n2);
            } // class 2
        }
        $v1 /= $n1;
        $v2 /= $n2;

        $vw = ($n1 * $v1 + $n2 * $v2) / ($n1 + $n2);
        $vb = ($n1 * ($u1 - $u0) * ($u1 - $u0) + $n2 * ($u2 - $u0) * ($u2 - $u0)) / ($n1 + $n2);
        $ts[$thres] = $vb / $vw;
    }

    asort($ts);
    $thres = $array_key_last($ts);

    $majorTags = [];
    foreach ($tags as $tag => $count) {
        if ($count >= $thres) {
            $majorTags[$tag] = $count;
        }
    }

    arsort($majorTags);
    return $majorTags;
}


function DeleteContentsFromMetadata(ContentDatabase $database, $contentPaths)
{
    if (empty($contentPaths)) {
        return false;
    }

    foreach ($contentPaths as $path) {
        $database->DeleteFromRecent($path);
        $database->DeleteFromTagMap($path);
    }
    return true;
}


function DeleteContentsFromIndex(SearchEngine\Index $index, $contentPaths)
{
    if (empty($contentPaths)) {
        return false;
    }

    foreach ($contentPaths as $path) {
        SearchEngine\Indexer::Delete($index, $path);
    }
    return true;
}
