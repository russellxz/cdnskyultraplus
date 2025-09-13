<?php
require_once __DIR__.'/db.php';
if (empty($_SESSION['uid'])) { header('Location:index.php'); exit; }
$uid=(int)$_SESSION['uid'];

$first=trim($_POST['first_name']??'');
$last =trim($_POST['last_name']??'');
$user =trim($_POST['username']??'');
$cur  = $_POST['current']??'';
$new  = $_POST['new']??'';

// Actualiza nombre/usuario
if($first||$last||$user){
  try{
    $pdo->prepare("UPDATE users SET first_name=?, last_name=?, username=? WHERE id=?")
        ->execute([$first,$last,$user,$uid]);
  }catch(Throwable $e){ exit('❌ Usuario ya en uso'); }
}

// Cambiar contraseña si corresponde
if($new){
  if(strlen($new)<6) exit('❌ Contraseña muy corta');
  $st=$pdo->prepare("SELECT pass FROM users WHERE id=?"); $st->execute([$uid]); $hash=$st->fetchColumn();
  if(!$hash || !password_verify($cur,$hash)) exit('❌ Contraseña actual incorrecta');
  $pdo->prepare("UPDATE users SET pass=? WHERE id=?")->execute([password_hash($new,PASSWORD_DEFAULT),$uid]);
}

header('Location: profile.php');