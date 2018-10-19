<?php


// require_once dirname(__FILE__) . "/Module/ContentsDatabaseManager.php";

// require_once dirname(__FILE__) . "/Module/Stopwatch.php";


// $sw = new Stopwatch();
// $sw->Start();

// Content::CreateGlobalTagMap("./Master/Contents/Root");
// $sw->Stop();
// echo $sw->ElapsedString() . "<br>";


// var_dump(Content::GlobalTagMap());


// function authenticate() {
//     header('WWW-Authenticate: Basic realm="Test Authentication System"');
//     header('HTTP/1.0 401 Unauthorized');
//     echo "このリソースにアクセスする際には有効なログインIDとパスワードを入力する必要があります。\n";
//     exit;
// }

// if (!isset($_SERVER['PHP_AUTH_USER']) ||
//     ($_POST['SeenBefore'] == 1 && $_POST['OldAuth'] == $_SERVER['PHP_AUTH_USER'])) {
//     authenticate();
// } else {
//     echo "<p>Welcome: " . htmlspecialchars($_SERVER['PHP_AUTH_USER']) . "<br />";
//     echo "Old: " . htmlspecialchars($_REQUEST['OldAuth']);
//     echo "<form action='' method='post'>\n";
//     echo "<input type='hidden' name='SeenBefore' value='1'>\n";
//     echo "<input type='hidden' name='OldAuth' value=\"" . htmlspecialchars($_SERVER['PHP_AUTH_USER']) . "\" />\n";
//     echo "<input type='submit' value='Re Authenticate'>\n";
//     echo "</form></p>\n";
// }

?>