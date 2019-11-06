<?php

class BinarySearch{
    public static function FindIndex(array $array, $search, $from, $to){
        $pos = self::FindInsertPosition($array, $search, $from, $to);
        if($pos === false || $to < $pos) return false;
        if($array[$pos] === $search) return $pos;
        return false; 
    }

    public static function FindInsertPosition(array $array, $value, $from, $to){

        if($from > $to) return false;

        while($from < $to){
            $mid = intval(($from + $to) / 2);
            if($value < $array[$mid]){
                $to = $mid;
            }
            else{
                $from = $mid + 1;
            }
        }
        if($array[$from] < $value) $from++;

        return $from;
    }

    public static function Insert(array &$array, $value, $from, $to, $allowDuplicate = true){
        $pos = self::FindInsertPosition($array, $value, $from, $to);
        if($pos === false) return false;
        if(!$allowDuplicate && $pos <= $to && $array[$pos] === $value) return false;

        if($to < $pos) $array[] = $value;
        else array_splice($array, $pos, 0, $value);
    }
}