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
 * Normalizes the given path.
 *
 * During normalization, all slashes are replaced by forward slashes ("/").
 * Contrary to {@link canonicalize()}, this method does not remove invalid
 * or dot path segments. Consequently, it is much more efficient and should
 * be used whenever the given path is known to be a valid, absolute system
 * path.
 *
 * This method is able to deal with both UNIX and Windows paths.
 *
 * @param string $path A path string.
 *
 * @return string The normalized path.
 *
 */
function normalize(string $path)
{
    return str_replace('\\', '/', $path);
}


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
    // NOTE 2022-05-04 <IOE>: But it cannot handle multi byte characters... 
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

/**
 * Joins two or more path strings.
 *
 * The result is a canonical path.
 *
 * @param string[]|string $paths Path parts as parameters or array.
 *
 * @return string The joint path.
 *
 */
function join($paths)
{
    if (!is_array($paths)) {
        $paths = func_get_args();
    }

    $finalPath = null;
    $wasScheme = false;

    foreach ($paths as $path) {
        $path = (string) $path;

        if ('' === $path) {
            continue;
        }

        if (null === $finalPath) {
            // For first part we keep slashes, like '/top', 'C:\' or 'phar://'
            $finalPath = $path;
            $wasScheme = (strpos($path, '://') !== false);
            continue;
        }

        // Only add slash if previous part didn't end with '/' or '\'
        if (!in_array(substr($finalPath, -1), array('/', '\\'))) {
            $finalPath .= '/';
        }

        // If first part included a scheme like 'phar://' we allow current part to start with '/', otherwise trim
        $finalPath .= $wasScheme ? $path : ltrim($path, '/');
        $wasScheme = false;
    }

    if (null === $finalPath) {
        return '';
    }

    return $finalPath;
}

/**
 * Splits a part into its root directory and the remainder.
 *
 * If the path has no root directory, an empty root directory will be
 * returned.
 *
 * If the root directory is a Windows style partition, the resulting root
 * will always contain a trailing slash.
 *
 * list ($root, $path) = Path::split("C:/webmozart")
 * // => array("C:/", "webmozart")
 *
 * list ($root, $path) = Path::split("C:")
 * // => array("C:/", "")
 *
 * @param string $path The canonical path to split.
 *
 * @return string[] An array with the root directory and the remaining
 *                  relative path.
 */
function split($path)
{
    if ('' === $path) {
        return array('', '');
    }

    // Remember scheme as part of the root, if any
    if (false !== ($pos = strpos($path, '://'))) {
        $root = substr($path, 0, $pos + 3);
        $path = substr($path, $pos + 3);
    } else {
        $root = '';
    }

    $length = strlen($path);

    // Remove and remember root directory
    if ('/' === $path[0]) {
        $root .= '/';
        $path = $length > 1 ? substr($path, 1) : '';
    } elseif ($length > 1 && ctype_alpha($path[0]) && ':' === $path[1]) {
        if (2 === $length) {
            // Windows special case: "C:"
            $root .= $path . '/';
            $path = '';
        } elseif ('/' === $path[2]) {
            // Windows normal case: "C:/"..
            $root .= substr($path, 0, 3);
            $path = $length > 3 ? substr($path, 3) : '';
        }
    }

    return array_merge([$root], explode('/', $path));
}


/**
 * The path object to use method chain.
 */
class Path
{

    public static function from($path)
    {
        return new Path($path);
    }

    public function normalize()
    {
        if (!$this->isValid()) return $this;

        $this->path = \PathUtils\normalize($this->path);
        return $this;
    }

    public function canonicalize()
    {
        if (!$this->isValid()) return $this;

        $this->path = \PathUtils\canonicalize($this->path);
        return $this;
    }

    public function join($paths)
    {
        if (!$this->isValid()) return $this;

        $this->path = \PathUtils\join($this->path, $paths);
        return $this;
    }

    public function split()
    {
        if (!$this->isValid()) return [];

        return \PathUtils\split($this->path);
    }

    public function isValid()
    {
        return $this->path !== false;
    }

    public function path()
    {
        return $this->path;
    }

    /**
     * @var string
     */
    private $path = '';
    private function __construct($path)
    {
        $this->path = $path;
    }
}