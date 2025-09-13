<?php
require_once __DIR__.'/paypal_client.php';
if (empty($_SESSION['uid'])) { http_response_code(401); exit('{"error":"login"}'); }
header('Content-Type: application/json');

$uid = (int)$_SESSION['uid'];
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true) ?: $_POST;
$orderId = $in['orderID'] ?? '';
if (!$orderId) { http_response_code(400); echo json_encode(['error'=>'orderID']); exit; }

$http=0; $err=null;
$res = paypal_api('POST',"/v2/checkout/orders/$orderId/capture", null, $http, $err);

if (!in_array($http,[200,201],true)) {
  http_response_code(500); echo json_encode(['error'=>'capture_failed','http'=>$http,'debug'=>$err,'res'=>$res]); exit;
}

$status = $res['status'] ?? '';
$pu     = $res['purchase_units'][0] ?? [];
$custom = $pu['custom_id'] ?? '';
$amount = $pu['payments']['captures'][0]['amount']['value'] ?? '0.00';
$payer  = $res['payer']['email_address'] ?? null;

list($uidFromOrder,$plan) = strpos($custom,':')!==false ? explode(':',$custom,2) : [null,null];
$uidFromOrder = (int)$uidFromOrder;
$cat = plans_catalog();

if ($status==='COMPLETED' && $uidFromOrder === $uid && isset($cat[$plan])) {
  // 1) Registrar/actualizar pago
  payment_upsert($uid, $orderId, $plan, (float)$amount, 'completed', $res);

  // 2) Obtener id del payment
  $st=$pdo->prepare("SELECT id FROM payments WHERE order_id=?");
  $st->execute([$orderId]); $payment_id=(int)$st->fetchColumn();

  // 3) Sumar cuota
  $inc = (int)$cat[$plan]['inc'];
  $pdo->prepare("UPDATE users SET quota_limit=quota_limit+? WHERE id=?")->execute([$inc, $uid]);

  // 4) Crear factura 'paid'
  $inv_id = invoice_create($uid, $payment_id, 'Compra '.$cat[$plan]['name'], (float)$amount, 'USD', 'paid', [
    'order_id'=>$orderId, 'payer_email'=>$payer, 'plan'=>$plan
  ]);

  // 5) Devolver nuevo estado
  $st=$pdo->prepare("SELECT quota_limit FROM users WHERE id=?"); $st->execute([$uid]);
  echo json_encode(['ok'=>true,'quota_limit'=>(int)$st->fetchColumn(),'inc'=>$inc,'invoice_id'=>$inv_id]);
  exit;
}

http_response_code(400);
echo json_encode(['error'=>'invalid_state_or_plan','status'=>$status,'custom'=>$custom,'res'=>$res]);
