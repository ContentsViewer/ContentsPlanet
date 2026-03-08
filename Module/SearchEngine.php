<?php

namespace SearchEngine;

require_once(dirname(__FILE__) . "/BinarySearch.php");

/**
 * Bigramベースの転置インデックス。
 *
 * データ構造:
 *   'id2index' => ['id0' => ['gram0' => true, ...], ...]
 *   'index2id' => ['gram0' => ['id0' => [count, offset0, offset1, ...], ...], ...]
 */
class Index
{
    public array $data = [];

    // --- I/O ---

    public function load(string $indexFilePath): bool
    {
        return $this->loadFromFile($indexFilePath);
    }

    public function apply(string $indexFilePath): bool
    {
        $serialized = serialize($this->data);

        if (file_put_contents($indexFilePath, $serialized, LOCK_EX) === false) {
            return false;
        }

        return true;
    }

    private function loadFromFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $fp = fopen($filePath, "r");
        if ($fp === false || !flock($fp, LOCK_SH)) {
            if ($fp !== false) fclose($fp);
            return false;
        }
        $raw = stream_get_contents($fp);
        fclose($fp);

        if ($raw === false) {
            return false;
        }
        $decoded = unserialize($raw, ['allowed_classes' => false]);
        if ($decoded === false) {
            return false;
        }
        $this->data = $decoded;
        return true;
    }

    // --- Search ---

    /**
     * @param string $query スペース区切りの検索クエリ
     * @return array [['id' => id, 'score' => score], ...]
     */
    public function search(string $query): array
    {
        $suggestions = [];

        if (!isset($this->data['index2id'])) {
            return $suggestions;
        }

        $query = trim($query);
        if (empty($query)) {
            return $suggestions;
        }

        $terms = array_values(array_filter(array_map('trim', explode(' ', $query))));
        $termCount = count($terms);
        if ($termCount === 0) {
            return $suggestions;
        }

        $scoreMap = [];
        for ($i = 0; $i < $termCount; $i++) {
            $term = self::normalizeText($terms[$i]);
            $term = ' ' . $term . ' ';
            $sequence = self::bigram($term);
            $gramCount = count($sequence);

            $hitInfo = [];
            for ($j = 0; $j < $gramCount; $j++) {
                $gram = $sequence[$j];

                if (!isset($this->data['index2id'][$gram])) {
                    continue;
                }

                foreach ($this->data['index2id'][$gram] as $id => $offsetInfo) {
                    if (isset($hitInfo[$id])) {
                        $pos = \BinarySearch::findInsertPosition($offsetInfo, $hitInfo[$id]['offset'], 1, $offsetInfo[0]);
                        if ($pos <= $offsetInfo[0] && $hitInfo[$id]['offset'] < $offsetInfo[$pos]) {
                            $hitInfo[$id]['offset'] = $offsetInfo[$pos];
                            $hitInfo[$id]['hitCount']++;
                        }
                    } else {
                        $hitInfo[$id] = [
                            'offset' => $offsetInfo[1],
                            'hitCount' => 1,
                        ];
                    }
                }
            }

            foreach ($hitInfo as $id => $info) {
                $scoreMap[$id] = ($scoreMap[$id] ?? 0) + $info['hitCount'] / $gramCount / $termCount;
            }
        }

        arsort($scoreMap);
        foreach ($scoreMap as $id => $score) {
            $suggestions[] = ['id' => $id, 'score' => $score];
        }
        return $suggestions;
    }

    // --- Indexing ---

    public function register(string $id, string $text): void
    {
        $text = trim($text);
        if (empty($text)) {
            return;
        }

        $text = self::normalizeText($text);
        $text = ' ' . $text . ' ';
        $sequence = self::bigram($text);
        $gramCount = count($sequence);

        $this->data['index2id'] ??= [];
        $this->data['id2index'] ??= [];

        for ($i = 0; $i < $gramCount; $i++) {
            $gram = $sequence[$i];

            if (!isset($this->data['index2id'][$gram])) {
                $this->data['index2id'][$gram] = [];
            }
            if (!isset($this->data['index2id'][$gram][$id])) {
                $this->data['index2id'][$gram][$id] = [0];
            }

            if ($this->data['index2id'][$gram][$id][0] == 0) {
                $this->data['index2id'][$gram][$id][] = $i;
                $this->data['index2id'][$gram][$id][0]++;
            } else {
                if (\BinarySearch::insert($this->data['index2id'][$gram][$id], $i, 1, $this->data['index2id'][$gram][$id][0], false)) {
                    $this->data['index2id'][$gram][$id][0]++;
                }
            }

            $this->data['id2index'][$id][$gram] = true;
        }
    }

    public function delete(string $id): void
    {
        if (!isset($this->data['id2index'][$id]) || !isset($this->data['index2id'])) {
            return;
        }

        foreach ($this->data['id2index'][$id] as $idx => $_) {
            if (!isset($this->data['index2id'][$idx][$id])) {
                continue;
            }

            unset($this->data['index2id'][$idx][$id]);
            if (empty($this->data['index2id'][$idx])) {
                unset($this->data['index2id'][$idx]);
            }
        }
        unset($this->data['id2index'][$id]);
    }

    // --- Text Utils (private) ---

    private static function normalizeText(string $text): string
    {
        // 全角英数字→半角、全角カタカナ→ひらがな、半角カタカナ→ひらがな、全角スペース→半角
        $text = mb_convert_kana($text, 'acHs');
        $text = strtolower($text);
        return $text;
    }

    private static function bigram(string $text): array
    {
        return self::ngram($text, 2);
    }

    private static function ngram(string $text, int $n): array
    {
        $sequence = [];
        $len = mb_strlen($text);
        for ($idx = 0; $idx < $len - $n + 1; $idx++) {
            $sequence[] = mb_substr($text, $idx, $n);
        }
        return $sequence;
    }
}
