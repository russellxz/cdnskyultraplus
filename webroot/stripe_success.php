<?php
// webroot/stripe_success.php
require_once __DIR__.'/db.php';

// Autoload composer
$autoloads = [__DIR__.'/../vendor/autoload.php', __DIR__.'/vendor/autoload.php'];
foreach ($autoloads as $p) { if (is_file($p)) { require_once $p; break; } }

use Stripe\StripeClient;

function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }

$uid = (int)($_SESSION['uid'] ?? 0);
if (!$uid) { header('Location: index.php'); exit; }

$session_id = trim($_GET['session_id'] ?? '');
if ($session_id === '') {
  echo 'Falta session_id'; exit;
}

$sk = (string) setting_get('stripe_secret','');
if ($sk === '') {
  echo 'Stripe no está configurado.'; exit;
}

try{
  $stripe = new StripeClient($sk);
  $sess = $stripe->checkout->sessions->retrieve($session_id, []);
  if (($sess->payment_status ?? '') !== 'paid') {
    echo 'Pago no confirmado aún.'; exit;
  }

  $plan_code = strtoupper((string)($sess->metadata->plan_code ?? ''));
  $user_id   = (int)($sess->metadata->user_id ?? ($sess->client_reference_id ?? 0));
  $amount    = floatval(($sess->amount_total ?? 0)/100);
  $currency  = strtoupper($sess->currency ?? 'USD');

  if (!$user_id || !$plan_code) { echo 'Faltan datos.'; exit; }

  // Idempotencia simple: ¿ya está?
  $st = $pdo->prepare("SELECT id,status FROM payments WHERE order_id=? LIMIT 1");
  $st->execute([$session_id]);
  $row = $st->fetch();
  if (!($row && $row['status']==='completed')) {
    // aplicar (igual que en webhook)
    $pdo->beginTransaction();
    try{
      payment_upsert(
        $user_id,
        $session_id,
        $plan_code,
        $amount,
        'completed',
        ['provider'=>'stripe','source'=>'success_fallback']
      );

      $delta = 0; $set_deluxe=false;
      switch ($plan_code) {
        case 'PLUS50':  $delta = 50; break;
        case 'PLUS120': $delta = 120; break;
        case 'PLUS250': $delta = 250; break;
        case 'DELUXE':
        case 'DELUXE_LIFE': $set_deluxe = true; break;
      }
      if ($delta>0){
        $st = $pdo->prepare("UPDATE users SET quota_limit = quota_limit + ? WHERE id=?");
        $st->execute([$delta, $user_id]);
      }
      if ($set_deluxe){
        $st = $pdo->prepare("UPDATE users SET is_deluxe=1 WHERE id=?");
        $st->execute([$user_id]);
      }
      $pdo->commit();
    } catch(Throwable $e){
      $pdo->rollBack();
      echo 'Error al aplicar crédito.'; exit;
    }
  }

  // UI simple
  ?>
  <!doctype html><meta charset="utf-8">
  <style>
    body{font:16px/1.5 system-ui;background:#0b0b0d;color:#eaf2ff;margin:0}
    .wrap{max-width:600px;margin:40px auto;padding:20px}
    .card{background:#111827;border:1px solid #334155;border-radius:12px;padding:18px}
    .btn{display:inline-block;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:800}
  </style>
  <div class="wrap">
    <div class="card">
      <h2>✅ Pago recibido</h2>
      <p>Plan: <b><?=h($plan_code)?></b> · Monto: <b><?=h($amount.' '.$currency)?></b></p>
      <p>Tu crédito ya fue aplicado a la cuenta.</p>
      <p><a class="btn" href="profile.php">Volver al perfil</a></p>
    </div>
  </div>
  <?php
} catch(Throwable $e){
  echo 'Error al consultar Stripe.';
}
