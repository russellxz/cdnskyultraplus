<?php
require_once __DIR__.'/db.php';

// Si ya está logueado, al perfil
if (!empty($_SESSION['uid'])) {
  header('Location: profile.php');
  exit;
}

// Mensajes flash desde register/otros (se limpian al mostrarlos)
$flash_ok  = $_SESSION['flash_ok']  ?? null;
$flash_err = $_SESSION['flash_err'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// Texto de planes (fallback si no lo definiste en config.php)
$plansText = defined('PLANS_TEXT')
  ? PLANS_TEXT
  : 'Planes: +50 archivos $1.37 · +120 $2.45 · +250 $3.55 · Deluxe $2.50/mes.';

// Helper: permitir HTML seguro en alerts (solo enlaces/énfasis)
function flash_html(string $s): string {
  return strip_tags($s, '<a><b><strong><em>');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<title>CDN SkyUltraPlus — Iniciar sesión</title>
<style>
  *{box-sizing:border-box}
  body{
    margin:0;font:15px/1.6 system-ui;color:#eaf2ff;
    background:
      radial-gradient(700px 400px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
      radial-gradient(700px 400px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
      linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%);
  }
  .wrap{max-width:900px;margin:0 auto;padding:20px}
  .card{
    max-width:520px;margin:0 auto;
    background:rgba(255,255,255,.07);
    border:1px solid rgba(255,255,255,.15);
    border-radius:16px;padding:20px;backdrop-filter:blur(10px)
  }
  .btn{
    display:inline-flex;align-items:center;gap:8px;
    background:linear-gradient(90deg,#0ea5e9,#22d3ee);
    color:#051425;border:none;border-radius:10px;
    padding:10px 14px;font-weight:800;text-decoration:none;cursor:pointer
  }
  .btn.ghost{background:transparent;border:1px solid #334155;color:#eaf2ff}
  .input{
    width:100%;padding:10px;border-radius:10px;
    border:1px solid #334155;background:#0f172a;color:#eaf2ff
  }
  .top{text-align:center;margin:18px 0}
  .logo{width:120px;display:block;margin:12px auto 8px;
        filter:drop-shadow(0 18px 50px rgba(219,39,119,.35))}
  a{color:#93c5fd}
  h1{
    margin:0 0 6px;font-size:28px;
    background:linear-gradient(90deg,#60a5fa,#a78bfa,#f472b6);
    -webkit-background-clip:text;background-clip:text;color:transparent
  }
  p.muted{color:#9fb0c9}

  /* Alerts */
  .alert{
    max-width:520px;margin:0 auto 12px;
    border-radius:12px;padding:12px 14px;
    border:1px solid; line-height:1.4
  }
  .ok{ background:rgba(34,197,94,.12); border-color:rgba(34,197,94,.5); color:#c8ffd7 }
  .err{ background:rgba(239,68,68,.12); border-color:rgba(239,68,68,.5); color:#ffd3d3 }

  /* --- bloque soporte debajo del “crear cuenta / olvidé” --- */
  .support-cta{
    margin-top:12px;padding:12px;border-radius:12px;
    background:rgba(15,23,42,.6);border:1px solid #2b3956
  }
  .support-cta p{margin:0 0 8px}
  .support-actions{display:flex;gap:8px;flex-wrap:wrap}
  .support-actions .btn{border-radius:9px}
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <img class="logo" src="https://cdn.russellxz.click/47d048e3.png" alt="SkyUltraPlus">
    <h1>Bienvenido al (CDN) de SkyUltraPlus</h1>
    <p class="muted">Sube y gestiona tus archivos con protección y velocidad ⚡. <?=htmlspecialchars($plansText)?></p>
  </div>

  <?php if ($flash_ok): ?>
    <div class="alert ok" role="alert" aria-live="polite"><?= flash_html($flash_ok) ?></div>
  <?php endif; ?>
  <?php if ($flash_err): ?>
    <div class="alert err" role="alert" aria-live="assertive"><?= flash_html($flash_err) ?></div>
  <?php endif; ?>

  <div class="card">
    <h3>Iniciar sesión</h3>
    <form method="post" action="login.php" autocomplete="on">
      <input class="input" name="user" placeholder="Usuario o correo" required autofocus autocomplete="username">
      <input class="input" type="password" name="pass" placeholder="Contraseña" required style="margin-top:8px" autocomplete="current-password">
      <button class="btn" style="margin-top:10px">Entrar</button>
    </form>
    <p style="margin-top:10px">
      ¿No tienes cuenta? <a href="register.php">Crea tu cuenta</a> ·
      <a href="forgot.php">Olvidé mi contraseña</a>
    </p>

    <!-- BLOQUE DE SOPORTE (debajo del texto anterior) -->
    <div class="support-cta">
      <p class="muted">
        ¿No puedes registrarte, no te llega el correo de verificación o tienes otro problema?
      </p>
      <div class="support-actions">
        <a class="btn" href="support.php">💬 Hablar con soporte</a>
        <a class="btn ghost" href="mailto:soporte@skyultraplus.com">✉️ Enviar correo</a>
      </div>
    </div>
    <!-- /SOPORTE -->
  </div>

</div>

<script>
// Autocerrar alertas a los 6s
setTimeout(()=>{
  document.querySelectorAll('.alert').forEach(a=>a.remove());
}, 6000);
</script>
</body>
</html>
