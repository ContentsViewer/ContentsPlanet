<?php

class BinarySearch
{
    public static function findIndex(array $array, $search, $from, $to)
    {
        $pos = self::findInsertPosition($array, $search, $from, $to);
        if ($pos === false || $to < $pos) return false;
        if ($array[$pos] === $search) return $pos;
        return false;
    }

    public static function findInsertPosition(array $array, $value, $from, $to)
    {
        if ($from > $to) return false;

        while ($from < $to) {
            $mid = intval(($from + $to) / 2);
            if ($value < $array[$mid]) {
                $to = $mid;
            } else {
                $from = $mid + 1;
            }
        }

        if ($array[$from] < $value) $from++;

        return $from;
    }

    /**
     * 配列に昇順になるように要素を加えます.
     * 対象の配列は昇順になっている必要があります．
     *
     * @return bool 正常に加えられたとき, true. その他はfalseを返します．
     */
    public static function insert(array &$array, $value, $from, $to, $allowDuplicate = true)
    {
        $pos = self::findInsertPosition($array, $value, $from, $to);
        if ($pos === false) return false;
        if (!$allowDuplicate && $pos <= $to && $array[$pos] === $value) return false;

        if ($to < $pos) $array[] = $value;
        else array_splice($array, $pos, 0, $value);

        return true;
    }
}
