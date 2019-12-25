<?php
namespace SearchEngine;
require_once(dirname(__FILE__) . "/BinarySearch.php");

class Seacher{
    public static $index = [];

    /**
     * [['id' => id, 'score' => score], ...]
     * 
     * @param string $query ex) term term
     * @return array [['id' => id, 'score' => score], ...]
     */
    public static function Search($query){
        /**
         * [
         *  ['id' => id, 'score' => score],
         *  ...
         * ]
         */
        $suggestions = [];

        if(!array_key_exists('index2id', self::$index)){
            return $suggestions;
        }

        $query = trim($query);
        if(empty($query)){
            return $suggestions;
        }

        $terms = explode(' ', $query);
        $termCount = count($terms);
        for($i = $termCount - 1; $i >= 0; $i--){
            $terms[$i] = trim($terms[$i]);
            if(empty($terms[$i])){
                array_splice($terms, $i, 1);
            }
        }

        $scoreMap = [];
        $termCount = count($terms);
        for($i = 0; $i < $termCount; $i++){
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
            
            if(mb_strlen($term) <= 1){
                $term = ' ' . $term . ' ';
            }
            $sequence = Utils::Bigram($term);
            $gramCount = count($sequence);
            for($j = 0; $j < $gramCount; $j++){
                $gram = $sequence[$j];

                if(array_key_exists($gram, self::$index['index2id'])){
                    
                    foreach(self::$index['index2id'][$gram] as $id => $offsetInfo){
                        if(array_key_exists($id, $hitInfo)){
                            $pos = \BinarySearch::FindInsertPosition($offsetInfo, $hitInfo[$id]['offset'], 1, $offsetInfo[0]);
                            // \Debug::Log($gram);
                            // \Debug::Log($pos);
                            // \Debug::Log($offsetInfo);
                            if($pos <= $offsetInfo[0] && $hitInfo[$id]['offset'] < $offsetInfo[$pos]){
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
            foreach($hitInfo as $id => $info){
                if(!array_key_exists($id, $scoreMap)){
                    $scoreMap[$id] = 0;
                }
                $scoreMap[$id] += $info['hitCount'] / $gramCount / $termCount;
            }
        }
        arsort($scoreMap);
        foreach($scoreMap as $id => $score){
            $suggestions[] = ['id' => $id, 'score' => $score];
        }
        return $suggestions;
    }

    public static function LoadIndex($indexFilePath){
        return Utils::LoadJson($indexFilePath, self::$index);
    }
}

class Indexer{
    /**
     * [
     *  'createdTime' => timestamp,
     *  'openedTime' => timestamp,
     *  'closedTime' => timestamp,
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
    public static $index = [];
    public static $indexOpenedTime = null;

    public static function RegistIndex($id, $text){
        $text = trim($text);

        if(empty($text)){
            return;
        }

        $text = Utils::NormalizeText($text);

        $text = ' ' . $text . ' ';
        $sequence = Utils::Bigram($text);
        $gramCount = count($sequence);

        for($i = 0; $i < $gramCount; $i++){
            $gram = $sequence[$i];
            if(!array_key_exists('index2id', self::$index)){
                self::$index['index2id'] = [];
            }
            if(!array_key_exists($gram, self::$index['index2id'])){
                self::$index['index2id'][$gram] = [];
            }
            if(!array_key_exists($id, self::$index['index2id'][$gram])){
                self::$index['index2id'][$gram][$id] = [];
                self::$index['index2id'][$gram][$id][] = 0;
            }

            if(self::$index['index2id'][$gram][$id][0] == 0){
                self::$index['index2id'][$gram][$id][] = $i;
                self::$index['index2id'][$gram][$id][0]++;
            }
            else{
                if(\BinarySearch::Insert(self::$index['index2id'][$gram][$id], $i, 1, self::$index['index2id'][$gram][$id][0], false)){
                    self::$index['index2id'][$gram][$id][0]++;
                }
            }
            
            self::$index['id2index'][$id][$gram] = true;
        }

        // var_dump(self::$index);
    }

    public static function UnregistIndex($id){
        if(!array_key_exists('id2index', self::$index) || !array_key_exists($id, self::$index['id2index'])){
            return;
        }

        if(!array_key_exists('index2id', self::$index)){
            return;
        }

        foreach(self::$index['id2index'][$id] as $index => $value){
            if(!array_key_exists($index, self::$index['index2id'])
                || !array_key_exists($id, self::$index['index2id'][$index])){
                continue;
            }

            unset(self::$index['index2id'][$index][$id]);
            if(empty(self::$index['index2id'][$index])){
                unset(self::$index['index2id'][$index]);
            }
        }
        unset(self::$index['id2index'][$id]);
        // echo '<br>A';
        // var_dump(self::$index);
    }

    public static function ApplyIndex($indexFilePath){
        if(!array_key_exists('createdTime', self::$index)){
            self::$index['createdTime'] = time();
        }
        
        if(is_null(self::$indexOpenedTime)){
            self::$indexOpenedTime = time();
        }

        self::$index['openedTime'] = self::$indexOpenedTime;
        self::$index['closedTime'] = time();

        $json = json_encode(self::$index);
        if ($json === false) {
            return false;
        }
        
        if (file_put_contents($indexFilePath, $json, LOCK_EX) === false) {
            return false;
        }

        return true;
    }

    public static function LoadIndex($indexFilePath){
        if(Utils::LoadJson($indexFilePath, self::$index)){
            self::$indexOpenedTime = time();
            return true;
        }
        return false;
    }
}

class Utils{
    public static function Ngram($text, $n){
        $sequence = [];
        $len = mb_strlen($text);
        for($idx = 0; $idx < $len - $n + 1; $idx++){
            $sequence[] = mb_substr($text, $idx, $n);
        }
        return $sequence;
    }

    public static function Bigram($text){
        return self::Ngram($text, 2);
    }

    public static function LoadJson($filePath, &$object){
        if (!file_exists($filePath)) {
            return false;
        }

        $fp = fopen($filePath, "r");
        if($fp === false || !flock($fp, LOCK_SH)){
            fclose($fp);
            return false;
        }
        $json = stream_get_contents($fp);
        fclose($fp);

        if ($json === false) {
            return false;
        }
        
        $object = json_decode($json, true);
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
    public static function NormalizeText($text){

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