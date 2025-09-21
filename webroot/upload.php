<?php
// upload.php — acepta sesión O API key y siempre responde JSON
require_once __DIR__.'/db.php';
require_once __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Api-Key, X-APIKEY');

function jsonOut(array $p, int $status = 200){
  http_response_code($status);
  echo json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonOut(['ok'=>false,'error'=>'Método no permitido'],405); }

/* --- BASE_URL fallback --- */
if (!defined('BASE_URL')) {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  define('BASE_URL', $scheme.'://'.$host);
}

/* ========= 1) Autenticación: API key (si viene) o sesión ========= */
$uid = null;
$me  = null;

// A) API key por header o campo
$api = $_SERVER['HTTP_X_API_KEY']
    ?? ($_SERVER['HTTP_X_APIKEY'] ?? null)
    ?? ($_POST['api_key'] ?? '');
$api = trim((string)$api);

if ($api !== '') {
  $st = $pdo->prepare("SELECT id, verified, is_deluxe, quota_limit FROM users WHERE api_key=? LIMIT 1");
  $st->execute([$api]);
  $u = $st->fetch();
  if (!$u)                       jsonOut(['ok'=>false,'error'=>'API key inválida'], 401);
  if ((int)$u['verified'] !== 1) jsonOut(['ok'=>false,'error'=>'Cuenta no verificada'], 403);
  $uid = (int)$u['id'];
  $me  = $u;
}

// B) Si no hay API key, usamos sesión
if ($uid === null) {
  if (empty($_SESSION['uid'])) jsonOut(['ok'=>false,'error'=>'No autenticado'], 401);
  $uid = (int)$_SESSION['uid'];
  $st = $pdo->prepare("SELECT is_deluxe, verified, email, quota_limit FROM users WHERE id=?");
  $st->execute([$uid]);
  $me = $st->fetch();
  if (!$me)                       jsonOut(['ok'=>false,'error'=>'Usuario no encontrado'], 404);
  if ((int)$me['verified'] !== 1) jsonOut(['ok'=>false,'error'=>'Cuenta no verificada'], 403);
}

/* ========= 2) Validación de entrada ========= */
if (!function_exists('clean_label')) {
  function clean_label(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return function_exists('mb_substr') ? mb_substr($s, 0, 120) : substr($s, 0, 120);
  }
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) jsonOut(['ok'=>false,'error'=>'Archivo faltante']);
$err = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
switch ($err) {
  case UPLOAD_ERR_OK: break;
  case UPLOAD_ERR_INI_SIZE:
  case UPLOAD_ERR_FORM_SIZE:
    jsonOut(['ok'=>false,'error'=>'El archivo supera el límite de PHP ('.ini_get('upload_max_filesize').').']);
  case UPLOAD_ERR_PARTIAL: jsonOut(['ok'=>false,'error'=>'Subida incompleta. Intenta de nuevo.']);
  case UPLOAD_ERR_NO_FILE: jsonOut(['ok'=>false,'error'=>'No se envió ningún archivo.']);
  default: jsonOut(['ok'=>false,'error'=>'Error de PHP al subir (código '.$err.').']);
}
if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
  jsonOut(['ok'=>false,'error'=>'Archivo no válido']);
}

$origFilename = $_FILES['file']['name'] ?? '';
$name = clean_label($_POST['name'] ?? '');
// Si no mandan "name", lo inferimos del nombre original (sin extensión)
if ($name === '') {
  $base = pathinfo($origFilename, PATHINFO_FILENAME);
  if ($base === '' || $base === null) $base = 'archivo_'.date('Ymd_His');
  $name = clean_label($base);
}

/* ========= 3) Límites ========= */
if (!defined('SIZE_LIMIT_FREE_MB'))   define('SIZE_LIMIT_FREE_MB',   5);
if (!defined('SIZE_LIMIT_DELUXE_MB')) define('SIZE_LIMIT_DELUXE_MB', 200);
$maxMB = ((int)$me['is_deluxe'] === 1) ? SIZE_LIMIT_DELUXE_MB : SIZE_LIMIT_FREE_MB;

$sizeBytes = (int)($_FILES['file']['size'] ?? 0);
if ($sizeBytes <= 0)                   jsonOut(['ok'=>false,'error'=>'Archivo vacío o inválido']);
if ($sizeBytes > $maxMB*1024*1024)     jsonOut(['ok'=>false,'error'=>"Tu archivo excede el límite de {$maxMB}MB"]);

/* cuota por cantidad */
$st = $pdo->prepare("SELECT COUNT(*) FROM files WHERE user_id=?");
$st->execute([$uid]);
$usados = (int)$st->fetchColumn();
$limite = (int)$me['quota_limit'];
if ($usados >= $limite) jsonOut(['ok'=>false,'error'=>'Sin espacio disponible.']);

/* ========= 4) Destino en disco ========= */
if (!defined('UPLOAD_BASE')) define('UPLOAD_BASE', __DIR__.'/uploads');
$dir = rtrim(UPLOAD_BASE,'/')."/u$uid";
if (!is_dir($dir)) @mkdir($dir, 0775, true);
if (!is_dir($dir) || !is_writable($dir)) {
  jsonOut(['ok'=>false,'error'=>'No se pudo escribir en uploads. Revisa permisos (chown www-data:www-data y chmod 775).'], 500);
}

/* ========= 4.1) Determinar extensión (robusto) ========= */
// 1) Lo que venga del nombre original…
$ext = strtolower(pathinfo($origFilename, PATHINFO_EXTENSION));
$ext = preg_replace('/[^a-z0-9_\-]/i', '', $ext);

// 2) Si NO hay extensión, inferir desde el MIME del tmp_name (antes de mover)
$tmp = $_FILES['file']['tmp_name'] ?? '';
$finfo = @finfo_open(FILEINFO_MIME_TYPE);
$mimeTmp = ($finfo && $tmp) ? (finfo_file($finfo, $tmp) ?: '') : '';
if ($finfo) @finfo_close($finfo);

if ($ext === '' && $mimeTmp) {
  $map = [
    'image/jpeg'=>'jpg', 'image/jpg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/gif'=>'gif',
    'video/mp4'=>'mp4', 'video/webm'=>'webm', 'video/quicktime'=>'mov',
    'audio/mpeg'=>'mp3', 'audio/aac'=>'aac', 'audio/ogg'=>'ogg', 'audio/wav'=>'wav',
    'application/pdf'=>'pdf', 'application/zip'=>'zip', 'application/x-zip-compressed'=>'zip',
    'application/vnd.android.package-archive'=>'apk',
  ];
  if (!empty($map[$mimeTmp])) $ext = $map[$mimeTmp];
}
// 3) Si aún no hay, último recurso
$ext = $ext !== '' ? ('.'.$ext) : '';

/* nombre físico único */
$tries = 8;
do {
  $fname = bin2hex(random_bytes(8)).$ext;
  $path  = $dir.'/'.$fname;
  $url   = rtrim(BASE_URL,'/').'/uploads/u'.$uid.'/'.$fname;
} while (file_exists($path) && --$tries > 0);

if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
  jsonOut(['ok'=>false,'error'=>'No se pudo guardar el archivo en disco'], 500);
}
@chmod($path, 0644);

/* ========= 5) Metadatos ========= */
$finfo = @finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? (finfo_file($finfo, $path) ?: 'application/octet-stream') : 'application/octet-stream';
if ($finfo) @finfo_close($finfo);
$origName = $origFilename !== '' ? $origFilename : $fname;

/* aviso no bloqueante */
$browserFriendly = [
  'image/jpeg','image/png','image/webp','image/gif',
  'audio/mpeg','audio/aac','audio/ogg',
  'video/mp4','video/webm'
];
$warn = in_array($mime, $browserFriendly, true) ? null : 'Formato no estándar para navegador (se subió igual).';

/* ========= 6) Insert en BD (mime/orig_name si existen) ========= */
try {
  // detecta columnas opcionales
  $q = $pdo->prepare(
    "SELECT COUNT(DISTINCT COLUMN_NAME)
       FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'files'
        AND COLUMN_NAME IN ('mime','orig_name')"
  );
  $q->execute();
  $haveBoth = ((int)$q->fetchColumn() === 2);

  if ($haveBoth) {
    $sql = "INSERT INTO files(user_id,name,url,path,size_bytes,mime,orig_name)
            VALUES(?,?,?,?,?,?,?)";
    $params = [$uid,$name,$url,$path,$sizeBytes,$mime,$origName];
  } else {
    $sql = "INSERT INTO files(user_id,name,url,path,size_bytes)
            VALUES(?,?,?,?,?)";
    $params = [$uid,$name,$url,$path,$sizeBytes];
  }

  // Ejecuta el insert
  $pdo->prepare($sql)->execute($params);

  // 🔥 actualizar contador de usados
  $pdo->prepare("UPDATE users SET used_files = used_files + 1 WHERE id=?")
      ->execute([$uid]);

} catch (Throwable $e) {
  @unlink($path);
  $msg = $e->getMessage();
  if (stripos($msg,'Unknown column') !== false) {
    $hint = "Esquema desactualizado en `files`. Añade columnas con:\n".
            "ALTER TABLE files ADD COLUMN mime VARCHAR(100) NULL, ADD COLUMN orig_name VARCHAR(191) NULL;";
    jsonOut(['ok'=>false,'error'=>'Error al guardar en BD','hint'=>$hint], 500);
  }
  jsonOut(['ok'=>false,'error'=>'Error al guardar en la base de datos'], 500);
}

/* ========= 7) OK ========= */
$out = ['ok'=>true, 'file'=>['name'=>$name,'url'=>$url,'mime'=>$mime,'orig'=>$origName]];
if ($warn) $out['warn'] = $warn;
jsonOut($out);
