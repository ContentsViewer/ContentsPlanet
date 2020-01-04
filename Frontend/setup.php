<?php

require_once(MODULE_DIR . '/Authenticator.php');
require_once(MODULE_DIR . '/Utils.php');

// === Please Set below variables ====================================
$username = "";
$password = "";

//


?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>
  <title>セットアップガイド</title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-setup.ico" type="image/vnd.microsoft.icon" />

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
</head>

<body>
  <h1>セットアップガイド</h1>

  <ul>
    <li>
      このセットアップガイドは, CollabCMSに必ず必要となる<strong>Masterユーザの設定方法の説明</strong>を行います.
    </li>
    <li>
      Masterユーザ以外のユーザ設定でも, このセットアップガイドに書かれている<strong>コンテンツフォルダパス</strong>を読み替える
      だけで, <strong>基本的に同じ</strong>です.
    </li>
    <li>
      このセットアップガイドは, <strong>CollabCMSが実際に置かれるサーバ上</strong>で動作させてください.
    </li>
    <li>
      セットアップ終了後, <strong>必ず</strong>このスクリプト上に書いた<strong>ユーザ名</strong>, <strong>パスワード</strong>
      は<strong>消去</strong>してください.
    </li>
  </ul>

  <h2>コンテンツ公開, 編集制限の場合(通常)</h2>
  <ol>
    <li>
      このページソース<code>Frontend/setup.php</code>内にある<code>$username</code>と<code>$password</code>を設定<br>
      Masterユーザであるからといって,ユーザ名を'master'にする必要はありません.
    </li>

    <li>
      <code>CollabCMS.php->USER_TABLE->'master'</code>内にある<code>hashedPassword</code>, <code>digest</code>
      に下で表示されている値をコピー&amp;ペースト<br>
      <code>USER_TABLE->'master'</code>の'master'を設定したいユーザ名<?=H($username)?>に設定
    </li>

    <li>
      <a href='<?=ROOT_URI?>/Login' target="_blank">ログインページ</a>にアクセスし, ログインできるか確認
    </li>

    <li>
      <a href='<?=ROOT_URI?>/' target="_blank">メインページ</a>にアクセスできるか確認
    </li>
  </ol>

  <h2>コンテンツ非公開, 編集制限の場合</h2>
  <ol>
    <li>
      このページソース<code>Frontend/setup.php</code>内にある<code>$username</code>と<code>$password</code>を設定<br>
      Masterユーザであるからといって,ユーザ名を'master'にする必要はありません.
    </li>

    <li>
      <code>CollabCMS.php->USER_TABLE->'master'</code>内にある<code>hashedPassword</code>, <code>digest</code>
      に下で表示されている値をコピー&amp;ペースト<br>
      <code>isPublic</code>を<code>false</code>に設定<br>
      <code>USER_TABLE->'master'</code>の'master'を設定したいユーザ名<?=H($username)?>に設定
    </li>

    <li>
      <a href='<?=ROOT_URI?>/Login' target="_blank">ログインページ</a>にアクセスし, ログインできるか確認
    </li>

    <li>
      <a href='<?=ROOT_URI?>/' target="_blank">メインページ</a>にアクセスできるか確認
    </li>
  </ol>

  <hr>
  <?php
  $hash = password_hash($password, PASSWORD_BCRYPT);
  PrintInfo('hashedPassword', H($hash));

  $digest = md5($username . ':' . Authenticator::REALM . ':' . $password);
  PrintInfo('digest', H($digest));

  function PrintInfo($name, $content) {
    echo $name . ':<br>';
    echo '<pre>' . $content . '</pre><br><br>';
  }
  ?>
</body>

</html>