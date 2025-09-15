<?php
require_once __DIR__.'/db.php';
if (session_status()===PHP_SESSION_NONE) { session_start(); }
$session_id = $_GET['session_id'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pago completado</title>
<style>
  body{margin:0;background:#0b0b0d;color:#eaf2ff;font:15px/1.6 system-ui}
  .wrap{max-width:700px;margin:0 auto;padding:24px}
  .card{background:#111827;border:1px solid #334155;border-radius:12px;padding:18px}
  .btn{display:inline-flex;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:8px 12px;font-weight:800;text-decoration:none}
  .muted{color:#9fb0c9}
</style>
</head>
<body><div class="wrap">
  <div class="card">
    <h2>✅ Pago recibido</h2>
    <p class="muted">Gracias. Si no ves reflejado el cambio de plan en unos segundos, recarga tu panel.</p>
    <?php if ($session_id): ?>
      <p class="muted">Ref: <code><?=htmlspecialchars($session_id)?></code></p>
    <?php endif; ?>
    <p style="margin-top:10px"><a class="btn" href="profile.php#pay">Volver al panel</a></p>
  </div>
</div></body></html>
