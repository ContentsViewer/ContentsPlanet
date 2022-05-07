<?php

require_once dirname(__FILE__) . "/ContentDatabase.php";
require_once dirname(__FILE__) . "/OutlineText.php";
require_once dirname(__FILE__) . "/CacheManager.php";
require_once dirname(__FILE__) . "/Debug.php";

class PluginLoader
{

    public function Load($pluginPath)
    {
        $pluginContent = new Content();
        if (!$pluginContent->SetContent($pluginPath)) {
            return [];
        }
        $cache = new Cache();
        $cache->Connect('plugin-' . $pluginContent->path);
        $cache->Lock(LOCK_EX);
        $cache->Fetch();
        if (
            ($cache->data['updatedTime'] ?? 0) < $pluginContent->modifiedTime ||
            !array_key_exists('scripts', $cache->data)
        ) {
            $cache->data['scripts'] = [];
            OutlineText\Parser::Init();
            OutlineText\Parser::Parse($pluginContent->body, $context);

            foreach ($context->morphSequence->morphs as $morph) {
                if ($morph['isCodeBlock']) {
                    $scriptName = $morph['codeBlockAttribute'];
                    $cache->data['scripts'][$scriptName] = ($cache->data['scripts'][$scriptName] ?? '') . $morph['content'] . "\n";
                }
            }
            $cache->data['updatedTime'] = time();
            $cache->Apply();
        }
        $cache->Unlock();
        $cache->Disconnect();
        return $cache->data['scripts'];
    }
}
