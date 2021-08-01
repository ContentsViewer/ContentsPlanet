<?php
namespace SearchEngine;
require_once(dirname(__FILE__) . "/BinarySearch.php");
require_once(dirname(__FILE__) . "/Debug.php");

/**
 * indexファイルとメモリ間を取り持つ.
 * 主に, 読み込みと書き込み
 */
class Index {
    /**
     * [
     *  'id2index' => 
     *      [
     *          'id0' => ['index0' => true, 'index1' => true, ...],
     *          ...
     *      ]
     *  'index2id' =>
     *      [
     *          'index0' => 
     *              [
     *                  'id0' => [count, offset0, offset1, ...],
     *                  ...
     *              ],
     *          ...
     *      ]
     * ]
     */
    public $data = [];

    public function Load($indexFilePath) {
        return Utils::LoadJson($indexFilePath, $this->data);
    }

    public function Apply($indexFilePath) {
        $json = json_encode($this->data);
        
        if ($json === false) {
            return false;
        }
        
        if (file_put_contents($indexFilePath, $json, LOCK_EX) === false) {
            return false;
        }

        return true;
    }
}

class Searcher {
    /**
     * [['id' => id, 'score' => score], ...]
     * 
     * @param string $query ex) term term
     * @return array [['id' => id, 'score' => score], ...]
     */
    public static function Search($index, $query) {
        /**
         * [
         *  ['id' => id, 'score' => score],
         *  ...
         * ]
         */
        $suggestions = [];

        if (!array_key_exists('index2id', $index->data)) {
            return $suggestions;
        }

        $query = trim($query);
        if (empty($query)) {
            return $suggestions;
        }

        $terms = explode(' ', $query);
        $termCount = count($terms);
        for($i = $termCount - 1; $i >= 0; $i--) {
            $terms[$i] = trim($terms[$i]);
            if (empty($terms[$i])) {
                array_splice($terms, $i, 1);
            }
        }

        $scoreMap = [];
        $termCount = count($terms);
        for($i = 0; $i < $termCount; $i++) {
            $term = $terms[$i];
            $term = Utils::NormalizeText($term);

            /**
             * [
             *  id(path) => 
             *      [
             *          'offset' => offset,
             *          'hitCount' => hitCount
             *      ]
             * ]
             */
            $hitInfo = [];
            // Note:
            //  2020-6-6: 
            //      下のように, 一文字のみ前後に空白を入れて, 一文字ヒットして多くの検索候補が出るのを防げた.
            //      だが, 一文字より多い場合でも, 多くの検索候補が出ている
            // if (mb_strlen($term) <= 1) {
            //     $term = ' ' . $term . ' ';
            // }
            // Note:
            //  2020-6-6:
            //      下のように, 前に空白を入れて単語として認識できるようにする.
            //      前後に入れると, 部分文字列がヒットしずらくなった.
            //      ただし部分文字列がどうなるか?
            // Note:
            //  2021-07-12:
            //      $term = ' ' . $term;
            //      のようにすると, `root`に`troubleshooting`がヒット(0.75以上)する. 
            //      'C#'に, 'CMS', 'CUDA', 'Cpp', 'C言語', 'cuDNN'等, はじめが'C'で始まるものがヒット(0.5以上)する.
            $term = ' ' . $term . ' ';
            $sequence = Utils::Bigram($term);
            $gramCount = count($sequence);
            for($j = 0; $j < $gramCount; $j++) {
                $gram = $sequence[$j];

                if (array_key_exists($gram, $index->data['index2id'])) {

                    foreach($index->data['index2id'][$gram] as $id => $offsetInfo) {
                        if (array_key_exists($id, $hitInfo)) {
                            $pos = \BinarySearch::FindInsertPosition($offsetInfo, $hitInfo[$id]['offset'], 1, $offsetInfo[0]);
                            // \Debug::Log($gram);
                            // \Debug::Log($id);
                            // \Debug::Log($pos);
                            // \Debug::Log($offsetInfo);
                            if ($pos <= $offsetInfo[0] && $hitInfo[$id]['offset'] < $offsetInfo[$pos]) {
                                $hitInfo[$id]['offset'] = $offsetInfo[$pos];
                                $hitInfo[$id]['hitCount']++;
                                // \Debug::Log('OK');
                            }
                            else{
                                // グラムはあるが, 前のグラムとつながりがないとき, スキップする
                                //
                                // 検索語: ブラウザゲーム
                                // 検索対象: ゲーム, ブラウザゲーム一覧
                                // 
                                // 検索語最後のバイグラムの'む 'が検索対象 'ゲーム' にあたり
                                // オフセットが手前にあるためヒットせず
                                // だが, 部分文字として'ブラウザゲーム一覧'にヒットしたい
                                //
                                // unset($hitInfo[$id]);
                                // \Debug::Log('NG');
                            }
                        }
                        else{
                            $hitInfo[$id]['offset'] = $offsetInfo[1];
                            $hitInfo[$id]['hitCount'] = 1;
                        }
                    }
                }
            }

            // var_dump($hitInfo);
            foreach($hitInfo as $id => $info) {
                if (!array_key_exists($id, $scoreMap)) {
                    $scoreMap[$id] = 0;
                }
                $scoreMap[$id] += $info['hitCount'] / $gramCount / $termCount;
            }
        }
        arsort($scoreMap);
        foreach($scoreMap as $id => $score) {
            $suggestions[] = ['id' => $id, 'score' => $score];
        }
        // \Debug::Log($suggestions);
        return $suggestions;
    }
}

class Indexer {
    
    public static function Index($index, $id, $text) {
        $text = trim($text);

        if (empty($text)) {
            return;
        }

        $text = Utils::NormalizeText($text);
        $text = ' ' . $text . ' ';
        $sequence = Utils::Bigram($text);
        $gramCount = count($sequence);

        for ($i = 0; $i < $gramCount; $i++) {
            $gram = $sequence[$i];
            if (!array_key_exists('index2id', $index->data)) {
                $index->data['index2id'] = [];
            }
            if (!array_key_exists($gram, $index->data['index2id'])) {
                $index->data['index2id'][$gram] = [];
            }
            if (!array_key_exists($id, $index->data['index2id'][$gram])) {
                $index->data['index2id'][$gram][$id] = [];
                $index->data['index2id'][$gram][$id][] = 0;
            }

            if ($index->data['index2id'][$gram][$id][0] == 0) {
                $index->data['index2id'][$gram][$id][] = $i;
                $index->data['index2id'][$gram][$id][0]++;
            }
            else {
                if (\BinarySearch::Insert($index->data['index2id'][$gram][$id], $i, 1, $index->data['index2id'][$gram][$id][0], false)) {
                    $index->data['index2id'][$gram][$id][0]++;
                }
            }
            
            $index->data['id2index'][$id][$gram] = true;
        }
    }

    public static function Delete($index, $id) {
        if (!array_key_exists('id2index', $index->data) 
            || !array_key_exists($id, $index->data['id2index'])) {
            return;
        }

        if (!array_key_exists('index2id', $index->data)) {
            return;
        }

        foreach ($index->data['id2index'][$id] as $idx => $value) {
            if (!array_key_exists($idx, $index->data['index2id'])
                || !array_key_exists($id, $index->data['index2id'][$idx])) {
                continue;
            }

            unset($index->data['index2id'][$idx][$id]);
            if (empty($index->data['index2id'][$idx])) {
                unset($index->data['index2id'][$idx]);
            }
        }
        unset($index->data['id2index'][$id]);
    }
}

class Utils {
    public static function Ngram($text, $n) {
        $sequence = [];
        $len = mb_strlen($text);
        for($idx = 0; $idx < $len - $n + 1; $idx++) {
            $sequence[] = mb_substr($text, $idx, $n);
        }
        return $sequence;
    }

    public static function Bigram($text) {
        return self::Ngram($text, 2);
    }

    public static function LoadJson($filePath, &$object) {
        if (!file_exists($filePath)) {
            return false;
        }

        $fp = fopen($filePath, "r");
        if ($fp === false || !flock($fp, LOCK_SH)) {
            fclose($fp);
            return false;
        }
        $json = stream_get_contents($fp);
        fclose($fp);

        if ($json === false) {
            return false;
        }
        $decoded = json_decode($json, true);
        if (is_null($decoded)) {
            return false;
        }
        $object = $decoded;
        return true;
    }

    /**
     * 全角英数字を半角英数字
     * 全角カタカナを全角ひらがな
     * 全角スペースを半角スペース
     * 半角カタカナを全角ひらがな
     * 
     * アルファベット大文字を小文字にする
     */
    public static function NormalizeText($text) {

        // 全角英数字を半角英数字
        // 全角カタカナを全角ひらがな
        // 半角カタカナを全角ひらがな
        // 全角スペースを半角スペース
        $text = mb_convert_kana($text, 'acHs');
        
        // アルファベット大文字を小文字にする
        $text = strtolower($text);

        return $text;
    }
}