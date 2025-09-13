<?php
require __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Inicia sesión']); exit; }
$q = trim($_GET['q'] ?? '');
$user_id = intval($_SESSION['uid']);
$st = $pdo->prepare("SELECT id,name,url,size,created_at FROM files WHERE user_id=? AND name LIKE ? ORDER BY id DESC LIMIT 100");
$st->execute([$user_id, '%'.$q.'%']);
echo json_encode(['ok'=>true,'items'=>$st->fetchAll()]);