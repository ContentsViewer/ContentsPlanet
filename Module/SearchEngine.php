<?php

namespace SearchEngine;

/**
 * Bigram-based inverted index (version 1).
 *
 * On-disk format:
 *   'version'    => 1
 *   'aliasToId'  => [alias => id_string, ...]
 *   'id2index' => [alias => [gram => true, ...], ...]
 *   'index2id' => [gram => [alias => packed_binary_string, ...], ...]
 *
 * Packed offset format (16-bit big-endian, no count header):
 *   pack('n', offset0) . pack('n', offset1) ...
 *   Count derived from strlen($packed) >> 1.
 */
class Index
{
    public array $data = [];
    private array $idToAlias = [];
    private int $nextAlias = 0;

    // --- I/O ---

    public function load(string $indexFilePath): bool
    {
        return $this->loadFromFile($indexFilePath);
    }

    public function apply(string $indexFilePath): bool
    {
        $this->data['version'] = 1;
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

        if (($decoded['version'] ?? 0) !== 1) {
            return false;
        }

        $this->data = $decoded;
        $this->idToAlias = array_flip($decoded['aliasToId']);
        $this->nextAlias = count($decoded['aliasToId']);
        return true;
    }

    // --- Search ---

    /** @return array [['id' => id, 'score' => score], ...] */
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

        $index2id = $this->data['index2id'];
        $aliasToId = $this->data['aliasToId'];
        $scoreMap = [];
        for ($i = 0; $i < $termCount; $i++) {
            $term = self::normalizeText($terms[$i]);
            $term = ' ' . $term . ' ';
            $sequence = self::bigram($term);
            $gramCount = count($sequence);

            $hitOffsets = [];
            $hitCounts = [];
            for ($j = 0; $j < $gramCount; $j++) {
                $gram = $sequence[$j];

                if (!isset($index2id[$gram])) {
                    continue;
                }

                foreach ($index2id[$gram] as $alias => $p) {
                    if (isset($hitOffsets[$alias])) {
                        $target = $hitOffsets[$alias];
                        $len = strlen($p);

                        if ($len <= 6) {
                            // Inline linear scan (97.8% of cases, count <= 3)
                            for ($off = 0; $off < $len; $off += 2) {
                                $val = (ord($p[$off]) << 8) | ord($p[$off + 1]);
                                if ($val > $target) {
                                    $hitOffsets[$alias] = $val;
                                    $hitCounts[$alias]++;
                                    break;
                                }
                            }
                        } else {
                            // Binary search on packed string (2.2% of cases)
                            $count = $len >> 1;
                            $pos = self::packedFindInsertPosition($p, $target, 0, $count - 1);
                            if ($pos < $count) {
                                $off = $pos << 1;
                                $val = (ord($p[$off]) << 8) | ord($p[$off + 1]);
                                if ($target < $val) {
                                    $hitOffsets[$alias] = $val;
                                    $hitCounts[$alias]++;
                                }
                            }
                        }
                    } else {
                        $hitOffsets[$alias] = (ord($p[0]) << 8) | ord($p[1]);
                        $hitCounts[$alias] = 1;
                    }
                }
            }

            foreach ($hitCounts as $alias => $hc) {
                $id = $aliasToId[$alias];
                $scoreMap[$id] = ($scoreMap[$id] ?? 0) + $hc / $gramCount / $termCount;
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
        $this->data['aliasToId'] ??= [];

        $alias = $this->idToAlias[$id] ?? $this->createAlias($id);

        for ($i = 0; $i < $gramCount; $i++) {
            $gram = $sequence[$i];

            if (!isset($this->data['index2id'][$gram])) {
                $this->data['index2id'][$gram] = [];
            }

            if (!isset($this->data['index2id'][$gram][$alias])) {
                $this->data['index2id'][$gram][$alias] = pack('n', $i);
            } else {
                self::packedInsert($this->data['index2id'][$gram][$alias], $i);
            }

            $this->data['id2index'][$alias][$gram] = true;
        }
    }

    public function delete(string $id): void
    {
        $alias = $this->idToAlias[$id] ?? null;
        if ($alias === null || !isset($this->data['index2id'])) {
            return;
        }

        if (isset($this->data['id2index'][$alias])) {
            foreach ($this->data['id2index'][$alias] as $gram => $_) {
                if (!isset($this->data['index2id'][$gram][$alias])) {
                    continue;
                }

                unset($this->data['index2id'][$gram][$alias]);
                if (empty($this->data['index2id'][$gram])) {
                    unset($this->data['index2id'][$gram]);
                }
            }
            unset($this->data['id2index'][$alias]);
        }

        unset($this->data['aliasToId'][$alias]);
        unset($this->idToAlias[$id]);
    }

    // --- Packed Offset Helpers (private) ---

    /**
     * Binary search on 16-bit packed offset string (0-based index).
     */
    private static function packedFindInsertPosition(string $packed, int $value, int $from, int $to): int
    {
        while ($from < $to) {
            $mid = ($from + $to) >> 1;
            $off = $mid << 1;
            if ($value < ((ord($packed[$off]) << 8) | ord($packed[$off + 1]))) {
                $to = $mid;
            } else {
                $from = $mid + 1;
            }
        }
        return $from;
    }

    /**
     * Insert a value into a 16-bit packed offset string in sorted order (no duplicates).
     */
    private static function packedInsert(string &$packed, int $value): bool
    {
        $len = strlen($packed);
        for ($off = 0; $off < $len; $off += 2) {
            $existing = (ord($packed[$off]) << 8) | ord($packed[$off + 1]);
            if ($existing === $value) return false;
            if ($existing > $value) break;
        }
        $packed = substr($packed, 0, $off)
                . pack('n', $value)
                . substr($packed, $off);
        return true;
    }

    private function createAlias(string $id): int
    {
        $alias = $this->nextAlias++;
        $this->idToAlias[$id] = $alias;
        $this->data['aliasToId'][$alias] = $id;
        return $alias;
    }

    // --- Text Utils (private) ---

    private static function normalizeText(string $text): string
    {
        $text = mb_convert_kana($text, 'acHs');
        $text = strtolower($text);
        return $text;
    }

    private static function bigram(string $text): array
    {
        $sequence = [];
        $len = mb_strlen($text);
        for ($idx = 0; $idx < $len - 1; $idx++) {
            $sequence[] = mb_substr($text, $idx, 2);
        }
        return $sequence;
    }
}
