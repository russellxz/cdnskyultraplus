<?php
/**
 * admin_stripe.php
 * Página de configuración de Stripe (solo UI/estilos; lógica intacta)
 */

require_once __DIR__.'/db.php';

/* (Opcional) Cargar autoloader de Composer si existe para detectar el SDK */
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
  require_once $autoload;
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];

/* ====== Guard de admin ====== */
try {
  $st = $pdo->prepare("SELECT is_admin FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  if ((int)$st->fetchColumn() !== 1) { http_response_code(403); exit('403'); }
} catch(Throwable $e){ http_response_code(403); exit('403'); }

/* Helpers */
function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }

/* ====== Detectar BASE_URL y construir Webhook URL ====== */
function compute_base_url(): string {
  if (defined('BASE_URL') && BASE_URL) return rtrim(BASE_URL, '/');
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
  $scheme = $https ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
  $base   = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
  $base   = rtrim(str_replace('\\','/', $base), '/');
  if ($base === '/') $base = '';
  return $scheme.'://'.$host.$base;
}
$webhookURL = compute_base_url().'/stripe_webhook.php';

/* ====== Guardado (POST) con CSRF ====== */
$ok = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf_ok = isset($_SESSION['csrf_stripe'], $_POST['csrf']) && hash_equals($_SESSION['csrf_stripe'], $_POST['csrf']);
  if (!$csrf_ok) {
    $err = 'CSRF inválido. Recarga la página.';
  } else {
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

/* ====== Carga de config actual ====== */
$cfg = [
  'pub'    => setting_get('stripe_public', ''),
  'sec'    => setting_get('stripe_secret', ''),
  'wh'     => setting_get('stripe_webhook_secret', ''),
  'p50'    => setting_get('stripe_price_plus50', ''),
  'p120'   => setting_get('stripe_price_plus120', ''),
  'p250'   => setting_get('stripe_price_plus250', ''),
  'pdelux' => setting_get('stripe_price_deluxe', ''),
];

/* CSRF token para el form */
$_SESSION['csrf_stripe'] = bin2hex(random_bytes(16));

/* Estado del SDK (solo informativo) */
$hasSdk = class_exists('\Stripe\StripeClient');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configurar Stripe — Admin</title>
<style>
  :root{
    --bg:#0b0c12;            /* fondo base oscuro */
    --bg-soft:#0f1424;       /* paneles */
    --line:#2a3550;          /* bordes */
    --ink:#ffffff;           /* texto principal (blanco) */
    --ink-dim:#dbe3ff;       /* blanco suave */
    --muted:#9fb2d9;         /* texto secundario */
    /* Paleta morado + azul */
    --violet:#7c3aed;        /* morado 600 */
    --indigo:#5b5bd6;        /* índigo */
    --blue:#3b82f6;          /* azul 500 */
    --grad:linear-gradient(135deg,var(--violet),var(--indigo),var(--blue));
    --grad-soft:radial-gradient(800px 420px at 110% -10%,rgba(124,58,237,.18),transparent 60%),
                radial-gradient(800px 420px at -10% 120%,rgba(59,130,246,.14),transparent 55%);
    --success:#10b981;
    --danger:#ef4444;
    --radius:14px;
    --shadow:0 12px 40px rgba(60,72,140,.25);
  }
  *{box-sizing:border-box}
  body{
    margin:0;color:var(--ink);
    background:var(--grad-soft), var(--bg);
    font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  }
  a{color:#b7c7ff;text-decoration:none}
  .wrap{max-width:980px;margin:0 auto;padding:22px}

  /* Topbar */
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
  .crumbs{display:flex;align-items:center;gap:8px;color:var(--muted)}
  .crumbs b{color:var(--ink)}
  .btn{
    display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;
    border:1px solid var(--line);background:#0f152a;color:var(--ink);font-weight:700;cursor:pointer;
  }
  .btn.primary{border:0;background:var(--grad);color:#fff;box-shadow:var(--shadow)}
  .btn.ghost{background:transparent}
  .btn:focus{outline:2px solid rgba(124,58,237,.5);outline-offset:2px}

  /* Chips */
  .chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:12px;border:1px solid var(--line);background:#0d1222;color:var(--ink-dim)}
  .chip.ok{border-color:rgba(16,185,129,.35);background:rgba(16,185,129,.08);color:#b7f7d6}
  .chip.warn{border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08);color:#fde68a}

  /* Card */
  .card{background:var(--bg-soft);border:1px solid var(--line);border-radius:var(--radius);padding:18px;margin-bottom:16px;box-shadow:var(--shadow)}
  .card h2,.card h3{margin:0 0 12px}
  .muted{color:var(--muted);font-size:13px}
  .help{font-size:13px;color:var(--ink-dim)}
  .divider{height:1px;background:var(--line);margin:12px 0}

  /* Grid */
  .grid{display:grid;gap:12px}
  .grid-2{display:grid;gap:12px;grid-template-columns:repeat(2,minmax(0,1fr))}
  @media (max-width:760px){ .grid-2{grid-template-columns:1fr} }

  /* Inputs */
  label.small{display:block;font-size:12px;color:#b4c2ee;margin:4px 0 6px}
  .field{position:relative}
  .input{
    width:100%;padding:12px 44px 12px 12px;border-radius:12px;
    border:1px solid var(--line);background:#0d1426;color:var(--ink);
    caret-color:#c7d2fe;
  }
  .input[readonly]{opacity:.9}
  .reveal,.copybtn{
    position:absolute;right:8px;top:50%;transform:translateY(-50%);
    border:0;border-radius:10px;padding:6px 10px;background:transparent;color:#c6d3ff;cursor:pointer
  }
  .reveal:hover,.copybtn:hover{color:#fff}

  /* Alerts */
  .alert{padding:10px;border-radius:12px;margin:10px 0}
  .alert.ok{background:#062016;border:1px solid #0b5b47;color:#d1fae5}
  .alert.err{background:#2a0e12;border:1px solid #7f1d1d;color:#fecaca}

  /* Sticky footer actions */
  .sticky-actions{position:sticky;bottom:0;z-index:10;padding-top:8px}
  .sticky-actions .card{margin-bottom:0}

  /* Header banner con gradiente */
  .banner{
    background:var(--grad);
    border-radius:16px;
    padding:28px 22px;
    color:#fff;
    box-shadow:var(--shadow);
  }
  .banner h1{margin:0 0 6px;font-size:22px}
  .banner .sub{color:#eef2ff;opacity:.9;font-size:14px}
</style>
</head>
<body>
  <div class="wrap">
    <!-- Topbar -->
    <div class="topbar">
      <div class="crumbs">
        <a class="btn ghost" href="admin.php">⬅️ Volver</a>
        <span>/</span>
        <b>Configurar Stripe</b>
      </div>
      <div class="chip <?= $hasSdk ? 'ok' : 'warn'?>">
        <?= $hasSdk ? '✅ SDK detectado (stripe/stripe-php)' : '⚠️ SDK no detectado' ?>
      </div>
    </div>

    <!-- Banner -->
    <div class="banner card" style="border:0">
      <h1>Stripe — Credenciales y Price IDs</h1>
      <div class="sub">
        Añade tus claves y configura el <b>webhook</b> para recibir eventos de pago.
      </div>
    </div>

    <!-- Mensajes -->
    <?php if($ok): ?><div class="alert ok"><?=$ok?></div><?php endif; ?>
    <?php if($err): ?><div class="alert err"><?=$err?></div><?php endif; ?>

    <!-- Webhook + comandos -->
    <div class="card">
      <h3>Webhook</h3>
      <p class="muted">Usa esta URL en Stripe Dashboard → Developers → Webhooks → Add endpoint.</p>
      <div class="grid">
        <div class="field">
          <label class="small">URL del Webhook</label>
          <input class="input" id="whurl" value="<?=h($webhookURL)?>" readonly>
          <button type="button" class="copybtn" onclick="copyVal('whurl')" title="Copiar">📋</button>
        </div>
        <p class="help">
          Habilita el evento <code>checkout.session.completed</code>.
        </p>
      </div>
      <div class="divider"></div>

      <?php if(!$hasSdk): ?>
        <h3>SDK de Stripe</h3>
        <p class="muted">Instálalo con Composer en tu servidor:</p>
        <div class="field">
          <input class="input" id="composerCmd" value="composer require stripe/stripe-php" readonly>
          <button type="button" class="copybtn" onclick="copyVal('composerCmd')" title="Copiar">📋</button>
        </div>
      <?php endif; ?>
    </div>

    <!-- Formulario -->
    <form method="post" class="grid" autocomplete="off" style="margin-bottom:16px">
      <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf_stripe'])?>">

      <div class="card">
        <h3>Claves de API</h3>
        <div class="grid-2" style="margin-top:6px">
          <div class="field">
            <label class="small">Publishable key (pk_...)</label>
            <input class="input" name="stripe_public" value="<?=h($cfg['pub'])?>" placeholder="pk_test_...">
          </div>
          <div class="field">
            <label class="small">Secret key (sk_...)</label>
            <input class="input" id="sk" type="password" name="stripe_secret" value="<?=h($cfg['sec'])?>" placeholder="sk_test_...">
            <button type="button" class="reveal" onclick="tgl('sk')" title="Ver/Ocultar">👁️</button>
          </div>
        </div>
        <div class="field" style="margin-top:10px">
          <label class="small">Webhook signing secret (whsec_...)</label>
          <input class="input" id="wh" type="password" name="stripe_webhook_secret" value="<?=h($cfg['wh'])?>" placeholder="whsec_...">
          <button type="button" class="reveal" onclick="tgl('wh')" title="Ver/Ocultar">👁️</button>
        </div>
      </div>

      <div class="card">
        <h3>Price IDs (pago único)</h3>
        <div class="grid-2" style="margin-top:6px">
          <div class="field">
            <label class="small">PLUS50 — Price ID</label>
            <input class="input" name="stripe_price_plus50" value="<?=h($cfg['p50'])?>" placeholder="price_...">
          </div>
          <div class="field">
            <label class="small">PLUS120 — Price ID</label>
            <input class="input" name="stripe_price_plus120" value="<?=h($cfg['p120'])?>" placeholder="price_...">
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label class="small">PLUS250 — Price ID</label>
            <input class="input" name="stripe_price_plus250" value="<?=h($cfg['p250'])?>" placeholder="price_...">
          </div>
          <div class="field">
            <label class="small">DELUXE — Price ID</label>
            <input class="input" name="stripe_price_deluxe" value="<?=h($cfg['pdelux'])?>" placeholder="price_...">
          </div>
        </div>
      </div>

      <div class="sticky-actions">
        <div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div class="muted">Revisa los cambios antes de guardar.</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a class="btn" href="admin.php">Cancelar</a>
            <button class="btn primary" type="submit">💾 Guardar configuración</button>
          </div>
        </div>
      </div>
    </form>
  </div>

<script>
function tgl(id){
  const i=document.getElementById(id);
  if(!i) return;
  i.type = (i.type==='password'?'text':'password');
}
async function copyVal(id){
  const el = document.getElementById(id);
  if(!el) return alert('No encontrado');
  try{
    await navigator.clipboard.writeText(el.value||'');
  }catch{
    prompt('Copia el valor:', el.value||'');
  }
}
</script>
</body>
</html>
