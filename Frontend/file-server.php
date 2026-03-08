<?php
/**
 * 参照する変数
 *  $vars['filePath']
 */

$filePath = $vars['filePath'];
$mtime = filemtime($filePath);
$size = filesize($filePath);

// ETag: size-mtime based (avoids reading file contents)
$etag = sprintf('"%x-%x"', $size, $mtime);

// Check conditional requests — return 304 early before any I/O
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

if (
    $ifNoneMatch === $etag ||
    ($ifModifiedSince !== '' && @strtotime($ifModifiedSince) >= $mtime)
) {
    header('HTTP/1.1 304 Not Modified');
    header('ETag: ' . $etag);
    exit;
}

// MIME type detection
$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
if (!preg_match('/\A\S+?\/\S+/', $mimeType)) {
    $mimeType = 'application/octet-stream';
}

// Response headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $size);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
header('Cache-Control: no-cache');

// Disable output buffering and send file
while (ob_get_level()) { ob_end_clean(); }
readfile($filePath);

exit;