<?php

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/ContentDatabase.php');
require_once(MODULE_DIR . '/ContentDatabaseControls.php');
require_once(MODULE_DIR . '/ContentTextParser.php');

use ContentDatabaseControls as DBControls;


Authenticator::RequireLoginedSession();

if(!isset($_POST['token']) || !Authenticator::ValidateCsrfToken($_POST['token'])){
    $vars['errorMessage'] = Localization\Localize('invalidToken', 'Invalid Token.');
    require(FRONTEND_DIR . '/400.php');
    exit();
}

if (!isset($_POST['rawText'])) {
    exit();
}

header("Access-Control-Allow-Origin: *");

$rawText = $_POST['rawText'];
$elements = Content::Parse($rawText);

ContentTextParser::Init();
$context = ContentTextParser::CreateContext($vars['contentPath']);

$vars['layerName'] = DBControls\GetRelatedLayerName($vars['contentPath']);
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
  <?=ContentTextParser::Parse($elements['summary'], $vars['contentPath'], $context)?>
  <hr>
  <?=ContentTextParser::Parse($elements['body'], $vars['contentPath'], $context);?>
</body>

</html>

<?php
exit();