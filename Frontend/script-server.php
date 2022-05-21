<?php
require_once(MODULE_DIR . "/PluginLoader.php");


// ex)
//  /Master/:plugins/path/to/scripts/[js|css]
//  =>
//  ['', 'Master', ':plugins', 'path', 'to', [js|css]]
$segments = explode('/', $vars['subURI']);

$pluginPath = implode('/', array_slice($segments, 3, -1));
$scriptName = end($segments);

if ($scriptName !== 'js' && $scriptName !== 'css') {
    require(FRONTEND_DIR . '/404.php');
    exit();
}

$loader = new PluginLoader($vars['contentsFolder']);
$scripts = $loader->loadScripts($pluginPath);

if ($scriptName == 'css') {
    header("Content-type: text/css");
} elseif ($scriptName == 'js') {
    header("Content-type: text/javascript");
}

echo $scripts[$scriptName] ?? '';
