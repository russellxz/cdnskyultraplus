<?php
require_once __DIR__.'/db.php';
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid=(int)$_SESSION['uid'];
$st=$pdo->prepare("SELECT first_name,last_name,username FROM users WHERE id=?");
$st->execute([$uid]); $me=$st->fetch();
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configuración — CDN</title>
<style>
 body{margin:0;font:15px/1.6 system-ui;background:
  radial-gradient(700px 400px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
  radial-gradient(700px 400px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
  linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%); color:#eaf2ff}
 .wrap{max-width:720px;margin:0 auto;padding:20px}
 .card{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:18px}
 .btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer;text-decoration:none}
 .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
 a{color:#93c5fd}
</style></head><body><div class="wrap">
  <h2>⚙️ Configuración de perfil</h2>
  <div style="margin-bottom:10px">
    <a class="btn" href="profile.php">⬅️ Volver al panel</a>
    <a class="btn" href="list.php">📁 Ver mis archivos</a>
  </div>
  <div class="card">
    <form method="post" action="profile_save.php">
      <label>Nombre</label>
      <input class="input" name="first_name" value="<?=htmlspecialchars($me['first_name'])?>" required>
      <label style="margin-top:8px">Apellido</label>
      <input class="input" name="last_name" value="<?=htmlspecialchars($me['last_name'])?>" required>
      <label style="margin-top:8px">Usuario</label>
      <input class="input" name="username" value="<?=htmlspecialchars($me['username'])?>" required>
      <hr style="border-color:#334155;margin:12px 0">
      <label>Contraseña actual</label>
      <input class="input" type="password" name="current" placeholder="Opcional">
      <label style="margin-top:8px">Nueva contraseña (6+)</label>
      <input class="input" type="password" name="new" placeholder="Opcional">
      <button class="btn" style="margin-top:12px">Guardar cambios</button>
    </form>
  </div>
</div></body></html>