<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/mail.php';

if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];
$me  = $pdo->query("SELECT * FROM users WHERE id=$uid")->fetch();
if (!$me || !$me['is_admin']) { http_response_code(403); exit('403'); }

/* ====== ACTIONS (POST) ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'toggle_ip') {
    $val = setting_get('ip_block_enabled','1') === '1' ? '0' : '1';
    setting_set('ip_block_enabled', $val);
    exit('OK');
  }

  if ($action === 'set_smtp') {
    setting_set('smtp_host', $_POST['host'] ?? '');
    setting_set('smtp_port', $_POST['port'] ?? '587');
    setting_set('smtp_user', $_POST['user'] ?? '');
    setting_set('smtp_pass', $_POST['pass'] ?? '');
    setting_set('smtp_from', $_POST['from'] ?? '');
    setting_set('smtp_name', $_POST['name'] ?? '');
    exit('OK');
  }

  if ($action === 'user_update') {
    $id = (int)($_POST['id'] ?? 0);
    // proteger root admin por email
    $email = $pdo->query("SELECT email FROM users WHERE id=$id")->fetchColumn();
    if ($email === ROOT_ADMIN_EMAIL) exit('ROOT protegido');

    $api   = trim($_POST['api_key'] ?? '');           // permite API keys cortas
    $adm   = !empty($_POST['is_admin'])  ? 1 : 0;
    $dlx   = !empty($_POST['is_deluxe']) ? 1 : 0;
    $quota = (int)($_POST['quota_limit'] ?? 50);

    $pdo->prepare("UPDATE users SET api_key=?, is_admin=?, is_deluxe=?, quota_limit=? WHERE id=?")
        ->execute([$api, $adm, $dlx, $quota, $id]);

    exit('OK');
  }

  if ($action === 'user_delete') {
    $id = (int)($_POST['id'] ?? 0);
    $email = $pdo->query("SELECT email FROM users WHERE id=$id")->fetchColumn();
    if ($email === ROOT_ADMIN_EMAIL) exit('ROOT protegido');

    // Borrar físicamente los archivos usando la tabla (más seguro)
    $st = $pdo->prepare("SELECT path FROM files WHERE user_id=?");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
      if ($p && is_file($p)) @unlink($p);
      // intenta limpiar directorio padre si queda vacío
      $dir = dirname($p);
      if (is_dir($dir)) @rmdir($dir);
    }
    $pdo->prepare("DELETE FROM files WHERE user_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    exit('OK');
  }

  if ($action === 'mail_send') {
    $to  = trim($_POST['to'] ?? '');
    $sub = trim($_POST['subject'] ?? '(sin asunto)');
    $msg = trim($_POST['message'] ?? '');
    if ($to === '*') {
      $emails = $pdo->query("SELECT email FROM users")->fetchAll(PDO::FETCH_COLUMN);
      $ok=0; foreach($emails as $e){ if (send_custom_email($e,$sub,$msg,$err)) $ok++; }
      exit("Enviados: $ok");
    } else {
      $ok = send_custom_email($to,$sub,$msg,$err);
      exit($ok ? 'OK' : 'ERR: '.$err);
    }
  }

  exit;
}

/* ====== DATA ====== */
$ipon = setting_get('ip_block_enabled','1') === '1';

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
  // NADA de full_name: usamos first_name/last_name
  $sql = "SELECT id,email,username,first_name,last_name,is_admin,is_deluxe,quota_limit,verified,api_key
          FROM users
          WHERE email LIKE :q
             OR username LIKE :q
             OR first_name LIKE :q
             OR last_name LIKE :q
          ORDER BY id DESC LIMIT 100";
  $st = $pdo->prepare($sql);
  $st->execute([':q'=>'%'.$q.'%']);
} else {
  $st = $pdo->query("SELECT id,email,username,first_name,last_name,is_admin,is_deluxe,quota_limit,verified,api_key
                     FROM users ORDER BY id DESC LIMIT 50");
}
$users = $st->fetchAll();
$smtp  = smtp_get();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel Admin — CDN</title>
<style>
  body{margin:0;background:#0b0b0d;color:#eaf2ff;font:15px/1.6 system-ui}
  .wrap{max-width:1100px;margin:0 auto;padding:20px}
  .card{background:#111827;border:1px solid #334155;border-radius:12px;padding:18px;margin-bottom:14px}
  .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
  .btn{display:inline-flex;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:8px 12px;font-weight:800;cursor:pointer;text-decoration:none}
  table{width:100%;border-collapse:collapse} td,th{border-bottom:1px solid #273042;padding:8px}
  a{color:#93c5fd}
  label.small{font-size:12px;color:#94a3b8;margin-right:6px}
</style>
</head>
<body><div class="wrap">
  <h2>Panel Admin</h2>

  <div class="card">
    <b>Bloqueo por IP:</b> <span id="ipstate"><?=$ipon?'ON':'OFF'?></span>
    <button class="btn" onclick="tgl()">Alternar</button>
    <script>
      async function tgl(){
        const r=await fetch('admin.php',{method:'POST',body:new URLSearchParams({action:'toggle_ip'})});
        location.reload();
      }
    </script>
  </div>

  <div class="card">
    <h3>SMTP</h3>
    <form onsubmit="saveSMTP(event)">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div><label class="small">Host</label><input class="input" name="host" value="<?=htmlspecialchars($smtp['host'])?>" placeholder="smtp.tu-dominio.com"></div>
        <div><label class="small">Puerto</label><input class="input" name="port" value="<?=htmlspecialchars($smtp['port'])?>" placeholder="587"></div>
        <div><label class="small">Usuario</label><input class="input" name="user" value="<?=htmlspecialchars($smtp['user'])?>" placeholder="correo@dominio.com"></div>
        <div><label class="small">Contraseña</label><input class="input" name="pass" value="<?=htmlspecialchars($smtp['pass'])?>" placeholder="••••••"></div>
        <div><label class="small">From</label><input class="input" name="from" value="<?=htmlspecialchars($smtp['from'])?>" placeholder="correo@dominio.com"></div>
        <div><label class="small">Nombre remitente</label><input class="input" name="name" value="<?=htmlspecialchars($smtp['name'])?>" placeholder="SkyUltraPlus"></div>
      </div>
      <button class="btn" style="margin-top:8px">Guardar SMTP</button>
    </form>
    <script>
      async function saveSMTP(e){
        e.preventDefault();
        const fd=new FormData(e.target); fd.append('action','set_smtp');
        const r=await fetch('admin.php',{method:'POST',body:fd}); alert(await r.text());
      }
    </script>
  </div>

  <div class="card">
    <h3>Enviar correo</h3>
    <form onsubmit="sendMail(event)">
      <input class="input" name="to" placeholder="Correo destino o * para todos" required>
      <input class="input" name="subject" placeholder="Asunto" style="margin-top:8px" required>
      <textarea class="input" name="message" placeholder="Mensaje HTML" style="margin-top:8px;height:120px" required></textarea>
      <button class="btn" style="margin-top:8px">Enviar</button>
    </form>
    <script>
      async function sendMail(e){
        e.preventDefault();
        const fd=new FormData(e.target); fd.append('action','mail_send');
        const r=await fetch('admin.php',{method:'POST',body:fd}); alert(await r.text());
      }
    </script>
  </div>

  <div class="card">
    <h3>Usuarios</h3>
    <form method="get">
      <input class="input" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Buscar por nombre, usuario o correo">
    </form>
    <div style="overflow:auto;margin-top:8px">
      <table>
        <tr>
          <th>ID</th><th>Usuario</th><th>Nombre</th><th>Correo</th>
          <th>Verif</th><th>Admin</th><th>Deluxe</th><th>Quota</th><th>API Key</th><th>Acciones</th>
        </tr>
        <?php foreach($users as $u): ?>
          <tr>
            <td><?=$u['id']?></td>
            <td><?=htmlspecialchars($u['username'])?></td>
            <td><?=htmlspecialchars(trim(($u['first_name']??'').' '.($u['last_name']??'')))?></td>
            <td><?=htmlspecialchars($u['email'])?></td>
            <td><?=$u['verified']?'✔️':'—'?></td>
            <td><input type="checkbox" id="ad<?=$u['id']?>" <?=$u['is_admin']?'checked':''?> <?=$u['email']===ROOT_ADMIN_EMAIL?'disabled':''?>></td>
            <td><input type="checkbox" id="dx<?=$u['id']?>" <?=$u['is_deluxe']?'checked':''?>></td>
            <td><input class="input" style="width:90px" type="number" id="qt<?=$u['id']?>" value="<?=$u['quota_limit']?>"></td>
            <td><input class="input" id="api<?=$u['id']?>" value="<?=htmlspecialchars($u['api_key'])?>"></td>
            <td>
              <button class="btn" onclick="upd(<?=$u['id']?>)" >Guardar</button>
              <?php if($u['email']!==ROOT_ADMIN_EMAIL): ?>
                <button class="btn" onclick="delu(<?=$u['id']?>)" style="background:#f87171;margin-left:6px">Eliminar</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <script>
      async function upd(id){
        const fd=new FormData();
        fd.append('action','user_update');
        fd.append('id', id);
        fd.append('api_key', document.getElementById('api'+id).value);
        fd.append('is_admin', document.getElementById('ad'+id)?.checked ? '1':'0');
        fd.append('is_deluxe', document.getElementById('dx'+id)?.checked ? '1':'0');
        fd.append('quota_limit', document.getElementById('qt'+id).value);
        const r=await fetch('admin.php',{method:'POST',body:fd});
        alert(await r.text());
        location.reload();
      }
      async function delu(id){
        if(!confirm('¿Eliminar usuario y sus archivos?')) return;
        const fd=new FormData(); fd.append('action','user_delete'); fd.append('id',id);
        const r=await fetch('admin.php',{method:'POST',body:fd});
        alert(await r.text());
        location.reload();
      }
    </script>
  </div>

  <p><a href="profile.php">Volver</a></p>
</div></body></html>