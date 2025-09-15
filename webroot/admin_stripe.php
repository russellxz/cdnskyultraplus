<?php
require_once __DIR__.'/db.php';

/* Cargar el autoloader de Composer si existe (para detectar stripe/stripe-php) */
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
  require_once $autoload;
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];

/* Verifica admin */
try {
  $st = $pdo->prepare("SELECT is_admin FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  if ((int)$st->fetchColumn() !== 1) { http_response_code(403); exit('403'); }
} catch(Throwable $e){ http_response_code(403); exit('403'); }

function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }

/* --------- BASE URL y Webhook URL correctos --------- */
function compute_base_url(): string {
  if (defined('BASE_URL') && BASE_URL) return rtrim(BASE_URL, '/');
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
  $base   = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
  $base   = rtrim(str_replace('\\','/', $base), '/');
  if ($base === '/') $base = ''; // si está en raíz
  return $scheme.'://'.$host.$base;
}
$webhookURL = compute_base_url().'/stripe_webhook.php';

/* --------- Guardado --------- */
$ok = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  $csrf_ok = isset($_SESSION['csrf_stripe'], $_POST['csrf']) && hash_equals($_SESSION['csrf_stripe'], $_POST['csrf']);
  if (!$csrf_ok) {
    $err = 'CSRF inválido. Recarga la página.';
  } else {
    // Guardar settings
    $keys = [
      'stripe_public',
      'stripe_secret',
      'stripe_webhook_secret',
      'stripe_price_plus50',
      'stripe_price_plus120',
      'stripe_price_plus250',
      'stripe_price_deluxe',
    ];
    foreach ($keys as $k) {
      $v = (string)($_POST[$k] ?? '');
      setting_set($k, $v);
    }
    $ok = '✅ Configuración guardada.';
  }
}

/* --------- Carga config actual --------- */
$cfg = [
  'pub'    => setting_get('stripe_public', ''),
  'sec'    => setting_get('stripe_secret', ''),
  'wh'     => setting_get('stripe_webhook_secret', ''),
  'p50'    => setting_get('stripe_price_plus50', ''),
  'p120'   => setting_get('stripe_price_plus120', ''),
  'p250'   => setting_get('stripe_price_plus250', ''),
  'pdelux' => setting_get('stripe_price_deluxe', ''),
];

$_SESSION['csrf_stripe'] = bin2hex(random_bytes(16));

/* SDK check (mostramos aviso si no está instalado) */
$hasSdk = class_exists('\Stripe\StripeClient');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configurar Stripe — Admin</title>
<style>
  body{margin:0;background:#0b0b0d;color:#eaf2ff;font:15px/1.6 system-ui}
  .wrap{max-width:900px;margin:0 auto;padding:20px}
  .card{background:#111827;border:1px solid #334155;border-radius:12px;padding:18px;margin-bottom:14px}
  .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
  .btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer;text-decoration:none}
  .muted{color:#9fb0c9;font-size:13px}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .grid{display:grid;gap:10px}
  label.small{font-size:12px;color:#94a3b8;margin:4px 0 4px;display:block}
  .ok{background:#052014;border:1px solid #065f46;color:#d1fae5;padding:10px;border-radius:10px;margin:10px 0}
  .err{background:#220a0a;border:1px solid #7f1d1d;color:#fecaca;padding:10px;border-radius:10px;margin:10px 0}
  code{background:#0f172a;border:1px solid #334155;padding:2px 6px;border-radius:6px}
  .pw{display:flex;gap:8px;align-items:center}
</style>
</head>
<body>
<div class="wrap">
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <a class="btn" href="admin.php">⬅️ Volver al panel</a>
  </div>

  <div class="card">
    <h2>Configurar Stripe</h2>
    <p class="muted">
      Añade tus claves y los <b>Price IDs</b> de tus planes. El <b>Webhook</b> debe apuntar a:
      <code><?=h($webhookURL)?></code><br>
      Habilita el evento <code>checkout.session.completed</code> en Stripe. (El cumplimiento se hace en <code>stripe_webhook.php</code>).
    </p>
    <p class="muted">SDK PHP:
      <?= $hasSdk ? '✅ Detectado (stripe/stripe-php)' : '⚠️ No detectado. Instala con <code>composer require stripe/stripe-php</code>' ?>
    </p>

    <?php if($ok): ?><div class="ok"><?=$ok?></div><?php endif; ?>
    <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>

    <form method="post" class="grid" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf_stripe'])?>">

      <div class="row2">
        <div>
          <label class="small">Publishable key (pk_...)</label>
          <input class="input" name="stripe_public" value="<?=h($cfg['pub'])?>" placeholder="pk_test_...">
        </div>
        <div>
          <label class="small">Secret key (sk_...)</label>
          <div class="pw">
            <input class="input" id="sk" type="password" name="stripe_secret" value="<?=h($cfg['sec'])?>" placeholder="sk_test_...">
            <button type="button" class="btn" onclick="tgl('sk')">👁️</button>
          </div>
        </div>
      </div>

      <div>
        <label class="small">Webhook signing secret (whsec_...)</label>
        <div class="pw">
          <input class="input" id="wh" type="password" name="stripe_webhook_secret" value="<?=h($cfg['wh'])?>" placeholder="whsec_...">
          <button type="button" class="btn" onclick="tgl('wh')">👁️</button>
        </div>
        <p class="muted" style="margin-top:6px">
          En el Dashboard de Stripe → Developers → Webhooks → Add endpoint → URL:
          <code><?=h($webhookURL)?></code> → Events: <code>checkout.session.completed</code> → copia el <b>Signing secret</b>.
        </p>
      </div>

      <div class="card" style="background:#0f172a">
        <h3>Price IDs (pago único)</h3>
        <div class="row2">
          <div>
            <label class="small">PLUS50 — Price ID</label>
            <input class="input" name="stripe_price_plus50" value="<?=h($cfg['p50'])?>" placeholder="price_...">
          </div>
          <div>
            <label class="small">PLUS120 — Price ID</label>
            <input class="input" name="stripe_price_plus120" value="<?=h($cfg['p120'])?>" placeholder="price_...">
          </div>
        </div>
        <div class="row2">
          <div>
            <label class="small">PLUS250 — Price ID</label>
            <input class="input" name="stripe_price_plus250" value="<?=h($cfg['p250'])?>" placeholder="price_...">
          </div>
          <div>
            <label class="small">DELUXE — Price ID</label>
            <input class="input" name="stripe_price_deluxe" value="<?=h($cfg['pdelux'])?>" placeholder="price_...">
          </div>
        </div>
      </div>

      <div>
        <button class="btn" type="submit">💾 Guardar configuración</button>
      </div>
    </form>
  </div>
</div>

<script>
function tgl(id){
  const i=document.getElementById(id);
  if(!i) return;
  i.type = (i.type==='password'?'text':'password');
}
</script>
</body>
</html>
