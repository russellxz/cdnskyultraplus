<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';

header('Content-Type: application/json; charset=utf-8');

// ——— endurecer
set_error_handler(function($no,$str,$file,$line){ throw new Error("$str @$file:$line"); });
set_exception_handler(function($e){
  pp_log(['capture_php_exception'=>get_class($e),'msg'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>'php_exception','msg'=>$e->getMessage()]);
});

if (empty($_SESSION['uid'])) { echo json_encode(['ok'=>false,'error'=>'auth']); exit; }
$uid = (int)$_SESSION['uid'];

$in      = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$orderID = trim((string)($in['orderID'] ?? ''));
$planCli = strtoupper(preg_replace('/[^A-Z0-9_]/','', $in['plan'] ?? ''));

if ($orderID==='') { echo json_encode(['ok'=>false,'error'=>'order_required']); exit; }

$http=0; $err=null;
$res = paypal_api('POST', "/v2/checkout/orders/{$orderID}/capture", new stdClass(), $http, $err);

if ($http!==201) {
  pp_log(['capture_http_fail'=>$http,'err'=>$err,'res'=>$res]);
  echo json_encode(['ok'=>false,'error'=>'capture_http_'.$http,'debug'=>$err,'res'=>$res]); exit;
}

$status = strtoupper($res['status'] ?? '');
$pu     = $res['purchase_units'][0] ?? [];
$custom = (string)($pu['custom_id'] ?? '');
$plan   = $planCli;
if (!$plan && $custom && strpos($custom, ':')!==false) {
  [$uidFrom, $planFrom] = explode(':', $custom, 2);
  $plan = strtoupper(preg_replace('/[^A-Z0-9_]/','', $planFrom));
}

if ($status!=='COMPLETED' && $status!=='APPROVED') {
  pp_log(['invalid_state'=>$status,'order'=>$orderID,'custom'=>$custom]);
  echo json_encode(['ok'=>false,'error'=>'invalid_state_or_plan','state'=>$status]); exit;
}

$cat = plans_catalog();
if (!$plan || !isset($cat[$plan])) {
  pp_log(['missing_plan'=>$plan,'from_custom'=>$custom]);
  echo json_encode(['ok'=>false,'error'=>'invalid_state_or_plan']); exit;
}

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("INSERT INTO payments(user_id, order_id, provider, plan_code, amount_usd, currency, status, raw_json, created_at)
                       VALUES(?,?,?,?,?,?,?, ?, CURRENT_TIMESTAMP)");
  $st->execute([
    $uid,
    $orderID,
    'paypal',
    $plan,
    (float)$cat[$plan]['usd'],
    'USD',
    'completed',
    json_encode($res, JSON_UNESCAPED_SLASHES),
  ]);

  $inc = (int)$cat[$plan]['inc'];
  if ($inc>0) {
    $up = $pdo->prepare("UPDATE users SET quota_limit = quota_limit + ? WHERE id=?");
    $up->execute([$inc,$uid]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true,'inc'=>$inc,'state'=>$status,'plan'=>$plan]); exit;

} catch(Throwable $e) {
  $pdo->rollBack();
  pp_log(['db_err'=>$e->getMessage()]);
  echo json_encode(['ok'=>false,'error'=>'db','msg'=>$e->getMessage()]); exit;
}
