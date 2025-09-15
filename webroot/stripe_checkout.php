<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/../vendor_bootstrap.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid'])) { http_response_code(401); exit('Auth required'); }

header('Content-Type: application/json; charset=utf-8');

function json_out($a,$c=200){ http_response_code($c); echo json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

// -------- Validación de configuración --------
$sk = setting_get('stripe_sk','');
$pk = setting_get('stripe_pk','');
if ($sk==='' || !class_exists(\Stripe\StripeClient::class)) {
  json_out(['ok'=>false,'error'=>'Stripe no está configurado o SDK ausente'], 500);
}

// -------- Usuario actual --------
$uid = (int)$_SESSION['uid'];
$st  = $pdo->prepare("SELECT id,email FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]); $me = $st->fetch();
if (!$me) json_out(['ok'=>false,'error'=>'Usuario inválido'], 400);

// -------- Entrada --------
$plan = strtoupper(trim($_POST['plan'] ?? ($_GET['plan'] ?? '')));
$ALLOWED = ['PLUS50','PLUS120','PLUS250','DELUXE'];
if (!in_array($plan,$ALLOWED,true)) json_out(['ok'=>false,'error'=>'Plan inválido'], 400);

// Price ID desde settings (definidos en admin_stripe.php)
$price = setting_get('stripe_price_'.$plan, '');
if ($price==='') json_out(['ok'=>false,'error'=>'Falta Price ID del plan en configuración'], 400);

// -------- URLs de retorno --------
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme.'://'.$host;

$success = $base.'/stripe_success.php?session_id={CHECKOUT_SESSION_ID}';
$cancel  = $base.'/profile.php#pay';

// -------- Crear sesión --------
$stripe  = new \Stripe\StripeClient($sk);

try{
  $session = $stripe->checkout->sessions->create([
    'mode' => 'payment',
    'payment_method_types' => ['card'],
    'line_items' => [[ 'price' => $price, 'quantity' => 1 ]],
    'success_url' => $success,
    'cancel_url'  => $cancel,
    'customer_email' => $me['email'] ?? null,
    'client_reference_id' => (string)$uid,
    'metadata' => [
      'user_id'   => (string)$uid,
      'plan_code' => $plan,
    ],
  ]);
  json_out(['ok'=>true,'url'=>$session->url]);
}catch(Throwable $e){
  error_log('stripe_checkout create error: '.$e->getMessage());
  json_out(['ok'=>false,'error'=>'No se pudo crear el checkout'], 500);
}
