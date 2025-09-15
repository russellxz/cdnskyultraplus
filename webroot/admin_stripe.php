<?php
/**
 * admin_stripe.php
 * Página de configuración de Stripe (solo UI mejorada; lógica intacta)
 * - Guarda/lee claves de Stripe y Price IDs en settings
 * - Muestra estado del SDK (stripe/stripe-php)
 * - Provee URL de webhook y comandos útiles con “copiar”
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

/* Helpers muy usados */
function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }

/* ====== Detectar BASE_URL y construir Webhook URL ======
   (no cambia tu lógica; simplemente asegura una URL correcta si BASE_URL no está definida) */
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
    // Claves a persistir en settings (misma lógica que ya usabas)
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
    --bg:#0b0b0d;
    --card:#111827;
    --muted:#9fb0c9;
    --line:#334155;
    --ink:#eaf2ff;
    --ink-dim:#cdd9ee;
    --accent1:#0ea5e9;
    --accent2:#22d3ee;
    --danger:#f87171;
    --success:#10b981;
    --warn:#f59e0b;
    --glow: 0 10px 30px rgba(14,165,233,.15);
    --radius:14px;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    color:var(--ink);
    background:
      radial-gradient(700px 400px at 110% -10%, rgba(34,211,238,.18), transparent 60%),
      radial-gradient(700px 400px at -10% 110%, rgba(14,165,233,.12), transparent 55%),
      var(--bg);
    font:15px/1.6 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  }
  a{color:#93c5fd;text-decoration:none}
  .wrap{max-width:980px;margin:0 auto;padding:20px}
  /* ======= Header / Breadcrumb ======= */
  .topbar{
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px
  }
  .crumbs{display:flex;align-items:center;gap:8px;color:var(--muted)}
  .crumbs b{color:var(--ink)}
  .chip{
    display:inline-flex;align-items:center;gap:6px;
    padding:6px 10px;border-radius:999px;background:#0f172a;border:1px solid var(--line);font-size:12px;color:var(--ink-dim)
  }
  .chip.ok{border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.1);color:#a7f3d0}
  .chip.warn{border-color:rgba(245,158,11,.4);background:rgba(245,158,11,.08);color:#fde68a}
  /* ======= Cards ======= */
  .card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    padding:18px;
    margin-bottom:16px;
    box-shadow:var(--glow);
  }
  .card h2, .card h3{margin:0 0 12px}
  .muted{color:var(--muted);font-size:13px}
  .help{font-size:13px;color:var(--ink-dim)}
  .divider{height:1px;background:var(--line);margin:12px 0}
  /* ======= Forms ======= */
  .grid{display:grid;gap:12px}
  .grid-2{display:grid;gap:12px;grid-template-columns:repeat(2,minmax(0,1fr))}
  @media (max-width:760px){ .grid-2{grid-template-columns:1fr} }
  label.small{display:block;font-size:12px;color:#94a3b8;margin:4px 0 6px}
  .field{position:relative}
  .input{
    width:100%;padding:12px 44px 12px 12px;
    border-radius:10px;border:1px solid var(--line);
    background:#0f172a;color:var(--ink)
  }
  .reveal,.copybtn{
    position:absolute;right:6px;top:50%;transform:translateY(-50%);
    border:none;border-radius:8px;padding:6px 10px;cursor:pointer;
    background:transparent;color:var(--ink-dim);transition:.15s;
  }
  .reveal:hover,.copybtn:hover{color:var(--ink)}
  .btn{
    display:inline-flex;align-items:center;gap:8px;
    background:linear-gradient(90deg,var(--accent1),var(--accent2));
    color:#051425;border:none;border-radius:10px;padding:10px 14px;
    font-weight:800;cursor:pointer;text-decoration:none
  }
  .btn.ghost{
    background:transparent;color:var(--ink);border:1px solid var(--line);font-weight:700
  }
  .btn.danger{ background:linear-gradient(90deg,#ef4444,#f87171); color:#1b0b0b }
  .btn-row{display:flex;gap:10px;flex-wrap:wrap}
  .note{padding:10px;border:1px dashed var(--line);border-radius:10px;background:#0c1426}
  .alert{padding:10px;border-radius:10px;margin:10px 0}
  .alert.ok{background:#052014;border:1px solid #065f46;color:#d1fae5}
  .alert.err{background:#220a0a;border:1px solid #7f1d1d;color:#fecaca}
  code.inline{background:#0f172a;border:1px solid var(--line);padding:2px 6px;border-radius:6px}
  /* ======= Sticky footer actions on mobile ======= */
  .sticky-actions{position:sticky;bottom:0;z-index:10;padding-top:8px}
  .sticky-actions .card{margin-bottom:0}
</style>
</head>
<body>
  <div class="wrap">
    <!-- ======= Breadcrumb + estado SDK ======= -->
    <div class="topbar">
      <div class="crumbs">
        <a class="btn ghost" href="admin.php">⬅️ Volver</a>
        <span> / </span>
        <b>Configurar Stripe</b>
      </div>
      <div class="chip <?= $hasSdk ? 'ok' : 'warn'?>">
        <?= $hasSdk ? '✅ SDK detectado (stripe/stripe-php)' : '⚠️ SDK no detectado' ?>
      </div>
    </div>

    <!-- ======= Intro + Webhook ======= -->
    <div class="card">
      <h2>Stripe — Credenciales y Price IDs</h2>
      <p class="muted">
        Añade tus claves y los <b>Price IDs</b> para los planes. Configura el <b>Webhook</b> con <span class="inline code"></span> el evento
        <code class="inline">checkout.session.completed</code>.
      </p>

      <!-- Avisos de guardado/errores -->
      <?php if($ok): ?><div class="alert ok"><?=$ok?></div><?php endif; ?>
      <?php if($err): ?><div class="alert err"><?=$err?></div><?php endif; ?>

      <!-- Webhook URL + copiar -->
      <div class="grid">
        <div class="field">
          <label class="small">URL del Webhook</label>
          <input class="input" id="whurl" value="<?=h($webhookURL)?>" readonly>
          <button type="button" class="copybtn" onclick="copyVal('whurl')" title="Copiar">📋</button>
        </div>
        <p class="help">
          Stripe Dashboard → <b>Developers</b> → <b>Webhooks</b> → <b>Add endpoint</b> → URL:
          <code class="inline"><?=h($webhookURL)?></code> → Events: <code class="inline">checkout.session.completed</code>.
        </p>
      </div>
      <div class="divider"></div>

      <!-- Comando composer (si falta SDK) -->
      <?php if(!$hasSdk): ?>
        <div class="note">
          <b>Instalar SDK:</b>
          <div class="field" style="margin-top:6px">
            <input class="input" id="composerCmd" value="cd /var/www/cdnskyultraplus && composer require stripe/stripe-php" readonly>
            <button type="button" class="copybtn" onclick="copyVal('composerCmd')" title="Copiar">📋</button>
          </div>
          <div class="help" style="margin-top:6px">Ejecuta esto en tu servidor. Luego recarga esta página.</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- ======= Formulario principal ======= -->
    <form method="post" class="grid" autocomplete="off" style="margin-bottom:16px">
      <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf_stripe'])?>">

      <!-- Claves -->
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

      <!-- Price IDs -->
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

      <!-- Acciones -->
      <div class="sticky-actions">
        <div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div class="muted">Revisa los cambios antes de guardar.</div>
          <div class="btn-row">
            <a class="btn ghost" href="admin.php">Cancelar</a>
            <button class="btn" type="submit">💾 Guardar configuración</button>
          </div>
        </div>
      </div>
    </form>

  </div><!-- /wrap -->

<script>
/* Mostrar/Ocultar contraseña en inputs sensibles */
function tgl(id){
  const i=document.getElementById(id);
  if(!i) return;
  i.type = (i.type==='password'?'text':'password');
}

/* Copiar al portapapeles el valor de un input por id */
async function copyVal(id){
  const el = document.getElementById(id);
  if(!el) return alert('No encontrado');
  try{
    await navigator.clipboard.writeText(el.value||'');
    const oldTitle = el.title;
    el.title = '¡Copiado!';
    setTimeout(()=>{ el.title = oldTitle; }, 1200);
  }catch{
    prompt('Copia el valor:', el.value||'');
  }
}
</script>
</body>
</html>
