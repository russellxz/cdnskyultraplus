<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/mail.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $first = trim($_POST['first'] ?? '');
  $last  = trim($_POST['last'] ?? '');
  $user  = trim($_POST['username'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['pass'] ?? '';
  $ip    = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

  $lower = function($s){ return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s); };
  $email = filter_var($lower($email), FILTER_VALIDATE_EMAIL);

  // Validación básica
  if (!$first || !$last || !$user || !$email || strlen($pass) < 6) {
    $_SESSION['flash_err'] = '❌ Completa nombre, apellido, usuario, correo válido y contraseña (6+).';
    header('Location: index.php'); exit;
  }

  // Bloqueo por IP si está activado en settings
  $block = setting_get('ip_block_enabled','1') === '1';
  if ($block) {
    $chk = $pdo->prepare("SELECT is_admin FROM users WHERE registration_ip=? LIMIT 1");
    $chk->execute([$ip]);
    $r = $chk->fetch();
    if ($r && intval($r['is_admin']) === 0) {
      $_SESSION['flash_err'] = '❌ Ya existe una cuenta creada desde tu IP.';
      header('Location: index.php'); exit;
    }
  }

  $api   = rand_key(16);   // API key corta (luego el admin puede editarla)
  $token = rand_key(48);   // token de verificación

  try {
    $pdo->prepare("
      INSERT INTO users(
        email, username, first_name, last_name, pass, api_key,
        is_admin, is_deluxe, verified, verify_token, quota_limit, registration_ip
      ) VALUES(?,?,?,?,?,?, 0,0,0, ?, 50, ?)
    ")->execute([
      $email, $user, $first, $last,
      password_hash($pass, PASSWORD_DEFAULT),
      $api,
      $token, $ip
    ]);

    // Intentar enviar verificación (no bloquea el flujo si falla)
    $sent = false;
    try { $sent = send_verify_email($email, $token, $err); } catch (Throwable $e) { $sent = false; }

    $_SESSION['flash_ok'] = $sent
      ? '✅ Cuenta creada. Revisa tu correo para verificar tu cuenta.'
      : '✅ Cuenta creada. ⚠️ No se pudo enviar el correo automático; solicita verificación manual a soporte.';

    header('Location: index.php'); exit;

  } catch (Throwable $e) {
    $m = $e->getMessage();
    $_SESSION['flash_err'] = (stripos($m,'unique') !== false)
      ? '❌ Usuario o correo ya registrado.'
      : '❌ Error inesperado al registrar.';
    header('Location: index.php'); exit;
  }
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Crear cuenta — CDN SkyUltraPlus</title>
<style>
  body{margin:0;background:
    radial-gradient(700px 400px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
    radial-gradient(700px 400px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
    linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%); color:#eaf2ff; font:15px/1.6 system-ui}
  .wrap{max-width:900px;margin:0 auto;padding:20px}
  .card{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:20px}
  .btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer}
  .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
  .logo{width:120px;display:block;margin:12px auto 8px}
  a{color:#93c5fd}
</style></head><body><div class="wrap">
  <img class="logo" src="https://cdn.russellxz.click/47d048e3.png" alt="logo">
  <div class="card" style="max-width:560px;margin:0 auto">
    <h2>Crear cuenta</h2>
    <form method="post">
      <input class="input" name="first" placeholder="Nombre" required>
      <input class="input" name="last"  placeholder="Apellido" required style="margin-top:8px">
      <input class="input" name="username" placeholder="Nombre de usuario" required style="margin-top:8px">
      <input class="input" name="email" type="email" placeholder="Correo" required style="margin-top:8px">
      <input class="input" name="pass" type="password" placeholder="Contraseña (6+)" required style="margin-top:8px">
      <button class="btn" style="margin-top:10px">Registrarme</button>
    </form>
    <p style="margin-top:10px">¿Ya tienes cuenta? <a href="index.php">Inicia sesión</a></p>
  </div>
</div></body></html>