<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/mail.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];

/* ========= Polyfills por compat ========= */
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    return $needle === '' || strpos($haystack, $needle) === 0;
  }
}

/* ========= Admin actual ========= */
try {
  $meSt = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
  $meSt->execute([$uid]);
  $me  = $meSt->fetch();
} catch(Throwable $e){ $me = null; }
if (!$me || (int)$me['is_admin'] !== 1) { http_response_code(403); exit('403'); }

/* ========= Helpers ========= */
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

/* ========= (Opcional) comprobación silenciosa de índices ========= */
function try_ensure_index(PDO $pdo, string $table, string $index, string $definition): void {
  try{
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME   = ?
                          AND INDEX_NAME   = ?");
    $q->execute([$table,$index]);
    if ((int)$q->fetchColumn() === 0) {
      $pdo->exec("CREATE INDEX `$index` ON `$table` ($definition)");
    }
  }catch(Throwable $e){ /* silencioso */ }
}
try_ensure_index($pdo, 'users', 'idx_users_username',  'username');
try_ensure_index($pdo, 'users', 'idx_users_email',     'email');
try_ensure_index($pdo, 'users', 'idx_users_first',     'first_name');
try_ensure_index($pdo, 'users', 'idx_users_last',      'last_name');

/* ========= Métricas ========= */
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

/* === GET ?action=metrics === */
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

/* === GET ?action=user_list (buscador en tiempo real) === */
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

/* ========= ACTIONS (POST) ========= */
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
      $quota = max(0, (int)$_POST['quota_limit'] ?? 50);

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

/* ========= SSR (fallback inicial) ========= */
$ipon = setting_get('ip_block_enabled','1') === '1';
try{
  $st = $pdo->query("SELECT id,email,username,first_name,last_name,is_admin,is_deluxe,quota_limit,verified,api_key
                     FROM users ORDER BY id DESC LIMIT 50");
  $users = $st->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){ $users = []; }
$smtp  = smtp_get();
?>


