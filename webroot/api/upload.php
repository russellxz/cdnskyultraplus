<?php
require __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');

$api = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['api_key'] ?? $_GET['api_key'] ?? '');
if (!$api) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Falta API key']); exit; }

$st = $pdo->prepare("SELECT id, quota_limit FROM users WHERE api_key=? AND verified=1");
$st->execute([$api]);
$u = $st->fetch();
if (!$u) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'API key inválida']); exit; }

$user_id = intval($u['id']);

// Cuota
$cq = $pdo->prepare("SELECT COUNT(*) c FROM files WHERE user_id=?");
$cq->execute([$user_id]);
$used = intval($cq->fetch()['c']);
if ($used >= intval($u['quota_limit'])) {
  echo json_encode(['ok'=>false,'error'=>"Cuota alcanzada. Precio por ampliar: $".number_format(PRICE_USD,2)." USD"]); exit;
}

// Validaciones
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
  echo json_encode(['ok'=>false,'error'=>'Archivo faltante']); exit;
}
$name = clean_label($_POST['name'] ?? '');
if ($name === '') { echo json_encode(['ok'=>false,'error'=>'Debes incluir "name"']); exit; }

$allowed = [
  'image/jpeg','image/png','image/webp','image/gif',
  'audio/mpeg','audio/ogg','audio/opus','audio/mp4','audio/x-m4a','audio/wav',
  'video/mp4','video/webm',
  'application/pdf'
];
$mime = mime_content_type($_FILES['file']['tmp_name']) ?: $_FILES['file']['type'];
$size = filesize($_FILES['file']['tmp_name']);
$ext  = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if (!in_array($mime, $allowed)) { echo json_encode(['ok'=>false,'error'=>"Tipo no permitido ($mime)"]); exit; }

$dir = dirname(__DIR__).'/uploads/'.$user_id;
if (!is_dir($dir)) mkdir($dir, 0755, true);

$fname = time().'_'.bin2hex(random_bytes(4)).($ext?'.'.$ext:'');
$dest  = $dir.'/'.$fname;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) { echo json_encode(['ok'=>false,'error'=>'No se pudo guardar']); exit; }

$url = BASE_URL.'/uploads/'.$user_id.'/'.$fname;
$pdo->prepare("INSERT INTO files(user_id,name,filename,mime,size,url) VALUES(?,?,?,?,?,?)")
    ->execute([$user_id,$name,$fname,$mime,$size,$url]);

echo json_encode(['ok'=>true,'url'=>$url,'name'=>$name,'size'=>$size]);