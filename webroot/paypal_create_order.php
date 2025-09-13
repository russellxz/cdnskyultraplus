<?php
// paypal_create_order.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paypal_client.php'; // define paypal_api() y plans_catalog()

header('Content-Type: application/json; charset=utf-8');

// Solo usuarios logueados
if (empty($_SESSION['uid'])) {
  http_response_code(401);
  echo json_encode(['error' => 'auth']);
  exit;
}

// Acepta JSON o x-www-form-urlencoded (POST). No uses GET para esto.
$raw  = file_get_contents('php://input');
$in   = json_decode($raw, true) ?: $_POST;
$plan = isset($in['plan']) ? strtoupper(preg_replace('/[^A-Z0-9_]/','', (string)$in['plan'])) : '';

if ($plan === '') {
  http_response_code(400);
  echo json_encode(['error' => 'plan_required']);
  exit;
}

// Catálogo del servidor (no confiar en el cliente)
$cat = plans_catalog(); // debe tener plus50/plus120/plus250, etc.
if (!isset($cat[$plan])) {
  http_response_code(400);
  echo json_encode(['error' => 'plan_invalid']);
  exit;
}

$uid    = (int)$_SESSION['uid'];
$amount = number_format((float)$cat[$plan]['usd'], 2, '.', '');
$brand  = setting_get('invoice_business', 'SkyUltraPlus');
$host   = $_SERVER['HTTP_HOST'] ?? '';

// Construir la orden
$body = [
  'intent' => 'CAPTURE',
  'purchase_units' => [[
    'amount'      => ['currency_code' => 'USD', 'value' => $amount],
    'description' => 'SkyUltraPlus ' . $cat[$plan]['name'],
    // custom_id lo usaremos para validar al capturar (uid y plan)
    'custom_id'   => $uid . ':' . $plan,
  ]]],
  'application_context' => [
    'brand_name'          => $brand,
    'shipping_preference' => 'NO_SHIPPING',
    'user_action'         => 'PAY_NOW',
    'return_url'          => "https://{$host}/profile.php",
    'cancel_url'          => "https://{$host}/profile.php",
  ],
];

// Llamar a PayPal
$http = 0; $err = null;
$res  = paypal_api('POST', '/v2/checkout/orders', $body, $http, $err);

if ($http === 201 && !empty($res['id'])) {
  echo json_encode(['id' => $res['id']]);
  exit;
}

// Error
http_response_code(500);
echo json_encode([
  'error' => 'create_failed',
  'http'  => $http,
  'debug' => $err,
  'res'   => $res,
]);
