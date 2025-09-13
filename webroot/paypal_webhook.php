<?php
// Webhook de PayPal
// Maneja (al menos): PAYMENT.CAPTURE.COMPLETED y CHECKOUT.ORDER.APPROVED

require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';
require_once __DIR__.'/mail.php'; // para send_custom_email si está disponible

header('Content-Type: application/json; charset=utf-8');

// 1) Lee el cuerpo del evento
$raw = file_get_contents('php://input');
$event = json_decode($raw, true);
if (!$event) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_json']);
  exit;
}

// 2) (Opcional) Verifica la firma del webhook si tienes configurado un Webhook ID
try {
  $cfg = paypal_cfg();
  if (!empty($cfg['webhook_id'])) {
    $verifyPayload = [
      'auth_algo'        => $_SERVER['HTTP_PAYPAL_AUTH_ALGO']        ?? '',
      'cert_url'         => $_SERVER['HTTP_PAYPAL_CERT_URL']         ?? '',
      'transmission_id'  => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']  ?? '',
      'transmission_sig' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? '',
      'transmission_time'=> $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME']?? '',
      'webhook_id'       => $cfg['webhook_id'],
      'webhook_event'    => $event,
    ];
    // Esta llamada lanza excepción si falla (paypal_request)
    $ver = paypal_request('POST','/v1/notifications/verify-webhook-signature', $verifyPayload);
    if (($ver['verification_status'] ?? '') !== 'SUCCESS') {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'bad_signature']);
      exit;
    }
  }
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'verify_failed','detail'=>$e->getMessage()]);
  exit;
}

// 3) Helpers locales
function order_id_from_capture(array $event): ?string {
  // a) La ruta “bonita” que suele venir:
  $oid = $event['resource']['supplementary_data']['related_ids']['order_id'] ?? null;
  if ($oid) return $oid;

  // b) A veces viene un link "up" al order:
  foreach (($event['resource']['links'] ?? []) as $ln) {
    if (($ln['rel'] ?? '') === 'up' && !empty($ln['href'])) {
      // .../v2/checkout/orders/XXXX
      if (preg_match('~\/v2\/checkout\/orders\/([^\/\?]+)~', $ln['href'], $m)) {
        return $m[1];
      }
    }
  }

  // c) Intento final: consultar el capture por ID y sacar el enlace al order
  try {
    $capId = $event['resource']['id'] ?? null;
    if ($capId) {
      $cap = paypal_request('GET', '/v2/payments/captures/'.$capId, null);
      foreach (($cap['links'] ?? []) as $ln) {
        if (($ln['rel'] ?? '') === 'up' && !empty($ln['href'])) {
          if (preg_match('~\/v2\/checkout\/orders\/([^\/\?]+)~', $ln['href'], $m)) {
            return $m[1];
          }
        }
      }
    }
  } catch (Throwable $e) { /* ignorar */ }

  return null;
}

/** Aplica beneficios del plan (suma cupo o activa deluxe). */
function apply_plan_benefits(int $user_id, string $plan_code): void {
  global $pdo;
  $cat = plan_catalog();
  if (empty($cat[$plan_code])) return;
  $p = $cat[$plan_code];

  if (($p['type'] ?? 'oneoff') === 'oneoff') {
    $add = (int)($p['quota_add'] ?? 0);
    if ($add > 0) {
      $pdo->prepare("UPDATE users SET quota_limit = quota_limit + ? WHERE id=?")->execute([$add, $user_id]);
    }
  } elseif ($p['type'] === 'sub') {
    // Reservado para más adelante: activar deluxe
    $pdo->prepare("UPDATE users SET is_deluxe=1 WHERE id=?")->execute([$user_id]);
  }
}

/** Enviar factura por email (simple). */
function email_invoice(int $invoice_id): void {
  global $pdo;
  // Carga datos mínimos
  $i = $pdo->prepare("SELECT number,user_id,title,amount_usd,currency,issued_at FROM invoices WHERE id=?");
  $i->execute([$invoice_id]); $inv = $i->fetch();
  if (!$inv) return;

  $u = $pdo->prepare("SELECT email,first_name,last_name FROM users WHERE id=?");
  $u->execute([$inv['user_id']]); $user = $u->fetch();
  if (!$user) return;

  $fullName = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
  $to = $user['email'];

  $html = '
  <div style="font-family:system-ui,Segoe UI,Arial,sans-serif;background:#0b0b12;color:#eaf2ff;padding:24px">
    <div style="max-width:560px;margin:0 auto;background:#111827;border:1px solid #273042;border-radius:12px;padding:18px">
      <img src="https://cdn.russellxz.click/47d048e3.png" alt="SkyUltraPlus" style="height:42px;display:block;margin:0 auto 6px">
      <h2 style="text-align:center;margin:8px 0 14px">Factura '.$inv['number'].'</h2>
      <p>Hola '.htmlspecialchars($fullName?:$to).',</p>
      <p>Gracias por tu compra. Aquí tienes el detalle:</p>
      <ul>
        <li><b>Concepto:</b> '.htmlspecialchars($inv['title']).'</li>
        <li><b>Monto:</b> '.number_format((float)$inv['amount_usd'],2).' '.$inv['currency'].'</li>
        <li><b>Fecha:</b> '.htmlspecialchars($inv['issued_at']).'</li>
      </ul>
      <p style="margin-top:14px">Saludos,<br>Equipo SkyUltraPlus</p>
    </div>
  </div>';

  if (function_exists('send_custom_email')) {
    $err = '';
    @send_custom_email($to, 'Factura '.$inv['number'].' — SkyUltraPlus', $html, $err);
  } else {
    // Fallback muy básico si no hay mail.php
    @mail($to, 'Factura '.$inv['number'].' — SkyUltraPlus',
      strip_tags("Concepto: {$inv['title']}\nMonto: {$inv['amount_usd']} {$inv['currency']}"),
      "Content-Type: text/plain; charset=UTF-8");
  }
}

// 4) Procesa el tipo de evento
$type = $event['event_type'] ?? '';
try {
  if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
    $orderId = order_id_from_capture($event);
    if (!$orderId) throw new Exception('no_order_id');

    // Busca el pago preliminar que guardamos al crear la orden
    $st=$pdo->prepare("SELECT id,user_id,plan_code,amount_usd,status FROM payments WHERE order_id=? LIMIT 1");
    $st->execute([$orderId]); $pay=$st->fetch();
    if (!$pay) {
      // Si no existe, guarda igualmente para tener rastro
      payment_upsert(0, $orderId, 'unknown', (float)($event['resource']['amount']['value'] ?? 0), 'completed', $event);
      echo json_encode(['ok'=>true,'note'=>'stored_without_match']);
      exit;
    }

    // Marca como completado + guarda raw
    payment_upsert((int)$pay['user_id'], $orderId, (string)$pay['plan_code'], (float)$pay['amount_usd'], 'completed', $event);

    // (opcional) email del pagador si viene en el webhook
    $payer_email = $event['resource']['payer']['email_address'] ?? ($event['resource']['payer_email'] ?? null);
    if ($payer_email) {
      $pdo->prepare("UPDATE payments SET payer_email=? WHERE id=?")->execute([$payer_email, (int)$pay['id']]);
    }

    // Aplica beneficios del plan
    apply_plan_benefits((int)$pay['user_id'], (string)$pay['plan_code']);

    // Genera factura pagada
    $cat = plan_catalog();
    $title = 'Compra '.($cat[$pay['plan_code']]['name'] ?? $pay['plan_code']);
    $inv_id = invoice_create((int)$pay['user_id'], (int)$pay['id'], $title, (float)$pay['amount_usd'], 'USD', 'paid', [
      'order_id'=>$orderId,
      'event_id'=>$event['id'] ?? null,
    ]);
    $pdo->prepare("UPDATE invoices SET paid_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$inv_id]);

    // Envía factura al correo del usuario
    email_invoice($inv_id);

    echo json_encode(['ok'=>true]);
    exit;
  }

  if ($type === 'CHECKOUT.ORDER.APPROVED') {
    // Útil solo como traza: marcamos como "approved" pero no damos beneficios
    $orderId = $event['resource']['id'] ?? null;
    if ($orderId) {
      // Busca a quién pertenece para conservar el user_id/plan_code
      $st=$pdo->prepare("SELECT user_id,plan_code,amount_usd FROM payments WHERE order_id=? LIMIT 1");
      $st->execute([$orderId]); $p=$st->fetch();
      if ($p) {
        payment_upsert((int)$p['user_id'], $orderId, (string)$p['plan_code'], (float)$p['amount_usd'], 'approved', $event);
      } else {
        payment_upsert(0, $orderId, 'unknown', 0.0, 'approved', $event);
      }
    }
    echo json_encode(['ok'=>true,'note'=>'approved_only']);
    exit;
  }

  // Otros eventos: ignorar suavemente
  echo json_encode(['ok'=>true,'ignored'=>$type]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
