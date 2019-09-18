<?php
/**
 * 参照する変数
 *  $vars['filePath']
 */

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($vars['filePath']);

//-- 適切なMIMEタイプが得られない時は、未知のファイルを示すapplication/octet-streamとする
if (!preg_match('/\A\S+?\/\S+/', $mimeType)) {
    $mimeType = 'application/octet-stream';
}

//-- Content-Type
header('Content-Type: ' . $mimeType);

header('Content-Length: ' . filesize($vars['filePath']));

// header('Connection: keep-alive');

//-- readfile()の前に出力バッファリングを無効化する
while (ob_get_level()) { ob_end_clean(); }

//-- 出力
readfile($vars['filePath']);

exit;