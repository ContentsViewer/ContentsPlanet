<?php
// NOTE-2022-05-04 <IOE>: We should follow PSR-12 coding style.
//  PSR-12:
//      * https://www.php-fig.org/psr/psr-12/

// Refs of impl:
//  * https://github.com/webmozart/path-util/blob/master/src/Path.php

namespace PathUtils;

use InvalidArgumentException;
use RuntimeException;

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
            throw new InvalidArgumentException(sprintf(
                'The path "%s" is out of the root.',
                $path
            ));
        }
    }

    // Return the "clean" path
    $path = $prefix . implode('/', $stack);
    return $path;
}

function getRelative(string $path, string $basePath)
{
    $path = canonicalize($path);
    $basePath = canonicalize($basePath);

    [$root, $relativePath] = split($path);
    [$baseRoot, $relativeBasePath] = split($basePath);

    // If the base path is given as absolute path and the path is already
    // relative, consider it to be relative to the given absolute path
    // already
    if ('' === $root && '' !== $baseRoot) {
        // If base path is already in its root
        if ('' === $relativeBasePath) {
            $relativePath = ltrim($relativePath, './\\');
        }

        return $relativePath;
    }

    // If the passed path is absolute, but the base path is not, we
    // cannot generate a relative path
    if ('' !== $root && '' === $baseRoot) {
        throw new InvalidArgumentException(sprintf(
            'The absolute path "%s" cannot be made relative to the '.
            'relative path "%s". You should provide an absolute base '.
            'path instead.',
            $path,
            $basePath
        ));
    }

    // Fail if the roots of the two paths are different
    if ($baseRoot && $root !== $baseRoot) {
        throw new InvalidArgumentException(sprintf(
            'The path "%s" cannot be made relative to "%s", because they '.
            'have different roots ("%s" and "%s").',
            $path,
            $basePath,
            $root,
            $baseRoot
        ));
    }

    if ('' === $relativeBasePath) return $relativePath;

    // Build a "../../" prefix with as many "../" parts as necessary
    $parts = explode('/', $relativePath);
    $baseParts = explode('/', $relativeBasePath);
    $dotDotPrefix = '';

    // Once we found a non-matching part in the prefix, we need to add
    // "../" parts for all remaining parts
    $match = true;

    foreach ($baseParts as $i => $basePart) {
        if ($match && isset($parts[$i]) && $basePart === $parts[$i]) {
            unset($parts[$i]);

            continue;
        }

        $match = false;
        $dotDotPrefix .= '../';
    }

    return rtrim($dotDotPrefix . implode('/', $parts), '/');
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
 * Changes the extension of a path string.
 *
 * @param string $path      The path string with filename.ext to change.
 * @param string $extension New extension (with or without leading dot).
 *
 * @return string The path string with new file extension.
 *
 */
function replaceExtension(string $path, string $extension)
{
    if ('' === $path) {
        return '';
    }

    // No extension for paths
    if ('/' === substr($path, -1)) {
        return $path;
    }

    $actualExtension = pathinfo($path, PATHINFO_EXTENSION);
    $extension = ltrim($extension, '.');
    if (!empty($extension)) $extension = '.' . $extension;

    // No actual extension in path
    if (empty($actualExtension)) {
        return rtrim($path, '.') . $extension;
    }

    return rtrim(substr($path, 0, -strlen($actualExtension)), '.') . $extension;
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

    return [$root, $path];
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
        $this->path = \PathUtils\normalize($this->path);
        return $this;
    }

    public function canonicalize()
    {
        $this->path = \PathUtils\canonicalize($this->path);
        return $this;
    }

    public function join($paths)
    {
        $this->path = \PathUtils\join($this->path, $paths);
        return $this;
    }

    public function split()
    {
        return \PathUtils\split($this->path);
    }

    public function replaceExtension(string $extension)
    {
        $this->path = \PathUtils\replaceExtension($this->path, $extension);
        return $this;
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
