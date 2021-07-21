<?php

require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/../Module/OutlineText.php";
require_once dirname(__FILE__) . "/../Module/Utils.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";

set_error_handler('ErrorHandling\PlainErrorHandler');


if (!isset($_POST['plainText'])) {
    exit();
}

header("Access-Control-Allow-Origin: *");

$plainText = $_POST['plainText'];

// --- 前処理 -------------
// 改行LFのみ
$plainText = str_replace("\r", "", $plainText);

// end 前処理 -----

$language = 'en';
if(isset($_POST['language'])){
    $language = H($_POST['language']);
}

OutlineText\Parser::Init();
?>

<!DOCTYPE html>
<html lang="<?=$language?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>

  <link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/style.css" />

  <!-- Code表記 -->
  <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shCore.js"></script>
  <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter/scripts/shAutoloader.js"></script>
  <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/syntaxhighlighter/styles/shCoreDefault.css" />

  <!-- 数式表記 -->
  <script src="<?=CLIENT_URI?>/OutlineText/load-mathjax.js" async></script>
</head>
<body>
  <?=OutlineText\Parser::Parse($plainText);?>

  <!-- SyntaxHighlighter 有効化 -->
  <script type="text/javascript" src="<?=CLIENT_URI?>/syntaxhighlighter-loader/loader.js"></script>
  <script>loadSyntaxHighlighter("<?=CLIENT_URI?>");</script>
</body>
</html>

<?php
exit();
