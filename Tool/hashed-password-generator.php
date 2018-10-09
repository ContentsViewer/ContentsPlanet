<?php

require_once dirname(__FILE__) . "/../Module/Authenticator.php";



$username = "";
$password = "";

$hash = password_hash($password, PASSWORD_BCRYPT);
PrintInfo("hashedPassword", $hash);

$digest = md5($username . ':' . Authenticator::Realm() . ':' . $password);
PrintInfo("digest", $digest);



function PrintInfo($name, $content){
    echo $name . ":<br>";
    echo $content . "<br><br>";
}
?>

