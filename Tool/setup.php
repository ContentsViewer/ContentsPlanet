<?php

require_once dirname(__FILE__) . "/../ConMAS.php";
require_once dirname(__FILE__) . "/../Module/Authenticator.php";

// === Please Set below variables ====================================
$username = "";
$password = "";

//

?>


<!DOCTYPE html>
<html lang="ja">

<head>
    <?php readfile("../Client/Common/CommonHead.html");?>
    <title>セットアップガイド</title>
</head>

<body>

<h1>セットアップガイド</h1>
<h2>コンテンツ公開, 編集制限の場合(通常)</h2>
<ol>
    <li>
        このページソース<code>setup.php</code>内にある<code>$username</code>と<code>$password</code>を設定
    </li>

    <li>
        <code>Module/Authenticator.php->$userTable->'master'</code>内にある<code>hashedPassword</code>, <code>digest</code>
        に下で表示されている値をコピー&ペースト
    </li>

    <li>
        <a href='../index.php' target="_blank">メインページ</a>にアクセスできるか確認
    </li>


    <li>
        <a href='../login.php' target="_blank">ログインページ</a>にアクセスし, ログインできるか確認
    </li>

</ol>
<h2>コンテンツ非公開, 編集制限の場合</h2>
<ol>
    <li>
        このページソース<code>setup.php</code>内にある<code>$username</code>と<code>$password</code>を設定
    </li>

    <li>
        <code>Module/Authenticator.php->$userTable->'master'</code>内にある<code>hashedPassword</code>, <code>digest</code>
        に下で表示されている値をコピー&ペースト<br>
        <code>isPublic</code>を<code>false</code>に設定
    </li>

    <li>
        <ul>
            <li>
                <h3>Digest認証の使用(推奨)</h3>

                <ol>
                    <li>
                        <code>Home/Master</code>内に<code>.htaccess</code>を作成し, 下で表示されている<code>.htaccess(Digest)</code>の内容をコピー&ペースト
                    </li>

                    <li>
                        <code>Password</code>内にファイル<code><?='.htdigest-' . $username?></code>を作成し, 下で表示されている内容をコピー&ペースト
                    </li>
                </ol>
            </li>

            <li>
                <h3>Basic認証の使用</h3>

                <ol>
                    <li>
                        <code>Home/Master</code>内に<code>.htaccess</code>を作成し, 下で表示されている<code>.htaccess(Basic)</code>の内容をコピー&ペースト
                    </li>

                    <li>
                        <code>Password</code>内にファイル<code><?='.htbasic-' . $username?></code>を作成し, 下で表示されている内容をコピー&ペースト
                    </li>
                </ol>
            </li>

        </ul>
    </li>

    <li>
        <a href='../login.php' target="_blank">ログインページ</a>にアクセスし, ログインできるか確認
    </li>

    <li>
        <a href='../index.php' target="_blank">メインページ</a>にアクセスできるか確認
    </li>

</ol>

<hr>
<?php

$hash = password_hash($password, PASSWORD_BCRYPT);
PrintInfo('hashedPassword', H($hash));

$digest = md5($username . ':' . Authenticator::Realm() . ':' . $password);
PrintInfo('digest', H($digest));

PrintInfo('.htdigest-' . H($username), H($username) . ':' . H(Authenticator::Realm()) . ':' . H($digest));

PrintInfo('.htaccess(digest)',
    'AuthType Digest<br>' .
    'AuthName "' . H(Authenticator::Realm()) . '"<br>' .
    'AuthUserFile ' . H(PASSWORD_DIR . DIRECTORY_SEPARATOR . '.htdigest-' . $username) . '<br>' .
    'Require valid-user'
);

$basic = CryptApr1Md5($password);
PrintInfo('.htbasic-' . H($username), H($username . ':' . $basic));

PrintInfo('.htaccess(Basic)',
    'AuthType Basic<br>' .
    'AuthName "' . H(Authenticator::Realm()) . '"<br>' .
    'AuthUserFile ' . H(PASSWORD_DIR . DIRECTORY_SEPARATOR . '.htbasic-' . $username) . '<br>' .
    'Require valid-user'
);
function PrintInfo($name, $content)
{
    echo $name . ':<br>';
    echo '<pre>' . $content . '</pre><br><br>';
}

function H($string)
{
    return htmlspecialchars($string);
}

// APR1-MD5 encryption method (windows compatible)
function CryptApr1Md5($plainpasswd)
{
    $salt = substr(str_shuffle("abcdefghijklmnopqrstuvwxyz0123456789"), 0, 8);
    $len = strlen($plainpasswd);
    $text = $plainpasswd . '$apr1$' . $salt;
    $bin = pack("H32", md5($plainpasswd . $salt . $plainpasswd));
    for ($i = $len; $i > 0; $i -= 16) {$text .= substr($bin, 0, min(16, $i));}
    for ($i = $len; $i > 0; $i >>= 1) {$text .= ($i & 1) ? chr(0) : $plainpasswd{0};}
    $bin = pack("H32", md5($text));
    $tmp = '';
    for ($i = 0; $i < 1000; $i++) {
        $new = ($i & 1) ? $plainpasswd : $bin;
        if ($i % 3) {
            $new .= $salt;
        }

        if ($i % 7) {
            $new .= $plainpasswd;
        }

        $new .= ($i & 1) ? $bin : $plainpasswd;
        $bin = pack("H32", md5($new));
    }
    for ($i = 0; $i < 5; $i++) {
        $k = $i + 6;
        $j = $i + 12;
        if ($j == 16) {
            $j = 5;
        }

        $tmp = $bin[$i] . $bin[$k] . $bin[$j] . $tmp;
    }
    $tmp = chr(0) . chr(0) . $bin[11] . $tmp;
    $tmp = strtr(strrev(substr(base64_encode($tmp), 2)),
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/",
        "./0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz");

    return "$" . "apr1" . "$" . $salt . "$" . $tmp;
}

?>
</body>
</html>
