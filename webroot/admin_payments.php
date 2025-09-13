<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/paypal.php';

if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];
$me  = $pdo->query("SELECT * FROM users WHERE id=$uid")->fetch();
if (!$me || !$me['is_admin']) { http_response_code(403); exit('403'); }

// POST actions (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    // Guardar settings; si secret viene vacío, mantenemos el actual
    $mode  = ($_POST['pp_mode'] ?? 'sandbox');
    $mode  = ($mode === 'live') ? 'live' : 'sandbox';
    setting_set('pp_mode', $mode);

    $cid   = trim($_POST['pp_client_id'] ?? '');
    $csec  = trim($_POST['pp_client_secret'] ?? '');
    $whid  = trim($_POST['pp_webhook_id'] ?? '');
    $bizn  = trim($_POST['biz_name'] ?? '');
    $bize  = trim($_POST['biz_email'] ?? '');
    $pref  = trim($_POST['invoice_prefix'] ?? '');

    if ($cid !== '')  setting_set('pp_client_id', $cid);
    if ($csec !== '') setting_set('pp_client_secret', $csec); // solo si lo envían
    setting_set('pp_webhook_id',   $whid);
    setting_set('biz_name',        $bizn);
    setting_set('biz_email',       $bize);
    setting_set('invoice_prefix',  $pref);

    exit('OK');
  }

  if ($action === 'test') {
    $err = null;
    $tok = paypal_get_token($err);
    if ($tok) exit("OK: Token obtenido.");
    exit("ERR: $err");
  }

  exit;
}

// Valores actuales para el formulario
$cfg = paypal_cfg();
$pp_mode    = $cfg['mode'];
$pp_client  = setting_get('pp_client_id','');
$pp_secret  = setting_get('pp_client_secret',''); // NO lo mostraremos
$pp_webhook = setting_get('pp_webhook_id','');
$biz_name   = setting_get('biz_name','SkyUltraPlus');
$biz_email  = setting_get('biz_email','soporte@tu-dominio.com');
$inv_prefix = setting_get('invoice_prefix','INV-');
$webhook_url = (defined('BASE_URL') ? BASE_URL : '') . '/paypal_webhook.php';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pagos (PayPal) — Admin</title>
<style>
  body{margin:0;background:#0b0b0d;color:#eaf2ff;font:15px/1.6 system-ui}
  .wrap{max-width:900px;margin:0 auto;padding:20px}
  .card{background:#111827;border:1px solid #334155;border-radius:12px;padding:18px;margin-bottom:14px}
  .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
  .btn{display:inline-flex;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:8px 12px;font-weight:800;cursor:pointer;text-decoration:none}
  label.small{font-size:12px;color:#94a3b8;margin:0 0 4px;display:block}
  .grid{display:grid;gap:10px}
  @media(min-width:760px){.grid-2{grid-template-columns:1fr 1fr}}
  .muted{color:#9fb0c9}
  code{background:#0f172a;border:1px solid #334155;border-radius:6px;padding:2px 6px}
</style>
</head>
<body>
<div class="wrap">

  <h2>Pagos (PayPal)</h2>
  <p><a href="admin.php" style="color:#93c5fd">← Volver al Panel Admin</a></p>

  <div class="card">
    <h3>Credenciales</h3>
    <form onsubmit="saveCfg(event)">
      <div class="grid grid-2">
        <div>
          <label class="small">Modo</label>
          <select class="input" name="pp_mode">
            <option value="sandbox" <?=$pp_mode==='sandbox'?'selected':''?>>Sandbox (pruebas)</option>
            <option value="live"    <?=$pp_mode==='live'   ?'selected':''?>>Live (producción)</option>
          </select>
        </div>
        <div>
          <label class="small">Webhook ID (opcional)</label>
          <input class="input" name="pp_webhook_id" value="<?=htmlspecialchars($pp_webhook)?>" placeholder="ej: 8A12345678901234567890ABCD">
        </div>

        <div style="grid-column:1 / -1">
          <label class="small">Client ID</label>
          <input class="input" name="pp_client_id" value="<?=htmlspecialchars($pp_client)?>" placeholder="Copiar de tu App PayPal">
        </div>
        <div style="grid-column:1 / -1">
          <label class="small">Client Secret</label>
          <input class="input" name="pp_client_secret" value="" placeholder="Déjalo vacío para mantener el actual">
        </div>
      </div>

      <h4 style="margin-top:16px">Datos para facturas</h4>
      <div class="grid grid-2">
        <div>
          <label class="small">Nombre de negocio</label>
          <input class="input" name="biz_name" value="<?=htmlspecialchars($biz_name)?>" placeholder="SkyUltraPlus">
        </div>
        <div>
          <label class="small">Email de negocio</label>
          <input class="input" name="biz_email" value="<?=htmlspecialchars($biz_email)?>" placeholder="billing@tu-dominio.com">
        </div>
        <div>
          <label class="small">Prefijo de factura</label>
          <input class="input" name="invoice_prefix" value="<?=htmlspecialchars($inv_prefix)?>" placeholder="INV-">
        </div>
      </div>

      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn">Guardar</button>
        <button class="btn" type="button" onclick="testCfg()">Probar credenciales</button>
      </div>
    </form>
    <p class="muted" style="margin-top:10px">
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
