<?php

require_once dirname(__FILE__) . "/Module/Authenticator.php";


Authenticator::RequireUnloginedSession();

foreach(['username', 'password', 'token', 'submit'] as $key){
    $$key = (string)filter_input(INPUT_POST, $key);
}





// エラーの情報を格納する配列の用意
$errors = [];

// POSTのときのみ実行
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if($username === "" || $password === ""){
        $errors[] = 'ユーザ名またはパスワードが入力されていません.';
    }

    else{
        $username = Authenticator::H($username);
        $password = Authenticator::H($password);
        
        //Debug::Log($adminUsername);
        if(
            password_verify($password, Authenticator::GetHashedPassword($username)) &&
            Authenticator::ValidateCsrfToken($token)
            ){

            // 認証が成功
            
            // セッションのIDの追跡を防ぐため, セッションIDの再割り当て
            session_regenerate_id(true);

            // ユーザ名を設定
            $_SESSION['username'] = $username;

            // ログイン後のページへ遷移
            header('Location: ./' . Authenticator::LoginedPage());

            exit;
            
        }

        // 認証の失敗
        $errors[] = 'ユーザ名またはパスワードが違います.';
    }
}


header ('Content-Type: text/html; charset=UTF-8');
?>


<!DOCTYPE html>
<html lang="ja">

<head>
    <title>ログインページ</title>

</head>


<body>
    <?php if ($errors): ?>
    <ul>
        <?php foreach ($errors as $err): ?>
        <li><?=Authenticator::H($err)?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>


    <form method="post" action="">
        <p>
            ユーザ名: <input type="text" name="username" value="<?php ?>">
        </p>

        <p>
            パスワード: <input type="password" name="password" value="">
        </p>

        <!-- トークン -->
        <input type="hidden" name="token" value="<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>"> 
        
        <p>
            <input type="submit" name="submit" value="ログイン">
        </p>
    </form>

</body>


</html>