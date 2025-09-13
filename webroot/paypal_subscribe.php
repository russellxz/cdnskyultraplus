<?php
// paypal_subscribe.php
require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';
header('Content-Type: application/json');

if (empty($_SESSION['uid'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'auth']); exit; }
$uid = (int)$_SESSION['uid'];

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$sid = trim($in['subscriptionID'] ?? '');
if ($sid === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing subscriptionID']); exit; }

// obtiene token y base
$err = null;
$cfg = paypal_cfg();
$tok = paypal_get_token($err);
if (!$tok) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'token: '.$err]); exit; }

// consulta detalles de la suscripción
$url = rtrim($cfg['base'],'/').'/v1/billing/subscriptions/'.rawurlencode($sid);
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer '.$tok,
    'Content-Type: application/json',
    'Accept: application/json'
  ],
]);
$res  = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http < 200 || $http >= 300) {
  http_response_code(502);
  echo json_encode(['ok'=>false,'error'=>'paypal http '.$http, 'raw'=>$res]);
  exit;
}

$sub = json_decode($res, true) ?: [];
$status = strtoupper($sub['status'] ?? '');
if (!in_array($status, ['ACTIVE','APPROVAL_PENDING'])) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'status '.$status]);
  exit;
}

// Asegurar columna pp_sub_id (SQLite soporta IF NOT EXISTS en versiones recientes; por si acaso, atrapamos error)
try { $pdo->exec("ALTER TABLE users ADD COLUMN pp_sub_id TEXT"); } catch (Throwable $e) {}

$st = $pdo->prepare("UPDATE users SET is_deluxe=1, pp_sub_id=? WHERE id=?");
$st->execute([$sid, $uid]);

echo json_encode(['ok'=>true, 'status'=>$status]);
