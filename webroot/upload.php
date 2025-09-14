<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/config.php';
header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $p, int $status = 200){
  http_response_code($status);
  echo json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

/* --- BASE_URL fallback por si no está definida en config.php --- */
if (!defined('BASE_URL')) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  define('BASE_URL', $scheme.'://'.$host);
}

/* --- Sesión / usuario --- */
if (empty($_SESSION['uid'])) jsonOut(['ok'=>false,'error'=>'No autenticado'], 401);
$uid = (int)$_SESSION['uid'];

$st = $pdo->prepare("SELECT is_deluxe, verified, email, quota_limit FROM users WHERE id=?");
$st->execute([$uid]);
$me = $st->fetch();
if (!$me)                       jsonOut(['ok'=>false,'error'=>'Usuario no encontrado'], 404);
if ((int)$me['verified'] !== 1) jsonOut(['ok'=>false,'error'=>'Cuenta no verificada'], 403);

/* --- Nombre “humano” --- */
if (!function_exists('clean_label')) {
  function clean_label(string $s): string {
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return function_exists('mb_substr') ? mb_substr($s, 0, 120) : substr($s, 0, 120);
  }
}
$name = clean_label($_POST['name'] ?? '');
if ($name === '') jsonOut(['ok'=>false,'error'=>'Debes poner un nombre al archivo']);

/* --- Archivo presente --- */
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

/* --- Límite por plan --- */
if (!defined('SIZE_LIMIT_FREE_MB'))   define('SIZE_LIMIT_FREE_MB',   5);
if (!defined('SIZE_LIMIT_DELUXE_MB')) define('SIZE_LIMIT_DELUXE_MB', 200);
$maxMB = ((int)$me['is_deluxe'] === 1) ? SIZE_LIMIT_DELUXE_MB : SIZE_LIMIT_FREE_MB;

$sizeBytes = (int)($_FILES['file']['size'] ?? 0);
if ($sizeBytes <= 0)                           jsonOut(['ok'=>false,'error'=>'Archivo vacío o inválido']);
if ($sizeBytes > $maxMB * 1024 * 1024)         jsonOut(['ok'=>false,'error'=>"Tu archivo excede el límite de {$maxMB}MB"]);

/* --- Cuota por cantidad --- */
$st = $pdo->prepare("SELECT COUNT(*) FROM files WHERE user_id=?");
$st->execute([$uid]);
$usados = (int)$st->fetchColumn();
$limite = (int)$me['quota_limit'];
if ($usados >= $limite)                        jsonOut(['ok'=>false,'error'=>'Sin espacio disponible.']);

/* --- Directorio destino --- */
if (!defined('UPLOAD_BASE')) define('UPLOAD_BASE', __DIR__.'/uploads');
$dir = rtrim(UPLOAD_BASE,'/')."/u$uid";
if (!is_dir($dir)) @mkdir($dir, 0775, true);
if (!is_dir($dir) || !is_writable($dir)) {
  jsonOut(['ok'=>false,'error'=>'No se pudo escribir en uploads. Revisa permisos (chown www-data:www-data y chmod 775).'], 500);
}

/* --- Nombre físico único --- */
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
  jsonOut(['ok'=>false,'error'=>'No se pudo guardar el archivo en disco'], 500);
}
@chmod($path, 0644);

/* --- MIME + nombre original --- */
$finfo = @finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? (finfo_file($finfo, $path) ?: 'application/octet-stream') : 'application/octet-stream';
if ($finfo) @finfo_close($finfo);
$origName = $orig !== '' ? $orig : $fname;

/* --- Aviso no bloqueante para formatos poco “web-friendly” --- */
$browserFriendly = [
  'image/jpeg','image/png','image/webp','image/gif',
  'audio/mpeg','audio/aac','audio/ogg',
  'video/mp4','video/webm'
];
$warn = in_array($mime, $browserFriendly, true) ? null : 'Formato no estándar para navegador (se subió igual).';

/* --- Guardar en BD (detectando columnas extra en MariaDB) --- */
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
    $params = [$uid,$name,$url,$path,$sizeBytes,$mime,$origName];
  } else {
    $sql = "INSERT INTO files(user_id,name,url,path,size_bytes)
            VALUES(?,?,?,?,?)";
    $params = [$uid,$name,$url,$path,$sizeBytes];
  }
  $pdo->prepare($sql)->execute($params);

} catch (Throwable $e) {
  @unlink($path);
  $msg = $e->getMessage();
  if (stripos($msg,'Unknown column') !== false) {
    $hint = "Esquema desactualizado: faltan columnas en `files`. Ejecuta:\n".
            "ALTER TABLE files ADD COLUMN mime VARCHAR(100) NULL, ADD COLUMN orig_name VARCHAR(191) NULL;";
    jsonOut(['ok'=>false,'error'=>'Error al guardar en BD.','hint'=>$hint], 500);
  }
  jsonOut(['ok'=>false,'error'=>'Error al guardar en la base de datos'], 500);
}

/* --- OK --- */
$out = ['ok'=>true,'file'=>['name'=>$name,'url'=>$url,'mime'=>$mime,'orig'=>$origName]];
if ($warn) $out['warn'] = $warn;
jsonOut($out);
