<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/mail.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];
$me  = $pdo->query("SELECT * FROM users WHERE id=$uid")->fetch();
if (!$me || (int)$me['is_admin'] !== 1) { http_response_code(403); exit('403'); }

/* ----------------------- Helpers ----------------------- */
function json_out(array $a){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function bytes_fmt(int|float $b): string {
  $u=['B','KB','MB','GB','TB']; $i=0; while($b>=1024 && $i<count($u)-1){ $b/=1024; $i++; }
  return number_format($b, $b>=10?0:1).' '.$u[$i];
}

/* --------- Métricas del servidor (para el monitor en tiempo real) --------- */
function metric_cpu_percent(float $interval=0.2): float {
  $line = @file('/proc/stat')[0] ?? '';
  if (strpos($line,'cpu')!==0) return -1;
  $p = preg_split('/\s+/', trim($line));
  $idle1  = (int)($p[4] ?? 0);
  $total1 = 0; for ($i=1;$i<count($p);$i++) $total1 += (int)$p[$i];
  usleep((int)($interval*1_000_000));
  $line = @file('/proc/stat')[0] ?? '';
  $p = preg_split('/\s+/', trim($line));
  $idle2  = (int)($p[4] ?? 0);
  $total2 = 0; for ($i=1;$i<count($p);$i++) $total2 += (int)$p[$i];
  $diffTotal = max(1, $total2 - $total1);
  $diffIdle  = max(0, $idle2  - $idle1);
  $usage = (1 - ($diffIdle / $diffTotal)) * 100;
  return max(0, min(100, round($usage, 1)));
}
function metric_mem(): array {
  $mem = @file('/proc/meminfo') ?: [];
  $tot=$avail=0;
  foreach($mem as $l){
    if (str_starts_with($l,'MemTotal:'))     $tot   = (int)filter_var($l,FILTER_SANITIZE_NUMBER_INT)*1024;
    if (str_starts_with($l,'MemAvailable:')) $avail = (int)filter_var($l,FILTER_SANITIZE_NUMBER_INT)*1024;
  }
  if ($tot<=0) return ['total'=>0,'used'=>0,'free'=>0,'percent'=>0];
  $used = max(0, $tot - $avail);
  return ['total'=>$tot,'used'=>$used,'free'=>$avail,'percent'=>round($used/$tot*100,1)];
}
function metric_disk(string $path): array {
  $path = realpath($path) ?: $path;
  $tot = @disk_total_space($path) ?: 0;
  $free = @disk_free_space($path) ?: 0;
  $used = max(0, $tot - $free);
  $pct  = $tot>0 ? round($used/$tot*100,1) : 0;
  return ['total'=>$tot,'used'=>$used,'free'=>$free,'percent'=>$pct,'path'=>$path];
}
function metric_uptime(): string {
  $u = @file_get_contents('/proc/uptime');
  if (!$u) return '';
  $secs = (int)floatval(explode(' ',$u)[0]);
  $d=floor($secs/86400); $h=floor(($secs%86400)/3600); $m=floor(($secs%3600)/60);
  $out=[]; if($d) $out[]="$d d"; if($h) $out[]="$h h"; if($m || (!$d && !$h)) $out[]="$m m";
  return implode(' ', $out);
}

/* === Endpoint GET ?action=metrics (antes de HTML) === */
if (isset($_GET['action']) && $_GET['action']==='metrics') {
  $uploadsBase = defined('UPLOAD_BASE') ? UPLOAD_BASE : (__DIR__.'/uploads');
  $cpu  = metric_cpu_percent(0.2);
  $mem  = metric_mem();
  $dsk  = metric_disk($uploadsBase);
  $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0,0,0];
  json_out([
    'ok'=>true,
    'cpu_pct'=>$cpu,
    'mem'=>['total'=>$mem['total'],'used'=>$mem['used'],'free'=>$mem['free'],'pct'=>$mem['percent']],
    'disk'=>['path'=>$dsk['path'],'total'=>$dsk['total'],'used'=>$dsk['used'],'free'=>$dsk['free'],'pct'=>$dsk['percent']],
    'load'=>['1m'=>round($load[0]??0,2),'5m'=>round($load[1]??0,2),'15m'=>round($load[2]??0,2)],
    'uptime'=>metric_uptime(),
  ]);
}

/* ======================= ACTIONS (POST) ======================= */
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
    $row = $pdo->query("SELECT id,email FROM users WHERE id=$id")->fetch();
    if (!$row) exit('Usuario no existe');

    $isRootTarget = (defined('ROOT_ADMIN_EMAIL') && $row['email'] === ROOT_ADMIN_EMAIL);
    $isSelf       = ($id === $uid);

    $api   = trim($_POST['api_key'] ?? '');
    $adm   = !empty($_POST['is_admin'])  ? 1 : 0;
    $dlx   = !empty($_POST['is_deluxe']) ? 1 : 0;
    $quota = max(0, (int)($_POST['quota_limit'] ?? 50));

    // Reglas: root y el propio admin no pueden quitarse admin
    if ($isRootTarget || $isSelf) $adm = 1;

    $st = $pdo->prepare("UPDATE users SET api_key=?, is_admin=?, is_deluxe=?, quota_limit=? WHERE id=?");
    $st->execute([$api, $adm, $dlx, $quota, $id]);

    exit('OK');
  }

  if ($action === 'user_delete') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $pdo->query("SELECT email FROM users WHERE id=$id")->fetch();
    if (!$row) exit('Usuario no existe');

    if ((defined('ROOT_ADMIN_EMAIL') && $row['email'] === ROOT_ADMIN_EMAIL) || $id === $uid) {
      exit('No puedes eliminar al ROOT ni a ti mismo');
    }

    // borrar archivos del usuario
    $st = $pdo->prepare("SELECT path FROM files WHERE user_id=?");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
      if ($p && is_file($p)) @unlink($p);
      $dir = dirname($p);
      if ($dir && is_dir($dir)) @rmdir($dir);
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

/* ======================= DATA ======================= */
$ipon = setting_get('ip_block_enabled','1') === '1';

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
  // ⚠️ FIX: no reutilizamos el mismo placeholder varias veces.
  $like = '%'.$q.'%';
  $sql = "SELECT id,email,username,first_name,last_name,is_admin,is_deluxe,quota_limit,verified,api_key
          FROM users
          WHERE email      LIKE ?
             OR username   LIKE ?
             OR first_name LIKE ?
             OR last_name  LIKE ?
          ORDER BY id DESC LIMIT 100";
  $st = $pdo->prepare($sql);
  $st->execute([$like,$like,$like,$like]);
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
  .grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  .kpi{background:#0f172a;border:1px solid #273042;border-radius:12px;padding:10px}
  .kpi h4{margin:2px 0 8px;font-size:14px;color:#93c5fd}
  canvas.spark{width:100%;height:120px;background:#0b1222;border-radius:8px}
  .bar{height:16px;background:#0b1222;border-radius:999px;overflow:hidden;border:1px solid #273042}
  .fill{height:100%;background:linear-gradient(90deg,#22d3ee,#0ea5e9)}
  .muted{color:#9fb0c9;font-size:13px}
</style>
</head>
<body><div class="wrap">
  <h2>Panel Admin</h2>

  <!-- MONITOREO EN TIEMPO REAL -->
  <div class="card">
    <h3>Monitoreo del servidor</h3>
    <div class="muted" id="metaUptime">Uptime: — · Carga: —</div>
    <div class="grid3" style="margin-top:10px">
      <div class="kpi">
        <h4>CPU</h4>
        <canvas id="cpuChart" class="spark" width="400" height="120"></canvas>
        <div class="muted">Uso: <b id="cpuTxt">--%</b></div>
      </div>
      <div class="kpi">
        <h4>Memoria</h4>
        <canvas id="memChart" class="spark" width="400" height="120"></canvas>
        <div class="muted">Uso: <b id="memTxt">--%</b> · <span id="memDetail"></span></div>
      </div>
      <div class="kpi">
        <h4>Disco (uploads)</h4>
        <div class="bar"><div id="diskFill" class="fill" style="width:0%"></div></div>
        <div class="muted" style="margin-top:6px">Uso: <b id="diskTxt">--%</b> · <span id="diskDetail"></span></div>
      </div>
    </div>
  </div>

  <div class="card">
    <b>Bloqueo por IP:</b> <span id="ipstate"><?=$ipon?'ON':'OFF'?></span>
    <button class="btn" onclick="tgl()">Alternar</button>
    <script>
      async function tgl(){
        const r=await fetch('admin.php',{method:'POST',body:new URLSearchParams({action:'toggle_ip'})});
        alert(await r.text()); location.reload();
      }
    </script>
  </div>

  <div class="card">
    <h3>Pagos (PayPal)</h3>
    <p>Configura tus credenciales, datos de facturación y prueba la conexión con PayPal.</p>
    <a class="btn" href="admin_payments.php">Abrir configuración</a>
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
          <?php
            $isRoot = (defined('ROOT_ADMIN_EMAIL') && $u['email']===ROOT_ADMIN_EMAIL);
            $isSelf = ($u['id']===$uid);
          ?>
          <tr>
            <td><?=$u['id']?></td>
            <td><?=htmlspecialchars($u['username'])?></td>
            <td><?=htmlspecialchars(trim(($u['first_name']??'').' '.($u['last_name']??'')))?></td>
            <td><?=htmlspecialchars($u['email'])?> <?=$isSelf?'<span class="muted">(tú)</span>':''?></td>
            <td><?=$u['verified']?'✔️':'—'?></td>
            <td>
              <input type="checkbox" id="ad<?=$u['id']?>" <?=$u['is_admin']?'checked':''?>
                     <?=($isRoot||$isSelf)?'disabled':''?>>
            </td>
            <td><input type="checkbox" id="dx<?=$u['id']?>" <?=$u['is_deluxe']?'checked':''?>></td>
            <td><input class="input" style="width:90px" type="number" id="qt<?=$u['id']?>" value="<?=$u['quota_limit']?>"></td>
            <td style="min-width:240px;display:flex;gap:6px;align-items:center">
              <input class="input" id="api<?=$u['id']?>" value="<?=htmlspecialchars($u['api_key'])?>">
              <button class="btn" type="button" onclick="regen(<?=$u['id']?>)">🔁</button>
            </td>
            <td>
              <button class="btn" onclick="upd(<?=$u['id']?>)">Guardar</button>
              <?php if(!$isRoot && !$isSelf): ?>
                <button class="btn" onclick="delu(<?=$u['id']?>)" style="background:#f87171;margin-left:6px">Eliminar</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <script>
      function rndKey(len=40){
        const chars='abcdef0123456789'; let o='';
        for(let i=0;i<len;i++) o+=chars[Math.floor(Math.random()*chars.length)];
        return o;
      }
      function regen(id){
        const inp=document.getElementById('api'+id);
        inp.value=rndKey(40);
      }
      async function upd(id){
        const fd=new FormData();
        fd.append('action','user_update');
        fd.append('id', id);
        fd.append('api_key', document.getElementById('api'+id).value);
        fd.append('is_admin', document.getElementById('ad'+id)?.checked ? '1':'0');
        fd.append('is_deluxe', document.getElementById('dx'+id)?.checked ? '1':'0');
        fd.append('quota_limit', document.getElementById('qt'+id).value);
        const r=await fetch('admin.php',{method:'POST',body:fd});
        alert(await r.text()); location.reload();
      }
      async function delu(id){
        if(!confirm('¿Eliminar usuario y sus archivos?')) return;
        const fd=new FormData(); fd.append('action','user_delete'); fd.append('id',id);
        const r=await fetch('admin.php',{method:'POST',body:fd});
        alert(await r.text()); location.reload();
      }
    </script>
  </div>

  <p><a href="profile.php">Volver</a></p>
</div>

<script>
/* ====== Monitor en tiempo real (CPU/RAM/Disco) ====== */
const cpuC = document.getElementById('cpuChart').getContext('2d');
const memC = document.getElementById('memChart').getContext('2d');
const cpuArr = Array(60).fill(0);
const memArr = Array(60).fill(0);

function drawSpark(ctx, arr){
  const w = ctx.canvas.width, h = ctx.canvas.height;
  ctx.clearRect(0,0,w,h);
  ctx.lineWidth = 2; ctx.strokeStyle = '#22d3ee';
  const max = 100, step = w/(arr.length-1);
  ctx.beginPath();
  ctx.moveTo(0, h - (arr[0]/max)*h);
  for(let i=1;i<arr.length;i++){
    const x = i*step, y = h - (arr[i]/max)*h;
    ctx.lineTo(x,y);
  }
  ctx.stroke();
}
function push(arr, v){ arr.push(v); while(arr.length>60) arr.shift(); }

async function poll(){
  try{
    const r = await fetch('admin.php?action=metrics', {cache:'no-store'});
    const j = await r.json();
    // CPU
    push(cpuArr, Number(j.cpu_pct||0));
    drawSpark(cpuC, cpuArr);
    document.getElementById('cpuTxt').textContent = (j.cpu_pct??0)+'%';
    // MEM
    const mp = Number(j.mem?.pct||0);
    push(memArr, mp);
    drawSpark(memC, memArr);
    document.getElementById('memTxt').textContent = mp+'%';
    document.getElementById('memDetail').textContent =
      (fmtBytes(j.mem?.used||0))+' / '+(fmtBytes(j.mem?.total||0));
    // DISK
    const dp = Number(j.disk?.pct||0);
    document.getElementById('diskFill').style.width = dp+'%';
    document.getElementById('diskTxt').textContent = dp+'%';
    document.getElementById('diskDetail').textContent =
      (fmtBytes(j.disk?.used||0))+' / '+(fmtBytes(j.disk?.total||0));
    // Uptime / load
    document.getElementById('metaUptime').textContent =
      `Uptime: ${j.uptime||'—'} · Carga: ${j.load?.['1m']||0}, ${j.load?.['5m']||0}, ${j.load?.['15m']||0}`;
  }catch(e){ /* silencio */ }
  setTimeout(poll, 2000);
}
function fmtBytes(b){
  const u=['B','KB','MB','GB','TB']; let i=0; while(b>=1024 && i<u.length-1){ b/=1024; i++; }
  return (b>=10?Math.round(b):Math.round(b*10)/10)+' '+u[i];
}
poll();
</script>
</body>
</html>
