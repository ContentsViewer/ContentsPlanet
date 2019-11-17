<?php

require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/../Module/OutlineText.php";

if (!isset($_POST['plainText'])) {
    exit();
}

header("Access-Control-Allow-Origin: *");

$plainText = $_POST['plainText'];

// --- 前処理 -------------
// 改行LFのみ
$plainText = str_replace("\r", "", $plainText);

// end 前処理 -----

$context = new OutlineText\Context();
if(isset($_POST['contentPath'])){
    $context->pathMacros = ContentsDatabaseManager::CreatePathMacros($_POST['contentPath']);
}

OutlineText\Parser::Init();
?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

    <link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/OutlineTextStandardStyle.css" />

    <!-- Code表記 -->
    <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shCore.js"></script>
    <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shAutoloader.js"></script>
    <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/syntaxhighlighter/styles/shCoreDefault.css" />

    <!-- 数式表記 -->
    <script type="text/x-mathjax-config">
    MathJax.Hub.Config({
        tex2jax: { inlineMath: [['$','$'], ["\\(","\\)"]] },
        TeX: { equationNumbers: { autoNumber: "AMS" } }
    });
    </script>
    <script type="text/javascript"
    src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.5/MathJax.js?config=TeX-AMS_CHTML">
    </script>
    <meta http-equiv="X-UA-Compatible" CONTENT="IE=EmulateIE7" />

</head>
<body>
    <?=OutlineText\Parser::Parse($plainText, $context);?>

    <!-- SyntaxHighlighter 有効化 -->
    <script>
        SyntaxHighlighter.autoloader(
        'applescript            <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushAppleScript.js',
        'actionscript3 as3      <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushAS3.js',
        'bash shell             <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushBash.js',
        'coldfusion cf          <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushColdFusion.js',
        'cpp c                  <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushCpp.js',
        'c# c-sharp csharp      <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushCSharp.js',
        'css                    <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushCss.js',
        'delphi pascal          <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushDelphi.js',
        'diff patch pas         <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushDiff.js',
        'erl erlang             <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushErlang.js',
        'groovy                 <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushGroovy.js',
        'java                   <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushJava.js',
        'jfx javafx             <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushJavaFX.js',
        'js jscript javascript  <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushJScript.js',
        'perl pl                <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPerl.js',
        'php                    <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPhp.js',
        'text plain             <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPlain.js',
        'py python              <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPython.js',
        'ruby rails ror rb      <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushRuby.js',
        'sass scss              <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushSass.js',
        'scala                  <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushScala.js',
        'sql                    <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushSql.js',
        'xml xhtml xslt html    <?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushXml.js'
        );
        SyntaxHighlighter.defaults['gutter'] = false;
        SyntaxHighlighter.all();
    </script>
</body>
</html>

<?php
exit();
