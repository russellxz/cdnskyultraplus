<?php
// webroot/stripe_webhook.php
require_once __DIR__.'/db.php';

// Composer autoload (vendor está 1 nivel arriba de webroot)
$autoloads = [__DIR__.'/../vendor/autoload.php', __DIR__.'/vendor/autoload.php'];
foreach ($autoloads as $p) { if (is_file($p)) { require_once $p; break; } }

use Stripe\Webhook as StripeWebhook;
use Stripe\StripeClient;

// === util: log a archivo simple para depurar ===
function slog($msg){
  $f = __DIR__.'/../stripe_error.log';
  @file_put_contents($f, '['.date('c')."] ".$msg."\n", FILE_APPEND);
}

// === lee secret key y whsec desde settings ===
$sk    = (string) setting_get('stripe_secret','');
$whsec = (string) setting_get('stripe_webhook_secret','');

// === lee payload + firma ===
$payload = @file_get_contents('php://input') ?: '';
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === '') {
  slog('webhook: payload vacío');
  http_response_code(400);
  echo 'no payload';
  exit;
}

// === verifica firma si hay whsec ===
$event = null;
if ($whsec && class_exists(StripeWebhook::class)) {
  try {
    $event = StripeWebhook::constructEvent($payload, $sig, $whsec);
  } catch (Throwable $e) {
    slog('webhook: firma inválida: '.$e->getMessage());
    http_response_code(400);
    echo 'bad signature';
    exit;
  }
} else {
  // sin firma (no recomendado), parse directo
  $event = json_decode($payload);
}

// === manejamos solo checkout.session.completed ===
$type = $event->type ?? ($event->type ?? null);
if ($type !== 'checkout.session.completed') {
  http_response_code(200);
  echo 'ignored';
  exit;
}

$session = $event->data->object ?? null;
if (!$session) {
  slog('webhook: sin session en event');
  http_response_code(400);
  echo 'no session';
  exit;
}

// === datos clave de la sesión ===
$session_id  = (string)($session->id ?? '');
$payment_intent_id = (string)($session->payment_intent ?? '');
$amount_total = floatval(($session->amount_total ?? 0)/100);
$currency     = strtoupper($session->currency ?? 'USD');
$plan_code    = strtoupper((string)($session->metadata->plan_code ?? ''));
$user_id      = (int)($session->metadata->user_id ?? ($session->client_reference_id ?? 0));
$paid         = ($session->payment_status ?? '') === 'paid';

slog("webhook: session=$session_id plan=$plan_code user=$user_id paid=".($paid?'yes':'no'));

if (!$paid || !$user_id || !$plan_code) {
  slog('webhook: faltan datos (paid/user_id/plan_code)');
  http_response_code(200);
  echo 'noop';
  exit;
}

// === idempotencia: si ya registramos ese order_id, no repetir ===
try {
  $st = $pdo->prepare("SELECT id,status FROM payments WHERE order_id=? LIMIT 1");
  $st->execute([$session_id]);
  $row = $st->fetch();
  if ($row && $row['status']==='completed') {
    http_response_code(200);
    echo 'already done';
    exit;
  }
} catch(Throwable $e){ /* continua */ }

// === aplicar el plan (crédito) dentro de transacción ===
$pdo->beginTransaction();
try {
  // Upsert del pago (guardamos provider en el raw_json)
  payment_upsert(
    $user_id,
    $session_id,
    $plan_code,
    $amount_total,
    'completed',
    ['provider'=>'stripe','session_id'=>$session_id,'payment_intent'=>$payment_intent_id,'currency'=>$currency]
  );

  // Suma de créditos o deluxe
  $delta = 0;
  $set_deluxe = false;
  switch ($plan_code) {
    case 'PLUS50':  $delta = 50; break;
    case 'PLUS120': $delta = 120; break;
    case 'PLUS250': $delta = 250; break;
    case 'DELUXE':
    case 'DELUXE_LIFE':
      $set_deluxe = true; break;
    default:
      // Si mandaste otro code, no hacemos nada para no romper
      slog('webhook: plan desconocido '.$plan_code);
  }

  if ($delta > 0) {
    $st = $pdo->prepare("UPDATE users SET quota_limit = quota_limit + ? WHERE id=?");
    $st->execute([$delta, $user_id]);
  }
  if ($set_deluxe) {
    $st = $pdo->prepare("UPDATE users SET is_deluxe=1 WHERE id=?");
    $st->execute([$user_id]);
  }

  $pdo->commit();
  http_response_code(200);
  echo 'ok';
} catch(Throwable $e){
  $pdo->rollBack();
  slog('webhook: error al aplicar crédito: '.$e->getMessage());
  http_response_code(500);
  echo 'error';
}
