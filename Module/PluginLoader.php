<?php
require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/ScriptLoader.php";
require_once dirname(__FILE__) . "/PathUtils.php";
require_once dirname(__FILE__) . "/Utils.php";


class PluginLoader
{
    private static $commonHead = null;

    public static function getCommonHead()
    {
        if (isset(self::$commonHead)) return self::$commonHead;

        $loader = new ScriptLoader;
        self::$commonHead = $loader->load(DEFAULT_CONTENTS_FOLDER . '/.plugins/common/user-scripts')['html'] ?? '';

        return self::$commonHead;
    }

    private $loader;
    private $contentsFolder = '';

    public function __construct($contentsFolder)
    {
        $this->loader = new ScriptLoader();
        $this->contentsFolder = $contentsFolder;
        $this->loader->macros = [
            ['{CURRENT_USER_ROOT_URI}'],
            [ROOT_URI . Path2URI($contentsFolder)]
        ];
    }


    public function loadScripts($path)
    {
        $scriptPath = \PathUtils\join($this->contentsFolder, '.plugins', $path);

        return $this->loader->load($scriptPath);
    }
}
