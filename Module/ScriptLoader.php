<?php

require_once dirname(__FILE__) . "/ContentDatabase.php";
require_once dirname(__FILE__) . "/OutlineText.php";
require_once dirname(__FILE__) . "/CacheManager.php";
require_once dirname(__FILE__) . "/Debug.php";

class ScriptLoader
{
    public $macros = [[], []];

    /**
     * @var ContentDatabase
     */
    private $database;

    public function __construct(ContentDatabase $database = null)
    {
        $this->database = $database ?? new ContentDatabase;
    }

    public function load(string $scriptPath)
    {
        $scriptContent = $this->database->get($scriptPath);
        if (!$scriptContent) {
            return [];
        }
        $cache = new Cache();
        try {        
            $cache->connect('script-' . $scriptContent->path)->lock(LOCK_EX)->fetch();
        } catch (Exception $error) {
            \Debug::LogError($error);
        }

        if (
            ($cache->data['updatedTime'] ?? 0) < $scriptContent->modifiedTime ||
            !array_key_exists('scripts', $cache->data)
        ) {
            $cache->data['scripts'] = [];
            OutlineText\Parser::Init();
            OutlineText\Parser::Parse($scriptContent->body, $context);

            foreach ($context->morphSequence->morphs as $morph) {
                if ($morph['isCodeBlock']) {
                    $scriptName = $morph['codeBlockAttribute'];
                    $script = str_replace($this->macros[0], $this->macros[1],  $morph['content']);

                    $cache->data['scripts'][$scriptName] = ($cache->data['scripts'][$scriptName] ?? '') . $script . "\n";
                }
            }
            $cache->data['updatedTime'] = time();

            try {
                $cache->apply();
            } catch (Exception $error) {
                \Debug::LogError($error);
            }
        }
        $cache->unlock()->disconnect();
        return $cache->data['scripts'];
    }
}
