<?php
// paypal_capture.php
declare(strict_types=1);

require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['uid'])) {
  http_response_code(401);
  echo json_encode(['error'=>'auth']);
  exit;
}
$uid = (int)$_SESSION['uid'];

// ---------- Entrada ----------
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true) ?: $_POST;
$orderID  = trim((string)($in['orderID'] ?? $in['orderId'] ?? ''));
$planHint = strtoupper(preg_replace('/[^A-Z0-9_]/','', (string)($in['plan'] ?? '')));

if ($orderID === '') {
  http_response_code(400);
  echo json_encode(['error'=>'order_required']);
  exit;
}

// ---------- Catálogo y helpers ----------
$cat = plans_catalog(); // Debe usar claves en MAYÚSCULAS: PLUS50, PLUS120, PLUS250
$amount2plan = [];
foreach ($cat as $code => $p) {
  $amount2plan[number_format((float)$p['usd'], 2, '.', '')] = $code;
}

function pick_plan_from_order(array $ord, array $amount2plan): array {
  $plan = '';
  $currency = '';
  $amount = '';
  if (!empty($ord['purchase_units'][0])) {
    $pu = $ord['purchase_units'][0];

    // custom_id esperado: "UID:PLAN"
    if (!empty($pu['custom_id'])) {
      $parts = explode(':', (string)$pu['custom_id'], 2);
      if (isset($parts[1])) $plan = strtoupper(preg_replace('/[^A-Z0-9_]/','', $parts[1]));
    }

    // Monto (según respuesta puede estar en dos sitios)
    if (!empty($pu['amount']['value'])) {
      $amount   = (string)$pu['amount']['value'];
      $currency = (string)($pu['amount']['currency_code'] ?? '');
    } elseif (!empty($pu['payments']['captures'][0]['amount']['value'])) {
      $amount   = (string)$pu['payments']['captures'][0]['amount']['value'];
      $currency = (string)($pu['payments']['captures'][0]['amount']['currency_code'] ?? '');
    }
  }

  // Fallback: deducir por monto si no hay custom_id
  if ($plan === '' && $amount !== '' && isset($amount2plan[$amount])) {
    $plan = $amount2plan[$amount];
  }
  return [$plan, $amount, $currency];
}

// ---------- 1) Obtener la orden ----------
$http = 0; $err = null;
$ord  = paypal_api('GET', '/v2/checkout/orders/'.$orderID, null, $http, $err);
if ($http === 404) { http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }
if ($http >= 400)  { http_response_code(502); echo json_encode(['error'=>'get_failed','http'=>$http,'debug'=>$err,'res'=>$ord]); exit; }

$status = (string)($ord['status'] ?? '');
list($planFromOrder, $amt, $cur) = pick_plan_from_order($ord, $amount2plan);

// Validar UID si viene en custom_id
if (!empty($ord['purchase_units'][0]['custom_id'])) {
  $parts = explode(':', (string)$ord['purchase_units'][0]['custom_id'], 2);
  $uidCustom = (int)($parts[0] ?? 0);
  if ($uidCustom !== $uid) {
    http_response_code(403);
    echo json_encode(['error'=>'mismatch_user','uid'=>$uid,'custom_uid'=>$uidCustom]);
    exit;
  }
}

// Determinar plan (custom_id > hint > monto)
$plan = $planFromOrder ?: $planHint;
if ($plan === '' || empty($cat[$plan])) {
  http_response_code(400);
  echo json_encode([
    'error'=>'invalid_state_or_plan',
    'status'=>$status, 'plan'=>$plan, 'amount'=>$amt, 'currency'=>$cur, 'res'=>$ord
  ]);
  exit;
}

// ---------- 2) Capturar si aún no está COMPLETED ----------
if ($status !== 'COMPLETED') {
  $cap = paypal_api('POST', '/v2/checkout/orders/'.$orderID.'/capture', [], $http, $err);
  if ($http !== 200 && $http !== 201) {
    http_response_code(502);
    echo json_encode(['error'=>'capture_failed','http'=>$http,'debug'=>$err,'res'=>$cap]);
    exit;
  }
  $ord = $cap; // usar la respuesta de captura en adelante
}

list($planFinal, $amt, $cur) = pick_plan_from_order($ord, $amount2plan);
$plan = $planFinal ?: $plan; // si la captura lo trae mejor

// ---------- Validaciones de importe ----------
$expectedUsd = number_format((float)$cat[$plan]['usd'], 2, '.', '');
if ($amt !== '' && $amt !== $expectedUsd) {
  http_response_code(400);
  echo json_encode(['error'=>'amount_mismatch','got'=>$amt,'expected'=>$expectedUsd,'plan'=>$plan]);
  exit;
}
if ($cur !== '' && strtoupper($cur) !== 'USD') {
  http_response_code(400);
  echo json_encode(['error'=>'currency_mismatch','cur'=>$cur]);
  exit;
}

// ---------- Registrar pago + acreditar archivos ----------
$orderIdOut  = (string)($ord['id'] ?? $orderID);
$payer_email = (string)($ord['payer']['email_address'] ?? '');
payment_upsert($uid, $orderIdOut, $plan, (float)$expectedUsd, 'completed', $ord);

$inc = (int)$cat[$plan]['inc']; // ej. 50 | 120 | 250
$pdo->prepare("UPDATE users SET quota_limit = quota_limit + ? WHERE id=?")->execute([$inc, $uid]);

echo json_encode(['ok'=>true,'inc'=>$inc,'order'=>$orderIdOut]);
