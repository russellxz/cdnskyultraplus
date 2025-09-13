<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';

header('Content-Type: application/json; charset=utf-8');

// ——— endurecer: convertir warnings a excepciones y responder 200 siempre
set_error_handler(function($no,$str,$file,$line){ throw new Error("$str @$file:$line"); });
set_exception_handler(function($e){
  pp_log(['create_php_exception'=>get_class($e),'msg'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>'php_exception','msg'=>$e->getMessage()]);
});

if (empty($_SESSION['uid'])) { http_response_code(200); echo json_encode(['ok'=>false,'error'=>'auth']); exit; }
$uid = (int)$_SESSION['uid'];

$in   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$plan = strtoupper(preg_replace('/[^A-Z0-9_]/','', $in['plan'] ?? ''));
$cat  = plans_catalog();

if (!$plan || !isset($cat[$plan])) {
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>'bad_plan','plans'=>array_keys($cat)]);
  exit;
}

$amount = number_format((float)$cat[$plan]['usd'], 2, '.', '');
$body = [
  'intent' => 'CAPTURE',
  'purchase_units' => [[
    'amount'      => ['currency_code'=>'USD','value'=>$amount],
    'description' => 'SkyUltraPlus '.$cat[$plan]['name'],
    'custom_id'   => $uid.':'.$plan,
  ]]],
  'application_context' => [
    'brand_name'   => setting_get('invoice_business','SkyUltraPlus'),
    'shipping_preference' => 'NO_SHIPPING',
    'user_action'  => 'PAY_NOW',
    'return_url'   => 'https://'.($_SERVER['HTTP_HOST'] ?? '').'/profile.php',
    'cancel_url'   => 'https://'.($_SERVER['HTTP_HOST'] ?? '').'/profile.php',
  ],
];

$http=0; $err=null;
$res = paypal_api('POST','/v2/checkout/orders',$body,$http,$err);

if ($http===201 && !empty($res['id'])) {
  echo json_encode(['ok'=>true,'id'=>$res['id']]); exit;
}

pp_log(['create_fail'=>true,'http'=>$http,'err'=>$err,'res'=>$res]);
http_response_code(200);
echo json_encode(['ok'=>false,'error'=>'create_failed','http'=>$http,'debug'=>$err,'res'=>$res]);
