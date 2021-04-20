<?php

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/ContentsDatabaseManager.php');
require_once(MODULE_DIR . '/ContentTextParser.php');

Authenticator::RequireLoginedSession();

if(!isset($_POST['token']) || !Authenticator::ValidateCsrfToken($_POST['token'])){
    $vars['errorMessage'] = Localization\Localize('invalidToken', 'Invalid Token.');
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

ContentTextParser::Init();
$context = ContentTextParser::CreateContext($vars['contentPath']);

$vars['layerName'] = ContentsDatabaseManager::GetRelatedLayerName($vars['contentPath']);
if($vars['layerName'] === false){
    $vars['layerName'] = DEFAULT_LAYER_NAME;
}

?>
<!DOCTYPE html>
<html lang="<?=$vars['layerName']?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>

  <link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/style.css" />

  <!-- Code表記 -->
  <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shCore.js"></script>
  <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shAutoloader.js"></script>
  <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/syntaxhighlighter/styles/shCoreDefault.css" />

  <!-- 数式表記 -->
  <script type="text/x-mathjax-config">
    MathJax.Hub.Config({
      tex2jax: { 
        inlineMath: [['$','$'], ["\\(","\\)"]],
        processEscapes: true
      },
      TeX: { equationNumbers: { autoNumber: "AMS" } }
    });
  </script>
  <script type="text/javascript"
    src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.5/MathJax.js?config=TeX-AMS_CHTML">
  </script>

</head>

<body>
  <?=ContentTextParser::Parse($plainText, $vars['contentPath'], $context);?>

  <!-- SyntaxHighlighter 有効化 -->
  <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter-loader/loader.js"></script>
  <script>loadSyntaxHighlighter("<?=CLIENT_URI?>");</script>
</body>

</html>

<?php
exit();