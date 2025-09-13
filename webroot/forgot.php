<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/mail.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $email = function_exists('mb_strtolower') ? mb_strtolower($email) : strtolower($email);
  $valid = filter_var($email, FILTER_VALIDATE_EMAIL);

  // Siempre mostramos mismo resultado para no revelar existencia del correo
  $_SESSION['flash_ok'] = 'Si el correo existe, te enviamos un enlace para restablecer tu contraseña. Revisa tu bandeja y spam.';

  if ($valid) {
    // Busca usuario
    $st = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    if ($row = $st->fetch()) {
      $uid   = (int)$row['id'];
      $token = bin2hex(random_bytes(24));
      $exp   = time() + 3600; // 1h

      // Limpia tokens viejos del usuario (opcional)
      $pdo->prepare("DELETE FROM password_resets WHERE user_id=?")->execute([$uid]);
      // Inserta nuevo
      $pdo->prepare("INSERT INTO password_resets(user_id,token,expires_at) VALUES(?,?,?)")
          ->execute([$uid,$token,$exp]);

      // Enviar correo (plantilla HTML simple)
      $link = BASE_URL.'/reset.php?token='.urlencode($token).'&email='.urlencode($email);
      $logo = 'https://cdn.russellxz.click/e37b8238.png';
      $html = '
        <div style="background:#0b0b0d;color:#eaf2ff;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px">
          <div style="max-width:560px;margin:0 auto;background:#111827;border:1px solid #334155;border-radius:14px;overflow:hidden">
            <div style="text-align:center;padding:18px 18px 0">
              <img src="'.$logo.'" alt="Sky Ultra Plus" style="width:120px;height:auto;display:inline-block;filter:drop-shadow(0 10px 30px rgba(219,39,119,.35))">
            </div>
            <div style="padding:18px">
              <h2 style="margin:0 0 8px">Restablecer tu contraseña</h2>
              <p style="margin:0 0 14px;color:#cbd5e1">Solicitaste cambiar la contraseña de tu cuenta del CDN de SkyUltraPlus.</p>
              <p style="text-align:center;margin:18px 0">
                <a href="'.$link.'" style="background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;font-weight:800;padding:12px 16px;border-radius:10px;text-decoration:none;display:inline-block">Restablecer contraseña</a>
              </p>
              <p style="margin:0 0 10px;color:#94a3b8">Si el botón no funciona, copia y pega este enlace:</p>
              <p style="word-break:break-all;margin:0 0 16px"><a href="'.$link.'" style="color:#22d3ee">'.$link.'</a></p>
              <p style="font-size:12px;color:#94a3b8;margin:0">Si no fuiste tú, ignora este mensaje.</p>
            </div>
          </div>
        </div>';

      // Enviar (si falla, igual redirigimos con el flash genérico de arriba)
      send_custom_email($email, 'Restablece tu contraseña — SkyUltraPlus CDN', $html, $err);
    }
  }

  header('Location: index.php');
  exit;
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Olvidé mi contraseña — CDN SkyUltraPlus</title>
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
    <h1>Olvidé mi contraseña</h1>
    <form method="post">
      <input class="input" name="email" type="email" placeholder="Tu correo" required>
      <button class="btn" style="margin-top:10px">Enviar enlace</button>
    </form>
    <p style="margin-top:10px">¿Ya la recordaste? <a href="index.php">Volver a inicio</a></p>
  </div>
</div></body></html>