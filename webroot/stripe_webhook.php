<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/../vendor_bootstrap.php';

$whsec = setting_get('stripe_whsec','');
$sk    = setting_get('stripe_sk','');
if ($whsec==='' || $sk==='') { http_response_code(500); echo 'Stripe no configurado'; exit; }

$payload = @file_get_contents('php://input');
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try{
  $event = \Stripe\Webhook::constructEvent($payload, $sig, $whsec);
}catch(\UnexpectedValueException $e){
  http_response_code(400); echo 'payload invalido'; exit;
}catch(\Stripe\Exception\SignatureVerificationException $e){
  http_response_code(400); echo 'firma invalida'; exit;
}

// Utilidad: aplicar el beneficio del plan
function apply_plan_effect(PDO $pdo, string $plan, int $user_id): array {
  switch ($plan) {
    case 'PLUS50':  $inc=50;  $pdo->prepare("UPDATE users SET quota_limit=quota_limit+? WHERE id=?")->execute([$inc,$user_id]);  return ['inc'=>$inc,'deluxe'=>false];
    case 'PLUS120': $inc=120; $pdo->prepare("UPDATE users SET quota_limit=quota_limit+? WHERE id=?")->execute([$inc,$user_id]);  return ['inc'=>$inc,'deluxe'=>false];
    case 'PLUS250': $inc=250; $pdo->prepare("UPDATE users SET quota_limit=quota_limit+? WHERE id=?")->execute([$inc,$user_id]);  return ['inc'=>$inc,'deluxe'=>false];
    case 'DELUXE':  $pdo->prepare("UPDATE users SET is_deluxe=1 WHERE id=?")->execute([$user_id]);                                return ['inc'=>0,'deluxe'=>true];
    default: return ['inc'=>0,'deluxe'=>false];
  }
}

// Para vincular el payment_id a la factura (opcional)
function payment_id_by_order(string $order_id): ?int {
  global $pdo;
  $st=$pdo->prepare("SELECT id FROM payments WHERE order_id=? LIMIT 1");
  $st->execute([$order_id]);
  $id = $st->fetchColumn();
  return $id===false ? null : (int)$id;
}

if ($event->type === 'checkout.session.completed') {
  /** @var \Stripe\Checkout\Session $session */
  $session = $event->data->object;

  $user_id   = (int)($session->metadata->user_id ?? 0);
  $plan_code = (string)($session->metadata->plan_code ?? '');
  $amount    = isset($session->amount_total) ? ((int)$session->amount_total)/100 : 0;
  $currency  = strtoupper((string)($session->currency ?? 'USD'));
  $payer     = (string)($session->customer_details->email ?? '');

  if ($user_id>0 && $plan_code!=='') {
    // 1) Marca/actualiza registro de pago
    try {
      payment_upsert($user_id, $session->id, $plan_code, (float)$amount, 'completed', (array)$session);
    } catch(Throwable $e) {
      error_log('payment_upsert error: '.$e->getMessage());
    }

    // 2) Aplica beneficio en la cuenta
    try {
      $res = apply_plan_effect($pdo, $plan_code, $user_id);
    } catch(Throwable $e) {
      error_log('apply_plan_effect error: '.$e->getMessage());
    }

    // 3) (Opcional) Crea factura interna
    try {
      $pid = payment_id_by_order($session->id);
      invoice_create($user_id, $pid, "Stripe $plan_code", (float)$amount, $currency, 'paid', [
        'payer_email'=>$payer,
        'event_id'   =>$event->id,
      ]);
    } catch(Throwable $e) {
      error_log('invoice_create error: '.$e->getMessage());
    }
  }
}

http_response_code(200);
echo 'ok';
