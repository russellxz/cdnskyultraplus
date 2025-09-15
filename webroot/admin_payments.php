<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';

if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];
$me  = $pdo->query("SELECT * FROM users WHERE id=$uid")->fetch();
if (!$me || !(int)$me['is_admin']) { http_response_code(403); exit('403'); }

/* ==== POST (guardar / probar) ==== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    // Modo
    $mode = ($_POST['mode'] ?? 'sandbox');
    $mode = ($mode === 'live') ? 'live' : 'sandbox';
    setting_set('paypal_mode', $mode);

    // Credenciales
    $cid  = trim($_POST['client_id'] ?? '');
    $csec = trim($_POST['client_secret'] ?? ''); // si viene vacío NO se sobreescribe
    $whid = trim($_POST['webhook_id'] ?? '');

    if ($cid !== '')  setting_set('paypal_client_id', $cid);
    if ($csec !== '') setting_set('paypal_client_secret', $csec);
    setting_set('paypal_webhook_id', $whid);

    // Datos de factura
    $bizn  = trim($_POST['biz_name']   ?? '');
    $bizm  = trim($_POST['biz_mail']   ?? '');
    $pref  = trim($_POST['inv_prefix'] ?? 'INV-');
    setting_set('invoice_business', $bizn);
    setting_set('invoice_email',    $bizm);
    setting_set('invoice_prefix',   $pref);

    exit('OK');
  }

  if ($action === 'test') {
    $err = null;
    $tok = paypal_get_token($err); // usa paypal_mode/id/secret de settings
    echo $tok ? 'OK: Token obtenido.' : ('ERR: '.$err);
    exit;
  }

  exit;
}

/* ==== Valores actuales ==== */
$pp_mode    = setting_get('paypal_mode','sandbox');
$pp_client  = setting_get('paypal_client_id','');
$pp_webhook = setting_get('paypal_webhook_id','');

$biz_name   = setting_get('invoice_business','SkyUltraPlus');
$biz_email  = setting_get('invoice_email','soporte@tu-dominio.com');
$inv_prefix = setting_get('invoice_prefix','INV-');

$host = $_SERVER['HTTP_HOST'] ?? '';
$webhook_url = ($host ? 'https://'.$host : '').'/paypal_webhook.php';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pagos (PayPal) — Admin</title>
<style>
  :root{
    /* Paleta */
    --bg:#0b0b0d;
    --ink:#eaf2ff;
    --ink-dim:#cfd8ee;
    --muted:#a6b4cf;
    --line:#2a3346;
    --card:#111827;

    /* Degradados rojo → rosado → azul */
    --r:#ef4444;   /* rojo */
    --p:#ec4899;   /* rosado */
    --b:#3b82f6;   /* azul */
    --btn-grad: linear-gradient(90deg, var(--r), var(--p), var(--b));
    --btn-grad-hover: linear-gradient(90deg, #f05252, #f472b6, #60a5fa);

    /* Extras */
    --radius:12px;
    --shadow: 0 10px 30px rgba(59,130,246,.15);
  }

  *{box-sizing:border-box}
  body{
    margin:0;
    color:var(--ink);
    background:
      radial-gradient(800px 420px at 110% -10%, rgba(239,68,68,.18), transparent 55%),
      radial-gradient(700px 400px at -10% 110%, rgba(59,130,246,.16), transparent 55%),
      linear-gradient(160deg, rgba(236,72,153,.12), rgba(59,130,246,.08)),
      var(--bg);
    font:15px/1.6 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  }

  a{color:#9ec1ff;text-decoration:none}
  a:hover{opacity:.9}

  .wrap{max-width:960px;margin:0 auto;padding:20px}

  /* Tarjetas y tipografía */
  .card{
    background:var(--card);
    border:1px solid var(--line);
    border-radius:var(--radius);
    padding:18px;
    margin-bottom:16px;
    box-shadow:var(--shadow);
  }
  h2{margin:0 0 6px}
  h3{margin:0 0 12px}
  h4{margin:14px 0 8px}
  .muted{color:var(--muted)}
  code{
    background:#0f172a;border:1px solid var(--line);border-radius:8px;padding:2px 6px;color:var(--ink-dim)
  }

  /* Formulario */
  .grid{display:grid;gap:12px}
  .grid-2{grid-template-columns:1fr}
  @media(min-width:760px){ .grid-2{grid-template-columns:1fr 1fr} }

  label.small{font-size:12px;color:#94a3b8;margin:0 0 6px;display:block}
  .input, select.input{
    width:100%;padding:12px;border-radius:10px;
    border:1px solid var(--line);background:#0f172a;color:var(--ink);
    outline: none;
  }
  .input:focus, select.input:focus{border-color:#6ea8ff; box-shadow:0 0 0 3px rgba(59,130,246,.25)}

  /* Botones */
  .btn{
    display:inline-flex;align-items:center;gap:8px;
    background:var(--btn-grad);
    color:#061120;border:none;border-radius:10px;
    padding:10px 14px;font-weight:800;cursor:pointer;
    text-decoration:none; transition:.15s ease-in-out;
  }
  .btn:hover{background:var(--btn-grad-hover); transform: translateY(-1px)}
  .btn:active{transform:translateY(0)}
  .btn.ghost{
    background:transparent;color:var(--ink);
    border:1px solid var(--line); font-weight:700;
  }

  /* Encabezados / separadores */
  .bar-top{
    display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    margin-bottom:8px
  }
  .badge{
    display:inline-flex;align-items:center;gap:6px;
    background:#0f172a;border:1px solid var(--line);color:var(--ink-dim);
    padding:6px 10px;border-radius:999px;font-size:12px
  }
</style>
</head>
<body>
<div class="wrap">

  <div class="bar-top">
    <h2>Pagos (PayPal)</h2>
    <a href="admin.php" class="btn ghost">← Volver al Panel</a>
  </div>

  <div class="card">
    <h3>Credenciales</h3>
    <form onsubmit="saveCfg(event)">
      <div class="grid grid-2">
        <div>
          <label class="small">Modo</label>
          <select class="input" name="mode">
            <option value="sandbox" <?=$pp_mode==='sandbox'?'selected':''?>>Sandbox (pruebas)</option>
            <option value="live"    <?=$pp_mode==='live'   ?'selected':''?>>Live (producción)</option>
          </select>
        </div>
        <div>
          <label class="small">Webhook ID (opcional)</label>
          <input class="input" name="webhook_id" value="<?=htmlspecialchars($pp_webhook)?>" placeholder="8A1234567890ABCD…">
        </div>

        <div style="grid-column:1 / -1">
          <label class="small">Client ID</label>
          <input class="input" name="client_id" value="<?=htmlspecialchars($pp_client)?>" placeholder="Copiar de tu App PayPal">
        </div>
        <div style="grid-column:1 / -1">
          <label class="small">Client Secret</label>
          <input class="input" name="client_secret" value="" placeholder="Déjalo vacío para mantener el actual">
        </div>
      </div>

      <h4 style="margin-top:6px">Datos para facturas</h4>
      <div class="grid grid-2">
        <div>
          <label class="small">Nombre de negocio</label>
          <input class="input" name="biz_name" value="<?=htmlspecialchars($biz_name)?>" placeholder="SkyUltraPlus">
        </div>
        <div>
          <label class="small">Email de negocio</label>
          <input class="input" name="biz_mail" value="<?=htmlspecialchars($biz_email)?>" placeholder="billing@tu-dominio.com">
        </div>
        <div>
          <label class="small">Prefijo de factura</label>
          <input class="input" name="inv_prefix" value="<?=htmlspecialchars($inv_prefix)?>" placeholder="INV-">
        </div>
      </div>

      <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn">Guardar</button>
        <button class="btn" type="button" onclick="testCfg()">Probar credenciales</button>
      </div>
    </form>

    <p class="muted" style="margin-top:12px">
      Webhook recomendado: <code><?=htmlspecialchars($webhook_url)?></code><br>
      Eventos: <code>PAYMENT.CAPTURE.COMPLETED</code> y <code>CHECKOUT.ORDER.APPROVED</code>.
    </p>
  </div>

</div>

<script>
async function saveCfg(e){
  e.preventDefault();
  const fd = new FormData(e.target);
  fd.append('action','save');
  const r = await fetch('admin_payments.php', {method:'POST', body:fd});
  alert(await r.text());
}
async function testCfg(){
  const fd = new FormData(); fd.append('action','test');
  const r = await fetch('admin_payments.php', {method:'POST', body:fd});
  alert(await r.text());
}
</script>
</body>
</html>
