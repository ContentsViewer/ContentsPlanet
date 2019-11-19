<?php

// require_once dirname(__FILE__) . "/../Module/SearchEngine.php";
require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";

ContentsDatabaseManager::LoadRelatedIndex(ContentsDatabaseManager::DefalutRootContentPath());

// echo preg_quote('-._~%:/?#[]@!$&\'()*+,;=', '/');

// $array = [0, 1, 2, 3];
// echo BinarySearch::FindInsertPosition($array, 2, 0, 3);

// $text = '１１1ｂb段ボール';
// 全角英数字を半角英数字
// 全角カタカナを全角ひらがな
// 半角カタカナを全角ひらがな
// 全角スペースを半角スペース
// echo mb_convert_kana($text, 'acHs');