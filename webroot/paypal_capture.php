<?php
// paypal_capture.php
require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'method']); exit; }
if (empty($_SESSION['uid'])) { http_response_code(401); echo json_encode(['error'=>'auth']); exit; }
$uid = (int)$_SESSION['uid'];

$raw = file_get_contents('php://input');
$inp = json_decode($raw, true);
$orderId = trim($inp['orderID'] ?? '');
if ($orderId === '') { http_response_code(400); echo json_encode(['error'=>'missing_order_id']); exit; }

$cats = plans_catalog();

/* 1) Leemos la orden para extraer custom_id y validar plan/usuario */
$http=0; $err=null;
$ord = paypal_api('GET', '/v2/checkout/orders/'.$orderId, null, $http, $err);
if ($http !== 200 || !$ord) { http_response_code(500); echo json_encode(['error'=>'get_failed','detail'=>$err,'http'=>$http]); exit; }

$pu = $ord['purchase_units'][0] ?? [];
$custom = $pu['custom_id'] ?? '';
if (strpos($custom, ':') === false) { http_response_code(400); echo json_encode(['error'=>'custom_id_missing']); exit; }
list($uidFromOrder, $plan) = explode(':', $custom, 2);
$uidFromOrder = (int)$uidFromOrder; $plan = strtoupper(trim($plan));

if ($uidFromOrder !== $uid) { http_response_code(403); echo json_encode(['error'=>'user_mismatch']); exit; }
if (!isset($cats[$plan])) { http_response_code(400); echo json_encode(['error'=>'plan_invalid']); exit; }

/* 2) Capturamos. IMPORTANTE: cuerpo JSON vacío '{}' (o sin cuerpo).
      Algunos entornos fallan con [] u otros JSON.  */
$cap = paypal_api('POST', '/v2/checkout/orders/'.$orderId.'/capture', '{}', $http, $err);
if ($http >= 400 || !$cap) { http_response_code(502); echo json_encode(['error'=>'capture_http','http'=>$http,'detail'=>$err]); exit; }

/* 3) Revisamos estado de captura */
$capStatus = $cap['status'] ?? '';
$captures  = $cap['purchase_units'][0]['payments']['captures'] ?? [];
$firstCap  = $captures[0] ?? [];
$txnStatus = $firstCap['status'] ?? '';
$amt       = $firstCap['amount']['value'] ?? null;
$cur       = $firstCap['amount']['currency_code'] ?? 'USD';
$payerMail = $cap['payer']['email_address'] ?? null;

if ($capStatus !== 'COMPLETED' && $txnStatus !== 'COMPLETED') {
  http_response_code(400);
  echo json_encode(['error'=>'not_completed','status'=>$capStatus,'txn'=>$txnStatus]);
  exit;
}

/* 4) Validación de importe */
$expected = number_format($cats[$plan]['usd'], 2, '.', '');
if ($amt !== $expected || $cur !== 'USD') {
  http_response_code(400);
  echo json_encode(['error'=>'amount_mismatch','got'=>$amt.$cur,'expected'=>$expected.'USD']);
  exit;
}

/* 5) Idempotencia: si ya registramos este order_id como COMPLETED, no repetimos */
$st = $pdo->prepare("SELECT id FROM payments WHERE provider='paypal' AND order_id=? AND status='completed'");
$st->execute([$orderId]);
if ($st->fetchColumn()) {
  echo json_encode(['ok'=>1,'inc'=>0,'info'=>'duplicate']); exit;
}

/* 6) Guardamos pago y sumamos cupo */
$pdo->beginTransaction();
try{
  // payments (ajusta nombres de columnas si difieren)
  $ins = $pdo->prepare("INSERT INTO payments(provider, order_id, user_id, plan_code, amount_usd, currency, status, payer_email, raw_json)
                        VALUES('paypal',?,?,?,?,?,'completed',?,?)");
  $ins->execute([$orderId, $uid, $plan, $expected, 'USD', $payerMail, json_encode($cap)]);

  // sumar cupo
  $inc = (int)$cats[$plan]['inc'];
  $pdo->prepare("UPDATE users SET quota_limit = quota_limit + ? WHERE id=?")->execute([$inc, $uid]);

  $pdo->commit();
} catch(Exception $e){
  $pdo->rollBack();
  paypal_log('DB error on capture '.$orderId.': '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['error'=>'db_error']);
  exit;
}

echo json_encode(['ok'=>1,'inc'=>$cats[$plan]['inc']]);
