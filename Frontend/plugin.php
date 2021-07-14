<?php
require_once(MODULE_DIR . '/PluginLoader.php');

$scriptName = substr($vars['subURI'], strpos($vars['subURI'], '/Plugin/') + 8);
if($scriptName !== 'js' && $scriptName !== 'css' ) {
    require(FRONTEND_DIR . '/404.php');
    exit();
}

$pluginFilename = $vars['contentsFolder'] . '/.plugins/client';

$loader = new PluginLoader();
$scripts = $loader->Load($pluginFilename);

if($scriptName == 'css') {
    header("Content-type: text/css");
}
elseif($scriptName == 'js') {
    header("Content-type: text/javascript");
}

echo $scripts[$scriptName] ?? '';