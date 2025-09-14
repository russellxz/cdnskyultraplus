<?php
require_once __DIR__.'/db.php';
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];

$me = $pdo->prepare("SELECT * FROM users WHERE id=?");
$me->execute([$uid]);
$me = $me->fetch();
if (!$me || !(int)$me['is_admin']) { http_response_code(403); exit('403'); }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'regen') {
    $new = rand_key(40);
    $st = $pdo->prepare("UPDATE users SET api_key=? WHERE id=?");
    $st->execute([$new, $uid]);
    $me['api_key'] = $new;
    $msg = 'API Key regenerada.';
  }

  if ($action === 'save') {
    $quota = max(0, (int)($_POST['quota_limit'] ?? $me['quota_limit']));
    $deluxe = isset($_POST['is_deluxe']) ? 1 : 0;
    $st = $pdo->prepare("UPDATE users SET quota_limit=?, is_deluxe=? WHERE id=?");
    $st->execute([$quota, $deluxe, $uid]);
    $me['quota_limit'] = $quota;
    $me['is_deluxe'] = $deluxe;
    $msg = 'Cambios guardados.';
  }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi perfil (Admin)</title>
<style>
  body{margin:0;background:#0b0b0d;color:#eaf2ff;font:15px/1.6 system-ui}
  .wrap{max-width:700px;margin:0 auto;padding:20px}
  .card{background:#111827;border:1px solid #334155;border-radius:12px;padding:18px;margin-bottom:14px}
  .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
  .btn{display:inline-flex;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:8px 12px;font-weight:800;cursor:pointer;text-decoration:none}
  label.small{font-size:12px;color:#94a3b8;margin:0 0 4px;display:block}
  .muted{color:#9fb0c9}
</style>
</head>
<body>
<div class="wrap">
  <h2>Mi perfil (Admin)</h2>
  <p><a href="admin.php" style="color:#93c5fd">← Volver</a></p>

  <?php if ($msg): ?>
    <div class="card" style="background:#0f172a;border-color:#22d3ee"><?=htmlspecialchars($msg)?></div>
  <?php endif; ?>

  <div class="card">
    <h3>API Key</h3>
    <p class="muted">Úsala para integraciones externas (bots, etc.).</p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <code style="background:#0f172a;border:1px solid #334155;border-radius:6px;padding:6px 8px;user-select:all"><?=htmlspecialchars($me['api_key'] ?? '')?></code>
      <form method="post" style="margin:0">
        <input type="hidden" name="action" value="regen">
        <button class="btn">Regenerar</button>
      </form>
    </div>
  </div>

  <div class="card">
    <h3>Capacidad y Deluxe</h3>
    <form method="post">
      <input type="hidden" name="action" value="save">
      <label class="small">Límite de archivos</label>
      <input class="input" type="number" name="quota_limit" value="<?= (int)$me['quota_limit'] ?>" min="0">
      <div style="margin-top:10px">
        <label><input type="checkbox" name="is_deluxe" value="1" <?= ((int)$me['is_deluxe']===1)?'checked':'' ?>> Marcar cuenta como Deluxe</label>
      </div>
      <div style="margin-top:12px">
        <button class="btn">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
