<?php

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/ContentsDatabaseManager.php');
require_once(MODULE_DIR . '/OutlineText.php');


if(!isset($_POST['token']) || !Authenticator::ValidateCsrfToken($_POST['token'])){
    $vars['errorMessage'] = 'トークンが無効です';
    require(FRONTEND_DIR . '/400.php');
    exit();
}

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
$context->pathMacros = ContentsDatabaseManager::CreatePathMacros($vars['contentPath']);

OutlineText\Parser::Init();

?>

<!DOCTYPE html>
<html lang="ja">

<head>
<?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

<link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/OutlineTextStandardStyle.css" />

<!-- Code表記 -->
<script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shCore.js"></script>
<script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushCpp.js"></script>
<script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushCSharp.js"></script>
<script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushXml.js"></script>
<script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPhp.js"></script>
<script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushPython.js"></script>
<script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushJava.js"></script>
<script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shBrushBash.js"></script>
<link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/syntaxhighlighter/styles/shCoreDefault.css" />
<script type="text/javascript">SyntaxHighlighter.defaults['gutter'] = false;SyntaxHighlighter.all();</script>


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
</body>
</html>

<?php
exit();