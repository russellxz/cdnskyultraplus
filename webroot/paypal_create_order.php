<?php
require_once __DIR__.'/db.php';

if (empty($_SESSION['uid'])) { http_response_code(401); exit; }
$uid = (int)$_SESSION['uid'];

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$plan  = strtoupper(trim($input['plan'] ?? ''));

$CATALOG = [
  'PLUS50'  => ['amount'=>'1.37', 'desc'=>'+50 archivos'],
  'PLUS120' => ['amount'=>'2.45', 'desc'=>'+120 archivos'],
  'PLUS250' => ['amount'=>'3.55', 'desc'=>'+250 archivos'],
  'DELUXE'  => ['amount'=>'5.00', 'desc'=>'Plan Deluxe (pago único)'],
];

if (!isset($CATALOG[$plan])) {
  http_response_code(400);
  echo json_encode(['error'=>'plan_invalid']); exit;
}

$brand  = setting_get('invoice_business','SkyUltraPlus');
$mode   = setting_get('paypal_mode','sandbox');
$cid    = setting_get('paypal_client_id','');
$csec   = setting_get('paypal_client_secret','');
if (!$cid || !$csec) { http_response_code(500); echo json_encode(['error'=>'paypal_not_configured']); exit; }

$base = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

function pp_log($msg,$obj=null){
  if (!is_string($msg)) $msg = json_encode($msg);
  $line = '['.date('Y-m-d H:i:s')."] $msg";
  if ($obj!==null) $line .= ' '.json_encode($obj);
  @file_put_contents(__DIR__.'/paypal.log', $line."\n", FILE_APPEND);
}

# token
$ch = curl_init("$base/v1/oauth2/token");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
  CURLOPT_USERPWD=>$cid.':'.$csec,
  CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>'grant_type=client_credentials'
]);
$tokRes = curl_exec($ch);
$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$tok = $tokRes ? json_decode($tokRes,true) : null;
if ($code!==200 || empty($tok['access_token'])) {
  pp_log('API TOKEN ERR', ['code'=>$code,'res'=>$tokRes]);
  http_response_code(500); echo json_encode(['error'=>'token_failed']); exit;
}

$amount = $CATALOG[$plan]['amount'];
$desc   = $CATALOG[$plan]['desc'];
$custom = $uid.':'.$plan;

$host = $_SERVER['HTTP_HOST'] ?? '';
$returnUrl = ($host?'https://'.$host:'').'/profile.php';
$cancelUrl = $returnUrl;

$payload = [
  'intent'=>'CAPTURE',
  'purchase_units'=>[[
    'amount'=>['currency_code'=>'USD','value'=>$amount],
    'description'=>"SkyUltraPlus $desc",
    'custom_id'=>$custom
  ]],
  'application_context'=>[
    'brand_name'=>$brand,
    'shipping_preference'=>'NO_SHIPPING',
    'user_action'=>'PAY_NOW',
    'return_url'=>$returnUrl,
    'cancel_url'=>$cancelUrl
  ]
];

$ch = curl_init("$base/v2/checkout/orders");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_HTTPHEADER=>[
    'Content-Type: application/json',
    'Authorization: Bearer '.$tok['access_token']
  ],
  CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>json_encode($payload)
]);
$res = curl_exec($ch);
$code= curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

pp_log('API POST /v2/checkout/orders '.$code, ['req'=>$payload, 'res'=>$res]);

if ($code!==201) { http_response_code(500); echo json_encode(['error'=>'create_failed','http'=>$code]); exit; }

$j = json_decode($res,true);
echo json_encode(['id'=>$j['id'] ?? null]);
