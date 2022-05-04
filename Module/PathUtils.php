<?php
// NOTE-2022-05-04 <IOE>: We should follow PSR-12 coding style.
//  PSR-12:
//      * https://www.php-fig.org/psr/psr-12/

// Refs of impl:
//  * https://github.com/webmozart/path-util/blob/master/src/Path.php

namespace PathUtils;

// Use native path manipulation functions when possible.
//  * dirname()
//  * basename()
//  * pathinfo()

/**
 * Normalize a file path string so that it can be checked safely.
 *
 * @param $path string
 *     The path to normalize.
 * @return string|false
 *    Normalized path or FALSE, if $path cannot be normalized (invalid).
 */
function canonicalize(string $path)
{
    if ($path === '') {
        return '';
    }

    // Attempt to avoid path encoding problems.
    // $path = preg_replace("/[^\x20-\x7E]/", '', $path);
    $path = str_replace('\\', '/', $path);

    // Remember path root.
    $prefix = substr($path, 0, 1) === '/' ? '/' : '';

    // Process path components
    $stack = array();
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            // No-op: skip empty part.
        } elseif ($part !== '..') {
            array_push($stack, $part);
        } elseif (!empty($stack)) {
            array_pop($stack);
        } else {
            return FALSE; // Out of the root.
        }
    }

    // Return the "clean" path
    $path = $prefix . implode('/', $stack);
    return $path;
}



// class Path
// {


//     /**
//      * @var string
//      */
//     private $path = "";

//     public function __construct($path)
//     {
//         $this->path = $path;
//     }

//     public function __toString()
//     {
//         return $this->path;
//     }

// }
