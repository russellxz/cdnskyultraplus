<?php
require __DIR__.'/db.php';
$token = $_GET['token'] ?? '';
if (!preg_match('/^[a-f0-9]{48}$/i',$token)) exit('Token faltante/ inválido.');
$st=$pdo->prepare("UPDATE users SET verified=1, verify_token=NULL WHERE verify_token=? AND (verified=0 OR verified IS NULL)");
$st->execute([$token]);
echo $st->rowCount()? "✅ Tu cuenta fue verificada. <a href='index.php'>Inicia sesión</a>" : "Token inválido o usado.";