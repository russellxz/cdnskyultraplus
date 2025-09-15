<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/mail.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];

if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strpos($haystack, $needle) === 0;
  }
}

try {
  $meSt = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
  $meSt->execute([$uid]);
  $me  = $meSt->fetch();
} catch(Throwable $e){ $me = null; }
if (!$me || (int)$me['is_admin'] !== 1) { http_response_code(403); exit('403'); }

function json_out(array $a, int $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }
function bytes_fmt(int|float $b): string {
  $u=['B','KB','MB','GB','TB']; $i=0; while($b>=1024 && $i<count($u)-1){ $b/=1024; $i++; }
  return number_format($b, $b>=10?0:1).' '.$u[$i];
}

function try_ensure_index(PDO $pdo, string $table, string $index, string $definition): void {
  try{
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $q->execute([$table,$index]);
    if ((int)$q->fetchColumn() === 0) {
      $pdo->exec("CREATE INDEX `$index` ON `$table` ($definition)");
    }
  }catch(Throwable $e){}
}
try_ensure_index($pdo, 'users', 'idx_users_username',  'username');
try_ensure_index($pdo, 'users', 'idx_users_email',     'email');
try_ensure_index($pdo, 'users', 'idx_users_first',     'first_name');
try_ensure_index($pdo, 'users', 'idx_users_last',      'last_name');

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

if (isset($_GET['action']) && $_GET['action']==='user_list') {
  header('Cache-Control: no-store');
  $qRaw = (string)($_GET['q'] ?? '');
  $q = trim($qRaw);
  try {
    if ($q !== '' && ctype_digit($q)) {
      $st = $pdo->prepare(
        "SELECT id,email,username,first_name,last_name,is_admin,is_deluxe,quota_limit,verified,api_key
         FROM users WHERE id=? LIMIT 1"
      );
      $st->execute([(int)$q]);
      json_out(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($q !== '') {
      $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
      $sql = "SELECT id,email,username,first_name,last_name,is_admin,is_deluxe,quota_limit,verified,api_key
              FROM users
              WHERE INSTR(LOWER(email), ?) > 0
                 OR INSTR(LOWER(username), ?) > 0
                 OR INSTR(LOWER(first_name), ?) > 0
                 OR INSTR(LOWER(last_name), ?) > 0
                 OR INSTR(LOWER(CONCAT_WS(' ', first_name, last_name)), ?) > 0
              ORDER BY id DESC
              LIMIT 200";
      $st = $pdo->prepare($sql);
      $st->execute([$needle, $needle, $needle, $needle, $needle]);
    } else {
      $st = $pdo->query(
        "SELECT id,email,username,first_name,last_name,is_admin,is_deluxe,quota_limit,verified,api_key
         FROM users ORDER BY id DESC LIMIT 50"
      );
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok'=>true,'items'=>$rows]);
  } catch (Throwable $e) {
    error_log('ADMIN user_list error: '.$e->getMessage());
    json_out(['ok'=>false,'error'=>'Error en la búsqueda'], 500);
  }
}

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
    try{
      $idSt = $pdo->prepare("SELECT id,email FROM users WHERE id=? LIMIT 1");
      $id   = (int)($_POST['id'] ?? 0);
      $idSt->execute([$id]);
      $row  = $idSt->fetch();
      if (!$row) exit('Usuario no existe');
      $isRootTarget = (defined('ROOT_ADMIN_EMAIL') && $row['email'] === ROOT_ADMIN_EMAIL);
      $isSelf       = ($id === $uid);
      $api   = trim($_POST['api_key'] ?? '');
      $adm   = !empty($_POST['is_admin'])  ? 1 : 0;
      $dlx   = !empty($_POST['is_deluxe']) ? 1 : 0;
      $quota = max(0, (int)($_POST['quota_limit'] ?? 50));
      if ($isRootTarget || $isSelf) $adm = 1;
      $st = $pdo->prepare("UPDATE users SET api_key=?, is_admin=?, is_deluxe=?, quota_limit=? WHERE id=?");
      $st->execute([$api, $adm, $dlx, $quota, $id]);
      exit('OK');
    }catch(Throwable $e){
      exit('Error al guardar');
    }
  }
  if ($action === 'user_delete') {
    try{
      $id = (int)($_POST['id'] ?? 0);
      $row = $pdo->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
      $row->execute([$id]);
      $row = $row->fetch();
      if (!$row) exit('Usuario no existe');
      if ((defined('ROOT_ADMIN_EMAIL') && $row['email'] === ROOT_ADMIN_EMAIL) || $id === $uid) {
        exit('No puedes eliminar al ROOT ni a ti mismo');
      }
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
    }catch(Throwable $e){
      exit('Error al eliminar');
    }
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

$ipon = setting_get('ip_block_enabled','1') === '1';
try{
  $st = $pdo->query("SELECT id,email,username,first_name,last_name,is_admin,is_deluxe,quota_limit,verified,api_key FROM users ORDER BY id DESC LIMIT 50");
  $users = $st->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $users = []; }
$smtp  = smtp_get();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel Admin — CDN</title>
<style>
  :root{--bg:#0b0b0d;--card:#111827;--muted:#9fb0c9;--line:#273042;--line2:#334155;--txt:#eaf2ff;--brand1:#0ea5e9;--brand2:#22d3ee;--danger:#ef4444;--danger2:#b91c1c;--ok:#22c55e;--warn:#f59e0b;--paypal1:#0070ba;--paypal2:#003087;--stripe1:#635bff;--stripe2:#0a2540}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto}
  .wrap{max-width:1160px;margin:0 auto;padding:20px}
  h2{margin:0 0 12px 0;font-weight:800;letter-spacing:.2px}
  .section-title{display:flex;align-items:center;gap:10px;margin:0 0 8px 0}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
  .card{background:var(--card);border:1px solid var(--line2);border-radius:14px;padding:18px;margin-bottom:14px}
  .muted{color:var(--muted);font-size:13px}
  .badge{display:inline-block;padding:2px 8px;border:1px solid var(--line2);border-radius:999px;background:#0f172a;font-size:12px;margin-left:6px}
  .input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line2);background:#0f172a;color:var(--txt);outline:none}
  .input:focus{border-color:var(--brand1);box-shadow:0 0 0 2px #0ea5e966}
  .input-wrap{position:relative;display:flex;align-items:center}
  .icon-btn{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:1px solid var(--line);background:#0b1222;color:var(--txt);padding:6px 8px;border-radius:8px;cursor:pointer}
  .btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(90deg,var(--brand1),var(--brand2));color:#051425;border:none;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer;text-decoration:none;transition:transform .05s ease,opacity .2s}
  .btn:hover{transform:translateY(-1px)}
  .btn.secondary{background:#0f172a;border:1px solid var(--line2);color:var(--txt)}
  .btn.ghost{background:transparent;border:1px dashed var(--line2);color:var(--txt)}
  .btn.danger{background:linear-gradient(90deg,var(--danger),var(--danger2));color:#fff}
  .btn.paypal{background:linear-gradient(90deg,var(--paypal1),var(--paypal2));color:#fff}
  .btn.stripe{background:linear-gradient(90deg,var(--stripe1),var(--stripe2));color:#fff}
  .btn.block{display:flex;justify-content:center;width:100%}
  .btn.small{padding:6px 10px;font-weight:700;border-radius:9px}
  .spacer{height:10px}
  .kpi{background:#0f172a;border:1px solid var(--line);border-radius:12px;padding:10px}
  .kpi h4{margin:2px 0 8px;font-size:14px;color:#93c5fd}
  canvas.spark{width:100%;height:120px;background:#0b1222;border-radius:8px}
  .bar{height:16px;background:#0b1222;border-radius:999px;overflow:hidden;border:1px solid var(--line)}
  .fill{height:100%;background:linear-gradient(90deg,var(--brand2),var(--brand1))}
  table{width:100%;border-collapse:collapse;min-width:920px}
  th{font-size:12px;color:#a9bbd6;text-align:left}
  td,th{border-bottom:1px solid var(--line);padding:8px;vertical-align:middle}
  .actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
  .actions .btn.danger{margin-left:10px}
  .pill{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;border:1px solid var(--line2);background:#0f172a}
  .brand-card{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .brand-card .txt{max-width:70%}
  .brand-card .big{font-size:18px;font-weight:800;margin:0}
  .brand-card .note{font-size:13px;color:var(--muted);margin:4px 0 0 0}
  .brand-card .cta{min-width:180px}
  .ip-toggle{display:flex;align-items:center;gap:10px}
  @media (max-width:880px){
    .grid-3{grid-template-columns:1fr}
    .grid-2{grid-template-columns:1fr}
    .brand-card{flex-direction:column;align-items:flex-start}
    .brand-card .txt{max-width:100%}
    .actions{gap:10px}
  }
</style>
</head>
<body>
<div class="wrap">
  <h2>Panel Admin</h2>

  <div class="card">
    <div class="section-title"><h3 style="margin:0">Monitoreo del servidor</h3><span class="badge" id="metaUptime">Uptime — · Carga —</span></div>
    <div class="grid-3" style="margin-top:10px">
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

  <div class="card ip-toggle">
    <div><b>Bloqueo por IP:</b> <span id="ipstate"><?= $ipon ? 'ON' : 'OFF' ?></span></div>
    <button class="btn small" onclick="tgl()">Alternar</button>
  </div>

  <div class="grid-2">
    <div class="card brand-card" style="background:linear-gradient(180deg,#0f172a,#0f172a);border-color:#1f2a44">
      <div class="txt">
        <p class="big">PayPal</p>
        <p class="note">Configura credenciales, datos de facturación y realiza pruebas de conexión.</p>
      </div>
      <div class="cta">
        <a class="btn paypal block" href="admin_payments.php">Configurar PayPal</a>
      </div>
    </div>
    <div class="card brand-card" style="background:linear-gradient(180deg,#0f172a,#0f172a);border-color:#1f2a44">
      <div class="txt">
        <p class="big">Stripe</p>
        <p class="note">Administra llaves y webhooks con un acceso más limpio y visible.</p>
      </div>
      <div class="cta">
        <a class="btn stripe block" href="admin_stripe.php">Configurar Stripe</a>
      </div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0">SMTP</h3>
    <form onsubmit="saveSMTP(event)">
      <div class="grid-2" style="gap:10px">
        <div>
          <div class="muted" style="margin-bottom:4px">Host</div>
          <input class="input" name="host" value="<?=h($smtp['host'])?>" placeholder="smtp.tu-dominio.com">
        </div>
        <div>
          <div class="muted" style="margin-bottom:4px">Puerto</div>
          <input class="input" name="port" value="<?=h($smtp['port'])?>" placeholder="587">
        </div>
        <div>
          <div class="muted" style="margin-bottom:4px">Usuario (oculto)</div>
          <div class="input-wrap">
            <input class="input" id="smtp_user" name="user" type="password" value="<?=h($smtp['user'])?>" placeholder="correo@dominio.com">
            <button type="button" class="icon-btn" onclick="toggleMask('smtp_user')">👁</button>
          </div>
        </div>
        <div>
          <div class="muted" style="margin-bottom:4px">Contraseña (oculta)</div>
          <div class="input-wrap">
            <input class="input" id="smtp_pass" name="pass" type="password" value="<?=h($smtp['pass'])?>" placeholder="••••••">
            <button type="button" class="icon-btn" onclick="toggleMask('smtp_pass')">👁</button>
          </div>
        </div>
        <div>
          <div class="muted" style="margin-bottom:4px">From</div>
          <input class="input" name="from" value="<?=h($smtp['from'])?>" placeholder="correo@dominio.com">
        </div>
        <div>
          <div class="muted" style="margin-bottom:4px">Nombre remitente</div>
          <input class="input" name="name" value="<?=h($smtp['name'])?>" placeholder="SkyUltraPlus">
        </div>
      </div>
      <div class="spacer"></div>
      <button class="btn">Guardar SMTP</button>
      <button class="btn secondary" type="button" onclick="testSMTP()">Probar envío</button>
    </form>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0">Enviar correo</h3>
    <form onsubmit="sendMail(event)">
      <input class="input" name="to" placeholder="Correo destino o * para todos" required>
      <input class="input" name="subject" placeholder="Asunto" style="margin-top:8px" required>
      <textarea class="input" name="message" placeholder="Mensaje HTML" style="margin-top:8px;height:120px" required></textarea>
      <div class="spacer"></div>
      <div class="actions">
        <button class="btn">Enviar</button>
        <span class="muted">Usa * para enviar a todos los usuarios</span>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px 0">Usuarios</h3>
    <form id="searchForm" method="get" onsubmit="return false">
      <input id="qAdmin" class="input" name="q" placeholder="Buscar por nombre, usuario o correo (tiempo real)">
    </form>
    <div style="overflow:auto;margin-top:8px">
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Usuario</th><th>Nombre</th><th>Correo</th>
            <th>Verif</th><th>Admin</th><th>Deluxe</th><th>Quota</th><th>API Key</th><th>Acciones</th>
          </tr>
        </thead>
        <tbody id="usersTbody">
          <?php foreach($users as $u): ?>
            <?php $isRoot = (defined('ROOT_ADMIN_EMAIL') && $u['email']===ROOT_ADMIN_EMAIL); $isSelf = ($u['id']===$uid); ?>
            <tr>
              <td><?=$u['id']?></td>
              <td><?=h($u['username'])?></td>
              <td><?=h(trim(($u['first_name']??'').' '.($u['last_name']??'')))?></td>
              <td><?=h($u['email'])?> <?=$isSelf?'<span class="badge">tú</span>':''?></td>
              <td><?=$u['verified']?'✔️':'—'?></td>
              <td><input type="checkbox" id="ad<?=$u['id']?>" <?=$u['is_admin']?'checked':''?> <?=($isRoot||$isSelf)?'disabled':''?>></td>
              <td><input type="checkbox" id="dx<?=$u['id']?>" <?=$u['is_deluxe']?'checked':''?>></td>
              <td><input class="input" style="width:90px" type="number" id="qt<?=$u['id']?>" value="<?=$u['quota_limit']?>"></td>
              <td style="min-width:260px;display:flex;gap:8px;align-items:center">
                <input class="input" id="api<?=$u['id']?>" value="<?=h($u['api_key'])?>">
                <button class="btn small" type="button" onclick="regen(<?=$u['id']?>)">🔁</button>
              </td>
              <td class="actions">
                <button class="btn small" type="button" onclick="upd(<?=$u['id']?>)">Guardar</button>
                <?php if(!$isRoot && !$isSelf): ?>
                  <button class="btn small danger" type="button" onclick="delu(<?=$u['id']?>)">Eliminar</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted" id="usersHint" style="margin-top:6px">Se muestran los últimos 50. Escribe para filtrar.</p>
    </div>
  </div>

  <p><a class="btn ghost" href="profile.php">Volver</a></p>
</div>

<script>
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
  function pushVal(arr, v){ arr.push(v); while(arr.length>60) arr.shift(); }
  async function poll(){
    try{
      const r = await fetch('admin.php?action=metrics', {cache:'no-store'});
      const j = await r.json();
      pushVal(cpuArr, Number(j.cpu_pct||0)); drawSpark(cpuC, cpuArr);
      document.getElementById('cpuTxt').textContent = (j.cpu_pct??0)+'%';
      const mp = Number(j.mem?.pct||0); pushVal(memArr, mp); drawSpark(memC, memArr);
      document.getElementById('memTxt').textContent = mp+'%';
      document.getElementById('memDetail').textContent = fmtBytes(j.mem?.used||0)+' / '+fmtBytes(j.mem?.total||0);
      const dp = Number(j.disk?.pct||0);
      document.getElementById('diskFill').style.width = dp+'%';
      document.getElementById('diskTxt').textContent = dp+'%';
      document.getElementById('diskDetail').textContent = fmtBytes(j.disk?.used||0)+' / '+fmtBytes(j.disk?.total||0);
      document.getElementById('metaUptime').textContent = 'Uptime '+(j.uptime||'—')+' · Carga '+(j.load?.['1m']||0)+', '+(j.load?.['5m']||0)+', '+(j.load?.['15m']||0);
    }catch(e){}
    setTimeout(poll, 2000);
  }
  function fmtBytes(b){
    const u=['B','KB','MB','GB','TB']; let i=0; while(b>=1024 && i<u.length-1){ b/=1024; i++; }
    return (b>=10?Math.round(b):Math.round(b*10)/10)+' '+u[i];
  }
  poll();

  async function tgl(){
    const r=await fetch('admin.php',{method:'POST',body:new URLSearchParams({action:'toggle_ip'})});
    alert(await r.text()); location.reload();
  }
  function esc(s){return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
  function rndKey(len=40){ const chars='abcdef0123456789'; let o=''; for(let i=0;i<len;i++) o+=chars[Math.floor(Math.random()*chars.length)]; return o; }
  function toggleMask(id){ const el=document.getElementById(id); if(!el) return; el.type = el.type==='password' ? 'text' : 'password'; }

  function regen(id){ const inp=document.getElementById('api'+id); if(inp) inp.value=rndKey(40); }
  async function upd(id){
    const fd=new FormData();
    fd.append('action','user_update');
    fd.append('id', id);
    fd.append('api_key', document.getElementById('api'+id)?.value||'');
    fd.append('is_admin', document.getElementById('ad'+id)?.checked ? '1':'0');
    fd.append('is_deluxe', document.getElementById('dx'+id)?.checked ? '1':'0');
    fd.append('quota_limit', document.getElementById('qt'+id)?.value||'0');
    const r=await fetch('admin.php',{method:'POST',body:fd});
    alert(await r.text());
    runSearch(false);
  }
  async function delu(id){
    if(!confirm('¿Eliminar usuario y sus archivos?')) return;
    const fd=new FormData(); fd.append('action','user_delete'); fd.append('id',id);
    const r=await fetch('admin.php',{method:'POST',body:fd});
    alert(await r.text());
    runSearch(false);
  }

  const qAdmin = document.getElementById('qAdmin');
  const tbody  = document.getElementById('usersTbody');
  const hint   = document.getElementById('usersHint');
  let t=null, ctl=null;

  function rowHtml(u, selfId, rootEmail){
    const isSelf = (String(u.id)===String(selfId));
    const isRoot = (rootEmail && u.email===rootEmail);
    return `
      <tr>
        <td>${u.id}</td>
        <td>${esc(u.username||'')}</td>
        <td>${esc(((u.first_name||'')+' '+(u.last_name||'')).trim())}</td>
        <td>${esc(u.email||'')}${isSelf? ' <span class="badge">tú</span>' : ''}</td>
        <td>${u.verified ? '✔️' : '—'}</td>
        <td><input type="checkbox" id="ad${u.id}" ${u.is_admin?'checked':''} ${(isRoot||isSelf)?'disabled':''}></td>
        <td><input type="checkbox" id="dx${u.id}" ${u.is_deluxe?'checked':''}></td>
        <td><input class="input" style="width:90px" type="number" id="qt${u.id}" value="${u.quota_limit}"></td>
        <td style="min-width:260px;display:flex;gap:8px;align-items:center">
          <input class="input" id="api${u.id}" value="${esc(u.api_key||'')}">
          <button class="btn small" type="button" onclick="regen(${u.id})">🔁</button>
        </td>
        <td class="actions">
          <button class="btn small" type="button" onclick="upd(${u.id})">Guardar</button>
          ${(!isRoot && !isSelf) ? `<button class="btn small danger" type="button" onclick="delu(${u.id})">Eliminar</button>`:''}
        </td>
      </tr>
    `;
  }

  async function runSearch(showLoading=true){
    const q = (qAdmin?.value||'').trim();
    if (ctl) ctl.abort(); ctl = new AbortController();
    try{
      if (showLoading && hint) hint.textContent='Buscando…';
      const r = await fetch(`admin.php?action=user_list&q=${encodeURIComponent(q)}`, {
        signal: ctl.signal,
        cache:'no-store',
        headers:{'Accept':'application/json'}
      });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error||'Error en búsqueda');
      const items = j.items||[];
      const rootEmail = <?= defined('ROOT_ADMIN_EMAIL') ? json_encode(ROOT_ADMIN_EMAIL) : 'null' ?>;
      tbody.innerHTML = items.map(u=>rowHtml(u, <?= (int)$uid ?>, rootEmail)).join('');
      if (hint) hint.textContent = q ? `Resultados: ${items.length}` : 'Se muestran los últimos 50. Escribe para filtrar.';
    }catch(e){
      if (e.name !== 'AbortError') { if (hint) hint.textContent='Error al buscar.'; }
    }
  }
  function deb(){ clearTimeout(t); t=setTimeout(()=>runSearch(true), 250); }
  qAdmin?.addEventListener('input', deb);
  runSearch(false);

  async function saveSMTP(e){
    e.preventDefault();
    const fd=new FormData(e.target); fd.append('action','set_smtp');
    const r=await fetch('admin.php',{method:'POST',body:fd}); alert(await r.text());
  }
  async function testSMTP(){
    const to=prompt('Correo destino para prueba:'); if(!to) return;
    const fd=new FormData(); fd.append('action','mail_send'); fd.append('to',to); fd.append('subject','Prueba SMTP'); fd.append('message','<b>Prueba OK</b>');
    const r=await fetch('admin.php',{method:'POST',body:fd}); alert(await r.text());
  }
</script>
</body>
</html>
