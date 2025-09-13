<?php
// paypal_create_order.php
declare(strict_types=1);

require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';

header('Content-Type: application/json; charset=utf-8');

// auth
if (empty($_SESSION['uid'])) { http_response_code(401); echo json_encode(['error'=>'auth']); exit; }
$uid = (int)$_SESSION['uid'];

// input
$raw  = file_get_contents('php://input');
$in   = json_decode($raw, true) ?: $_POST;
$plan = strtoupper(preg_replace('/[^A-Z0-9_]/','', (string)($in['plan'] ?? '')));

// catálogo
$cat = plans_catalog();
if (!isset($cat[$plan])) {
  http_response_code(400);
  echo json_encode(['error'=>'plan_invalid','plans'=>array_keys($cat)]);
  exit;
}

// credenciales / modo
$cfg = paypal_cfg();
if ($cfg['cid']==='' || $cfg['secret']==='') {
  http_response_code(500);
  echo json_encode(['error'=>'no_credentials','hint'=>'Configura Client ID y Secret en Admin → Pagos']);
  exit;
}

$amount = number_format((float)$cat[$plan]['usd'], 2, '.', '');
$body = [
  'intent' => 'CAPTURE',
  'purchase_units' => [[
    'amount'       => ['currency_code'=>'USD','value'=>$amount],
    'description'  => 'SkyUltraPlus '.$cat[$plan]['name'],
    'custom_id'    => $uid.':'.$plan,
  ]],   // 👈 OJO: aquí van DOS corchetes de cierre, no tres
  'application_context' => [
    'brand_name'          => $cfg['brand'] ?: 'SkyUltraPlus',
    'shipping_preference' => 'NO_SHIPPING',
    'user_action'         => 'PAY_NOW',
    'return_url'          => 'https://'.($_SERVER['HTTP_HOST'] ?? '').'/profile.php',
    'cancel_url'          => 'https://'.($_SERVER['HTTP_HOST'] ?? '').'/profile.php',
  ],
];

$http = 0; $err = null;
$res  = paypal_api('POST','/v2/checkout/orders', $body, $http, $err);

if ($http === 201 && !empty($res['id'])) {
  echo json_encode(['id'=>$res['id']]);
  exit;
}

http_response_code(500);
echo json_encode([
  'error' => 'create_failed',
  'http'  => $http,
  'debug' => $err,
  'res'   => $res,
  'mode'  => $cfg['mode']
]);
