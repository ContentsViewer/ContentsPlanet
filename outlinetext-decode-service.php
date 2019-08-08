<?php

require_once dirname(__FILE__) . "/CollabCMS.php";
require_once dirname(__FILE__) . "/Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/Module/OutlineText.php";

if (isset($_POST['plainText'])) {
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
    <?php readfile("Client/Common/CommonHead.html");?>

    <link rel="stylesheet" href="Client/OutlineText/OutlineTextStandardStyle.css" />


    <!-- Code表記 -->
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shCore.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushCpp.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushCSharp.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushXml.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushPhp.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushPython.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushJava.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushBash.js"></script>
    <link type="text/css" rel="stylesheet" href="Client/syntaxhighlighter/styles/shCoreDefault.css" />
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

}

exit();

?>