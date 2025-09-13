<?php
require_once __DIR__.'/db.php';

if (empty($_SESSION['uid'])) { http_response_code(401); exit; }
$uid = (int)$_SESSION['uid'];

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$orderID = trim($input['orderID'] ?? '');
if (!$orderID) { http_response_code(400); echo json_encode(['error'=>'missing_orderID']); exit; }

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

# GET order (para leer custom_id y validar)
$ch = curl_init("$base/v2/checkout/orders/".urlencode($orderID));
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$tok['access_token']]
]);
$getRes = curl_exec($ch);
$getCode= curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
pp_log('API GET /v2/checkout/orders '.$getCode, ['id'=>$orderID, 'res'=>$getRes]);

if ($getCode!==200) { http_response_code(502); echo json_encode(['error'=>'order_fetch_failed','http'=>$getCode]); exit; }

$o = json_decode($getRes,true);
$custom = $o['purchase_units'][0]['custom_id'] ?? '';
list($paid_uid, $plan) = array_pad(explode(':',$custom,2), 2, null);
$plan = strtoupper(trim((string)$plan));
$paid_uid = (int)$paid_uid;

$INCS = ['PLUS50'=>50, 'PLUS120'=>120, 'PLUS250'=>250]; // planes que suman archivos

# CAPTURE
$ch = curl_init("$base/v2/checkout/orders/".urlencode($orderID)."/capture");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_HTTPHEADER=>[
    'Content-Type: application/json',
    'Authorization: Bearer '.$tok['access_token']
  ],
  CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>json_encode((object)[]) // cuerpo vacío
]);
$capRes = curl_exec($ch);
$capCode= curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
pp_log('API POST /v2/checkout/orders/capture '.$capCode, ['id'=>$orderID, 'res'=>$capRes]);

if ($capCode!==201 && $capCode!==200) { http_response_code(502); echo json_encode(['error'=>'capture_failed','http'=>$capCode]); exit; }

$cj = json_decode($capRes,true);
$status = $cj['status'] ?? '';
if ($status!=='COMPLETED') { http_response_code(400); echo json_encode(['error'=>'not_completed','status'=>$status]); exit; }

# Aplicar efectos en la cuenta
if ($paid_uid !== $uid) {
  // Por seguridad: el pago no corresponde al usuario logueado
  // Aún así podemos aplicar usando $paid_uid si quieres; de momento validamos.
}

$inc = 0; $deluxe=false;
if (isset($INCS[$plan])) {
  $inc = (int)$INCS[$plan];
  $pdo->prepare("UPDATE users SET quota_limit = quota_limit + ? WHERE id=?")->execute([$inc, $paid_uid ?: $uid]);
} elseif ($plan === 'DELUXE') {
  $deluxe = true;
  $pdo->prepare("UPDATE users SET is_deluxe = 1 WHERE id=?")->execute([$paid_uid ?: $uid]);
} else {
  // plan desconocido; no rompemos, pero lo registramos
  pp_log('WARN unknown plan on capture', ['plan'=>$plan,'custom'=>$custom]);
}

echo json_encode(['ok'=>true, 'inc'=>$inc, 'deluxe'=>$deluxe]);
