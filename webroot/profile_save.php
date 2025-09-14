<?php
require_once __DIR__.'/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];

function back($ok=null, $err=null){
  if ($ok)  $_SESSION['flash_ok']  = $ok;
  if ($err) $_SESSION['flash_err'] = $err;
  header('Location: settings.php'); exit;
}

try{
  // Traer datos actuales
  $st=$pdo->prepare("SELECT id, username, pass, is_deluxe, api_key FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  $cur=$st->fetch();
  if(!$cur) back(null,'Usuario no encontrado.');

  // Campos
  $first = trim($_POST['first_name'] ?? '');
  $last  = trim($_POST['last_name'] ?? '');
  $user  = trim($_POST['username'] ?? '');
  $api   = trim($_POST['api_key'] ?? '');
  $curp  = $_POST['current'] ?? '';
  $newp  = $_POST['new'] ?? '';
  $new2  = $_POST['new2'] ?? '';

  if ($first==='' || $last==='' || $user==='') back(null,'Nombre, apellido y usuario son obligatorios.');

  // Username único (ignorar el propio id)
  $st=$pdo->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
  $st->execute([$user, $uid]);
  if ($st->fetch()) back(null,'Ese nombre de usuario ya está en uso.');

  // API key: sólo si Deluxe
  $isDeluxe = ((int)$cur['is_deluxe']===1);
  $apiToSet = $cur['api_key']; // por defecto no cambia
  if ($isDeluxe) {
    if ($api==='') back(null,'La API key no puede estar vacía para Deluxe.');
    if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $api)) back(null,'API key inválida. Usa 6–64 caracteres A-Z, a-z, 0-9, - y _.');
    // Unicidad de API key
    $st=$pdo->prepare("SELECT id FROM users WHERE api_key=? AND id<>? LIMIT 1");
    $st->execute([$api, $uid]);
    if ($st->fetch()) back(null,'Esa API key ya está en uso por otro usuario.');
    $apiToSet = $api;
  }

  // Cambio de contraseña (opcional)
  $setPass = null;
  $wantsPass = (trim($newp) !== '' || trim($new2) !== '');
  if ($wantsPass) {
    if (trim($curp)==='') back(null,'Debes escribir tu contraseña actual para cambiarla.');
    if (!password_verify($curp, $cur['pass'])) back(null,'Tu contraseña actual no es correcta.');
    if (strlen($newp) < 6) back(null,'La nueva contraseña debe tener al menos 6 caracteres.');
    if ($newp !== $new2) back(null,'Las contraseñas nuevas no coinciden.');
    $setPass = password_hash($newp, PASSWORD_DEFAULT);
  }

  // Construir UPDATE dinámico
  $sql = "UPDATE users SET first_name=?, last_name=?, username=?, api_key=?";
  $params = [$first, $last, $user, $apiToSet];
  if ($setPass !== null) { $sql .= ", pass=?"; $params[] = $setPass; }
  $sql .= " WHERE id=?";
  $params[] = $uid;

  $st=$pdo->prepare($sql);
  $st->execute($params);

  back('Cambios guardados correctamente.');
} catch(Throwable $e){
  back(null,'Error al guardar cambios.');
}
