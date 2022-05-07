<?php

require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/../Module/OutlineText.php";
require_once dirname(__FILE__) . "/../Module/Utils.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";
require_once dirname(__FILE__) . "/../Module/ContentsViewerUtils.php";

set_error_handler('ErrorHandling\PlainErrorHandler');

use ContentsViewerUtils as CVUtils;


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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <?= CVUtils\GetCommonHeaad() ?>

  <link rel="stylesheet" href="<?= CLIENT_URI ?>/Common/css/base.css">
  <link rel="stylesheet" href="<?=CLIENT_URI?>/OutlineText/style.css" />

  <!-- Code表記 -->
  <script>
    SyntaxHighlighter = {
      src: "<?=CLIENT_URI?>/syntaxhighlighter"
    }
  </script>
  <script src="<?=CLIENT_URI?>/OutlineText/load-syntaxhighlighter.js" async></script>

  <!-- 数式表記 -->
  <script src="<?=CLIENT_URI?>/OutlineText/load-mathjax.js" async></script>
</head>
<body>
  <?=OutlineText\Parser::Parse($plainText);?>
</body>
</html>

<?php
exit();
