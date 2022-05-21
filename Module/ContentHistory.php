<?php
namespace ContentHistory;

require_once dirname(__FILE__) . "/CacheManager.php";
require_once dirname(__FILE__) . "/PathUtils.php";

$MAX_REVISIONS = 10;

function GetHistory($contentPath) {
    $cacheName = GetHsitoryCacheName($contentPath);
    if(!\CacheManager::cacheExists($cacheName)) {
        return [];
    }
    $cache = new \Cache();
    $cache->connect($cacheName); $cache->lock(LOCK_EX);
    $cache->fetch();
    $cache->unlock(); $cache->disconnect();
    return $cache->data;
}

function AddRevision($contentPath, $ts, $content) {
    global $MAX_REVISIONS;
    $cacheName = GetHsitoryCacheName($contentPath);
    $cache = new \Cache();
    $cache->connect($cacheName); $cache->lock(LOCK_EX);
    $cache->fetch();
    $cache->data['expires'] = 12 * 30 * 24 * 60 * 60;
    $revisions = $cache->data['revisions'] ?? [];
    $revisions[$ts] = $content;
    \krsort($revisions);
    $poped_count = count($revisions) - $MAX_REVISIONS;
    while($poped_count-- > 0) array_pop($revisions);
    $cache->data['revisions'] = $revisions;
    $cache->apply(); $cache->unlock(); $cache->disconnect();
}

function GetHsitoryCacheName($contentPath) {
    return 'history-' . \PathUtils\canonicalize($contentPath);
}