<?php
require __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');

$api = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
if (!$api) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'Falta API key']);
  exit;
}

$st = $pdo->prepare("SELECT id FROM users WHERE api_key=? AND verified=1");
$st->execute([$api]);
$u = $st->fetch();

if ($u) {
  echo json_encode(['ok'=>true,'message'=>'✅ API key válida']);
} else {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'❌ API key inválida']);
}
