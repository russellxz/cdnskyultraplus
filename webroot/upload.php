<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $p, int $status = 200){
  http_response_code($status);
  echo json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

/* === Autenticación: sesión o API key === */
$uid = null;

// 1. Revisa si viene API key
$api = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
if ($api) {
  $st = $pdo->prepare("SELECT id,is_deluxe,verified,email,quota_limit FROM users WHERE api_key=? LIMIT 1");
  $st->execute([$api]);
  $me = $st->fetch();
  if (!$me || (int)$me['verified'] !== 1) {
    jsonOut(['ok'=>false,'error'=>'API key inválida o cuenta no verificada'], 401);
  }
  $uid = (int)$me['id'];
} else {
  // 2. Si no hay API key, usa sesión
  if (empty($_SESSION['uid'])) {
    jsonOut(['ok'=>false,'error'=>'No autenticado'], 401);
  }
  $uid = (int)$_SESSION['uid'];
  $u = $pdo->prepare("SELECT is_deluxe,verified,email,quota_limit FROM users WHERE id=?");
  $u->execute([$uid]);
  $me = $u->fetch();
  if (!$me)                        jsonOut(['ok'=>false,'error'=>'Usuario no encontrado'], 404);
  if ((int)$me['verified'] !== 1)  jsonOut(['ok'=>false,'error'=>'Cuenta no verificada'], 403);
}

/* Nombre humano obligatorio */
$name = clean_label($_POST['name'] ?? '');
if ($name === '') jsonOut(['ok'=>false,'error'=>'Debes poner un nombre al archivo']);

/* Archivo presente */
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  jsonOut(['ok'=>false,'error'=>'Archivo faltante']);
}
$err = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
switch ($err) {
  case UPLOAD_ERR_OK: break;
  case UPLOAD_ERR_INI_SIZE:
  case UPLOAD_ERR_FORM_SIZE:
    jsonOut(['ok'=>false,'error'=>'El archivo supera el máximo permitido por PHP ('.ini_get('upload_max_filesize').').']);
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

/* Límite por tipo de cuenta */
if (!defined('SIZE_LIMIT_FREE_MB'))   define('SIZE_LIMIT_FREE_MB',   5);
if (!defined('SIZE_LIMIT_DELUXE_MB')) define('SIZE_LIMIT_DELUXE_MB', 200);
$maxMB = ((int)$me['is_deluxe'] === 1) ? SIZE_LIMIT_DELUXE_MB : SIZE_LIMIT_FREE_MB;

$sizeBytes = (int)$_FILES['file']['size'];
if ($sizeBytes <= 0) {
  jsonOut(['ok'=>false,'error'=>'Archivo vacío o inválido']);
}
if ($sizeBytes > $maxMB * 1024 * 1024) {
  jsonOut(['ok'=>false,'error'=>"Tu archivo excede el límite de {$maxMB}MB"]);
}

/* Cuota */
$cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM files WHERE user_id=?");
$cnt->execute([$uid]);
$usados = (int)$cnt->fetch()['c'];
$limite = (int)$me['quota_limit'];
if ($usados >= $limite) {
  jsonOut(['ok'=>false,'error'=>'Sin espacio disponible. Compra más en WhatsApp.']);
}

/* Carpeta por usuario */
if (!defined('UPLOAD_BASE')) define('UPLOAD_BASE', __DIR__.'/uploads');
$dir = UPLOAD_BASE . "/u$uid";
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
  jsonOut(['ok'=>false,'error'=>'No se pudo preparar el directorio de usuario'], 500);
}

/* Nombre físico único */
$orig = $_FILES['file']['name'] ?? '';
$ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$ext  = preg_replace('/[^a-z0-9_\-]/i', '', $ext);
$ext  = $ext !== '' ? ('.'.$ext) : '';

$tries = 5;
do {
  $fname = bin2hex(random_bytes(8)).$ext;
  $path  = $dir.'/'.$fname;
  $url   = BASE_URL.'/uploads/u'.$uid.'/'.$fname;
} while (file_exists($path) && --$tries > 0);

/* Guardar en disco */
if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
  jsonOut(['ok'=>false,'error'=>'No se pudo guardar el archivo'], 500);
}
@chmod($path, 0644);

/* MIME y BD */
$finfo = @finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? (finfo_file($finfo, $path) ?: 'application/octet-stream') : 'application/octet-stream';
if ($finfo) @finfo_close($finfo);
$origName = $orig !== '' ? $orig : $fname;

try {
  $sql = "INSERT INTO files(user_id,name,url,path,size_bytes,mime,orig_name) VALUES(?,?,?,?,?,?,?)";
  $pdo->prepare($sql)->execute([$uid, $name, $url, $path, $sizeBytes, $mime, $origName]);
} catch (Throwable $e) {
  try {
    $pdo->prepare("INSERT INTO files(user_id,name,url,path,size_bytes) VALUES(?,?,?,?,?)")
        ->execute([$uid, $name, $url, $path, $sizeBytes]);
  } catch (Throwable $e2) {
    @unlink($path);
    jsonOut(['ok'=>false,'error'=>'Error al guardar en la base de datos'], 500);
  }
}

/* OK */
$out = ['ok'=>true,'file'=>['name'=>$name,'url'=>$url,'mime'=>$mime,'orig'=>$origName]];
jsonOut($out);
