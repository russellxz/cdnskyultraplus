<?php
// paypal_create_order.php
declare(strict_types=1);

require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['uid'])) {
  http_response_code(401);
  echo json_encode(['error'=>'auth']);
  exit;
}

$plan = strtoupper(preg_replace('/[^A-Z0-9]/','', $_POST['plan'] ?? $_GET['plan'] ?? ''));
if ($plan === '') {
  http_response_code(400);
  echo json_encode(['error'=>'plan_required']);
  exit;
}

try {
  $order = paypal_create_order((int)$_SESSION['uid'], $plan);
  echo json_encode(['id' => $order['id']]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
