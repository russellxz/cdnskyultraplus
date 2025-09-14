<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Api-Key, X-APIKEY');

function jsonOut(array $p, int $status = 200){
  http_response_code($status);
  echo json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

/* ----- CORS preflight ----- */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* ----- Sólo POST real ----- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  jsonOut(['ok'=>false,'error'=>'Método no permitido'], 405);
}

/* ----- Fallback de BASE_URL si no viene en config.php ----- */
if (!defined('BASE_URL')) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  define('BASE_URL', $scheme.'://'.$host);
}

/* ===========================
 * 1) Autenticación por API key
 * =========================== */
$api = $_SERVER['HTTP_X_API_KEY']
    ?? ($_SERVER['HTTP_X_APIKEY'] ?? null)
    ?? ($_POST['api_key'] ?? '');
$api = trim((string)$api);

if ($api === '') {
  jsonOut(['ok'=>false,'error'=>'Falta API key (usa header X-API-Key o campo api_key)'], 401);
}

$st = $pdo->prepare("SELECT id, verified, is_deluxe, quota_limit FROM users WHERE api_key=? LIMIT 1");
$st->execute([$api]);
$u = $st->fetch();
if (!$u)                       jsonOut(['ok'=>false,'error'=>'API key inválida'], 401);
if ((int)$u['verified'] !== 1) jsonOut(['ok'=>false,'error'=>'Cuenta no verificada'], 403);

$uid      = (int)$u['id'];
$isDeluxe = (int)$u['is_deluxe'] === 1;
$quotaMax = (int)$u['quota_limit'];

/* ===========================
 * 2) Validación de entrada
 * =========================== */
if (!function_exists('clean_label')) {
  function clean_label(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return function_exists('mb_substr') ? mb_substr($s, 0, 120) : substr($s, 0, 120);
  }
}
$name = clean_label($_POST['name'] ?? '');
if ($name === '') jsonOut(['ok'=>false,'error'=>'Debes poner un nombre al archivo']);

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  jsonOut(['ok'=>false,'error'=>'Archivo faltante']);
}
$err = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
switch ($err) {
  case UPLOAD_ERR_OK: break;
  case UPLOAD_ERR_INI_SIZE:
  case UPLOAD_ERR_FORM_SIZE:
    jsonOut(['ok'=>false,'error'=>'El archivo supera el límite de PHP ('.ini_get('upload_max_filesize').').']);
  case UPLOAD_ERR_PARTIAL:
    jsonOut(['ok'=>false,'error'=>'Subida incompleta. Intenta de nuevo.']);
  case UPLOAD_ERR_NO_FILE:
    jsonOut(['ok'=>false,'error'=>'No se envió ningún archivo.']);
  default:
    jsonOut(['ok'=>false,'error'=>'Error de PHP al subir (código '.$err.').']);
}
if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
  jsonOut(['ok'=>false,'error'=>'Archivo no válido']);
}

/* ===========================
 * 3) Límites (plan + cuota)
 * =========================== */
if (!defined('SIZE_LIMIT_FREE_MB'))   define('SIZE_LIMIT_FREE_MB',   5);
if (!defined('SIZE_LIMIT_DELUXE_MB')) define('SIZE_LIMIT_DELUXE_MB', 200);

$maxMB     = $isDeluxe ? SIZE_LIMIT_DELUXE_MB : SIZE_LIMIT_FREE_MB;
$sizeBytes = (int)($_FILES['file']['size'] ?? 0);

if ($sizeBytes <= 0) jsonOut(['ok'=>false,'error'=>'Archivo vacío o inválido']);
if ($sizeBytes > $maxMB * 1024 * 1024) {
  jsonOut(['ok'=>false,'error'=>"Tu archivo excede el límite de {$maxMB}MB"]);
}

/* cuota por cantidad */
$c = $pdo->prepare("SELECT COUNT(*) FROM files WHERE user_id=?");
$c->execute([$uid]);
$usados = (int)$c->fetchColumn();
if ($usados >= $quotaMax) {
  jsonOut(['ok'=>false,'error'=>'Sin espacio disponible. Contacta soporte para ampliar tu cuota.']);
}

/* ===========================
 * 4) Destino (disco)
 * =========================== */
$root        = realpath(__DIR__.'/..') ?: dirname(__DIR__);
$uploadsBase = defined('UPLOAD_BASE') ? UPLOAD_BASE : ($root.'/uploads');

$dir = rtrim($uploadsBase,'/')."/u$uid";
if (!is_dir($dir)) @mkdir($dir, 0775, true);
if (!is_dir($dir) || !is_writable($dir)) {
  jsonOut(['ok'=>false,'error'=>'No se puede escribir en uploads. Revisa permisos (chown www-data:www-data y chmod 775).'], 500);
}

$orig = $_FILES['file']['name'] ?? '';
$ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$ext  = preg_replace('/[^a-z0-9_\-]/i', '', $ext);
$ext  = $ext !== '' ? ('.'.$ext) : '';

$tries = 8;
do {
  $fname = bin2hex(random_bytes(8)).$ext;
  $path  = $dir.'/'.$fname;
  $url   = rtrim(BASE_URL,'/').'/uploads/u'.$uid.'/'.$fname;
} while (file_exists($path) && --$tries > 0);

if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
  jsonOut(['ok'=>false,'error'=>'No se pudo guardar el archivo'], 500);
}
@chmod($path, 0644);

/* ===========================
 * 5) Metadatos (MIME / nombre)
 * =========================== */
$finfo = @finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? (finfo_file($finfo, $path) ?: 'application/octet-stream') : 'application/octet-stream';
if ($finfo) @finfo_close($finfo);
$origName = $orig !== '' ? $orig : $fname;

/* Aviso no bloqueante */
$browserFriendly = [
  'image/jpeg','image/png','image/webp','image/gif',
  'audio/mpeg','audio/aac','audio/ogg',
  'video/mp4','video/webm'
];
$warn = in_array($mime, $browserFriendly, true) ? null : 'Formato no estándar para navegador (se subió igual).';

/* ===========================
 * 6) Insert en MariaDB (detectando columnas)
 * =========================== */
try {
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
    $params = [$uid, $name, $url, $path, $sizeBytes, $mime, $origName];
  } else {
    $sql = "INSERT INTO files(user_id,name,url,path,size_bytes)
            VALUES(?,?,?,?,?)";
    $params = [$uid, $name, $url, $path, $sizeBytes];
  }
  $pdo->prepare($sql)->execute($params);

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

/* ===========================
 * 7) OK
 * =========================== */
$out = ['ok'=>true,'file'=>['name'=>$name,'url'=>$url,'mime'=>$mime,'orig'=>$origName]];
if ($warn) $out['warn'] = $warn;
jsonOut($out);
