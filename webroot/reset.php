<?php
require_once __DIR__.'/db.php';

// Si viene por POST, intenta cambiar la contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = trim($_POST['token'] ?? '');
  $pass1 = $_POST['pass1'] ?? '';
  $pass2 = $_POST['pass2'] ?? '';

  if (!$token || strlen($pass1) < 6 || $pass1 !== $pass2) {
    $_SESSION['flash_err'] = 'La contraseña debe tener 6+ caracteres y coincidir.';
    header('Location: index.php');
    exit;
  }

  // Busca token válido
  $st = $pdo->prepare("SELECT pr.user_id, pr.expires_at FROM password_resets pr WHERE pr.token=? LIMIT 1");
  $st->execute([$token]);
  $pr = $st->fetch();

  if (!$pr || (int)$pr['expires_at'] < time()) {
    $_SESSION['flash_err'] = 'El enlace de restablecimiento es inválido o expiró.';
    header('Location: index.php');
    exit;
  }

  $uid = (int)$pr['user_id'];

  // Cambia contraseña
  $pdo->prepare("UPDATE users SET pass=? WHERE id=?")
      ->execute([password_hash($pass1, PASSWORD_DEFAULT), $uid]);

  // Elimina tokens del usuario
  $pdo->prepare("DELETE FROM password_resets WHERE user_id=?")->execute([$uid]);

  $_SESSION['flash_ok'] = 'Tu contraseña fue cambiada. Ya puedes iniciar sesión.';
  header('Location: index.php');
  exit;
}

// Si viene por GET, muestra formulario si el token existe (opcional: comprobar expiración aquí también)
$token = trim($_GET['token'] ?? '');
if (!$token) {
  $_SESSION['flash_err'] = 'Falta el token de restablecimiento.';
  header('Location: index.php'); exit;
}

// (Opcional) validar que el token existe antes de mostrar la vista:
$st = $pdo->prepare("SELECT pr.user_id, pr.expires_at FROM password_resets pr WHERE pr.token=? LIMIT 1");
$st->execute([$token]);
$pr = $st->fetch();
if (!$pr || (int)$pr['expires_at'] < time()) {
  $_SESSION['flash_err'] = 'El enlace de restablecimiento es inválido o expiró.';
  header('Location: index.php'); exit;
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Restablecer contraseña — CDN SkyUltraPlus</title>
<style>
  body{margin:0;background:
    radial-gradient(700px 400px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
    radial-gradient(700px 400px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
    linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%); color:#eaf2ff; font:15px/1.6 system-ui}
  .wrap{max-width:900px;margin:0 auto;padding:20px}
  .card{max-width:520px;margin:0 auto;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:20px;backdrop-filter:blur(10px)}
  .btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:10px 14px;font-weight:800;text-decoration:none;cursor:pointer}
  .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
  a{color:#93c5fd}
  .logo{width:120px;display:block;margin:18px auto 10px;filter:drop-shadow(0 18px 50px rgba(219,39,119,.35))}
  h1{margin:0 0 6px;text-align:center}
</style>
</head><body><div class="wrap">
  <img class="logo" src="https://cdn.russellxz.click/47d048e3.png" alt="logo">
  <div class="card">
    <h1>Nueva contraseña</h1>
    <form method="post">
      <input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>">
      <input class="input" type="password" name="pass1" placeholder="Nueva contraseña (6+)" required>
      <input class="input" type="password" name="pass2" placeholder="Repite la contraseña" required style="margin-top:8px">
      <button class="btn" style="margin-top:10px">Cambiar contraseña</button>
    </form>
    <p style="margin-top:10px"><a href="index.php">Volver al inicio</a></p>
  </div>
</div></body></html>