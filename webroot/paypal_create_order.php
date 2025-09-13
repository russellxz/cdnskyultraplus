<?php
// paypal_create_order.php
require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'method']); exit; }
if (empty($_SESSION['uid'])) { http_response_code(401); echo json_encode(['error'=>'auth']); exit; }

$uid  = (int)$_SESSION['uid'];
$raw  = file_get_contents('php://input');
$inp  = json_decode($raw, true);
$plan = strtoupper(trim($inp['plan'] ?? ''));

$cats = plans_catalog();
if (!isset($cats[$plan])) { http_response_code(400); echo json_encode(['error'=>'plan_invalid']); exit; }

$amount = number_format($cats[$plan]['usd'], 2, '.', '');
$brand  = setting_get('invoice_business', 'SkyUltraPlus');

$host = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://').$host;

$body = [
  'intent' => 'CAPTURE',
  'purchase_units' => [[
    'amount' => ['currency_code'=>'USD', 'value'=>$amount],
    'description' => $brand.' '.$cats[$plan]['name'],
    'custom_id' => $uid.':'.$plan, // <- nos llevamos usuario+plan para validarlo al capturar
  ]],
  'application_context' => [
    'brand_name'        => $brand,
    'shipping_preference'=> 'NO_SHIPPING',
    'user_action'       => 'PAY_NOW',
    'return_url'        => $baseUrl.'/profile.php', // por si el SDK lo necesita
    'cancel_url'        => $baseUrl.'/profile.php',
  ],
];

$err = null; $http = 0;
$j = paypal_api('POST','/v2/checkout/orders', $body, $http, $err);
if ($http !== 201 || !$j || empty($j['id'])) {
  http_response_code(500);
  echo json_encode(['error'=>'create_failed','detail'=>$err,'http'=>$http]);
  exit;
}

echo json_encode(['id'=>$j['id']]);
