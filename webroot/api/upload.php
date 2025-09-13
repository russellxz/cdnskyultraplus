<?php
require __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $p, int $status = 200){
  http_response_code($status);
  echo json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

// 🔑 API key
$api = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['api_key'] ?? '');
if (!$api) jsonOut(['ok'=>false,'error'=>'Falta API key'], 401);

$st = $pdo->prepare("SELECT id, quota_limit FROM users WHERE api_key=? AND verified=1");
$st->execute([$api]);
$u = $st->fetch();
if (!$u) jsonOut(['ok'=>false,'error'=>'API key inválida'], 401);

$uid = (int)$u['id'];

/* Nombre */
$name = $_POST['name'] ?? '';
if ($name === '') jsonOut(['ok'=>false,'error'=>'Debes poner un nombre al archivo']);

/* Archivo */
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
  jsonOut(['ok'=>false,'error'=>'Archivo faltante']);
}

$sizeBytes = (int)$_FILES['file']['size'];
if ($sizeBytes <= 0) jsonOut(['ok'=>false,'error'=>'Archivo vacío o inválido']);

/* Carpeta */
$dir = dirname(__DIR__).'/uploads/u'.$uid;
if (!is_dir($dir)) mkdir($dir, 0775, true);

$orig = $_FILES['file']['name'] ?? '';
$ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
$fname = bin2hex(random_bytes(8)).($ext ? '.'.$ext : '');
$path  = $dir.'/'.$fname;
$url   = BASE_URL.'/uploads/u'.$uid.'/'.$fname;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
  jsonOut(['ok'=>false,'error'=>'No se pudo guardar el archivo'], 500);
}
@chmod($path, 0644);

$finfo = @finfo_open(FILEINFO_MIME_TYPE);
$mime  = $finfo ? (finfo_file($finfo, $path) ?: 'application/octet-stream') : 'application/octet-stream';
if ($finfo) @finfo_close($finfo);
$origName = $orig !== '' ? $orig : $fname;

/* Insert con fallback */
try {
  $sql = "INSERT INTO files(user_id,name,url,path,size_bytes,mime,orig_name) VALUES(?,?,?,?,?,?,?)";
  $pdo->prepare($sql)->execute([$uid, $name, $url, $path, $sizeBytes, $mime, $origName]);
} catch (Throwable $e) {
  try {
    $pdo->prepare("INSERT INTO files(user_id,name,url,path,size_bytes) VALUES(?,?,?,?,?)")
        ->execute([$uid, $name, $url, $path, $sizeBytes]);
  } catch (Throwable $e2) {
    @unlink($path);
    jsonOut(['ok'=>false,'error'=>'Error en BD'], 500);
  }
}

/* OK */
jsonOut(['ok'=>true,'file'=>['name'=>$name,'url'=>$url,'mime'=>$mime,'orig'=>$origName]]);