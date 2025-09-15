<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/config.php';

// Debe existir vendor/autoload.php
$autoload = __DIR__.'/../vendor/autoload.php';
if (!file_exists($autoload)) $autoload = __DIR__.'/vendor/autoload.php';
if (!file_exists($autoload)) {
  http_response_code(500);
  exit('Falta vendor/autoload.php (composer install).');
}
require_once $autoload;

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid  = (int)$_SESSION['uid'];
$plan = strtoupper(trim($_GET['plan'] ?? ''));

// Trae Price IDs desde settings
$prices = [
  'PLUS50'  => setting_get('stripe_price_plus50', ''),
  'PLUS120' => setting_get('stripe_price_plus120', ''),
  'PLUS250' => setting_get('stripe_price_plus250', ''),
  'DELUXE'  => setting_get('stripe_price_deluxe', ''),
];

function show_err($msg, $code=400){
  http_response_code($code);
  echo "<!doctype html><meta charset='utf-8'><body style='font-family:system-ui;background:#0b0b0d;color:#eaf2ff'><div style='max-width:720px;margin:40px auto;background:#111827;border:1px solid #334155;border-radius:12px;padding:18px'><h2>Error</h2><p>$msg</p><p><a href='profile.php' style='color:#93c5fd'>Volver</a></p></div></body>";
  exit;
}

if (!isset($prices[$plan])) show_err('Plan inválido.');
$priceId = $prices[$plan];

if (!$priceId) show_err('No hay Price ID configurado para este plan. Ve a Configurar Stripe.');
if (stripos($priceId, 'prod_') === 0) {
  show_err('Pusiste un Product ID (prod_…). Stripe Checkout necesita un Price ID (price_…). Corrígelo en Configurar Stripe.');
}

$secret = setting_get('stripe_secret', '');
if (!$secret) show_err('No hay Secret Key configurada en Stripe.');

try{
  $stripe = new \Stripe\StripeClient($secret);

  $session = $stripe->checkout->sessions->create([
    'mode' => 'payment',
    'line_items' => [[
      'price'    => $priceId,
      'quantity' => 1,
    ]],
    'success_url' => rtrim(BASE_URL,'/').'/stripe_success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => rtrim(BASE_URL,'/').'/profile.php',
    'metadata'    => [
      'user_id'   => (string)$uid,
      'plan_code' => $plan,
    ],
  ]);

  header('Location: '.$session->url, true, 303);
  exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
  show_err('Stripe API error: '.htmlspecialchars($e->getMessage()), 502);
} catch (Throwable $e) {
  show_err('Error inesperado: '.htmlspecialchars($e->getMessage()), 500);
}
