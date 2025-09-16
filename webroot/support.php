<?php
// Página pública: no requiere sesión.
// Si quieres el mismo header de seguridad que en otras páginas, puedes incluir db.php.
// require_once __DIR__.'/db.php';

function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }
function digits($p){ return preg_replace('/\D+/', '', $p); }
function wa_link($phone, $msg='Hola, necesito soporte de SkyUltraPlus'){
  return 'https://wa.me/'.digits($phone).'?text='.rawurlencode($msg);
}

$contacts = [
  ['name'=>'Lucas',   'phone'=>'+57 316 1325891',  'img'=>'https://cdn.skyultraplus.com/uploads/u3/e8e11cfcb94bf312.jpg'],
  ['name'=>'Diego',   'phone'=>'+57 301 7501838',  'img'=>'https://cdn.skyultraplus.com/uploads/u3/2e170b79fef45e4f.png'],
  ['name'=>'Gata',    'phone'=>'+52 453 128 7294', 'img'=>'https://cdn.skyultraplus.com/uploads/u3/a1e24da6a417214d.png'],
  ['name'=>'Mario',   'phone'=>'+57 322 6873710',  'img'=>'https://cdn.skyultraplus.com/uploads/u3/91bf48fe92dc45b0.jpeg'],
  ['name'=>'Russell', 'phone'=>'+1 516-709-6032',  'img'=>'https://cdn.skyultraplus.com/uploads/u3/00ca8c1a45ef1697.jpg'],
];

$email_support = 'soporte@skyultraplus.com';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Soporte — SkyUltraPlus</title>
<style>
  :root{
    --txt:#eaf2ff; --muted:#9fb0c9; --card:#0f172a; --stroke:#334155;
    --g1:#0ea5e9; --g2:#22d3ee; --g3:#60a5fa;
  }
  *{box-sizing:border-box}
  body{
    margin:0;font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto;color:var(--txt);
    background:
      radial-gradient(800px 500px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
      radial-gradient(800px 500px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
      linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%);
  }
  a{color:#93c5fd;text-decoration:none}
  .wrap{max-width:1100px;margin:0 auto;padding:20px}
  .card{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:18px}

  .btn{
    display:inline-flex;align-items:center;gap:8px;
    background:linear-gradient(90deg,var(--g1),var(--g2));color:#051425;
    border:none;border-radius:10px;padding:9px 14px;font-weight:800;cursor:pointer;text-decoration:none
  }
  .btn.ghost{background:transparent;color:var(--txt);border:1px solid var(--stroke)}
  .muted{color:var(--muted)}
  h1{margin:6px 0 10px;font-size:28px;line-height:1.2}

  /* grid de agentes */
  .support-grid{display:grid;gap:12px;margin-top:12px}
  @media(min-width:820px){ .support-grid{grid-template-columns:repeat(2,1fr)} }
  .support-card{
    display:flex;gap:12px;align-items:center;background:#0b1324;border:1px solid #2a3650;border-radius:14px;padding:10px
  }
  .support-avatar{
    width:56px;height:56px;border-radius:50%;object-fit:cover;aspect-ratio:1/1;flex:0 0 56px;
    border:2px solid rgba(255,255,255,.18);box-shadow:0 8px 24px rgba(0,0,0,.25);display:block
  }
  .support-info{min-width:0;flex:1}
  .support-name{font-weight:800}
  .support-phone{color:var(--muted);font-size:13px;margin-top:2px}
  .support-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .top-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Soporte de SkyUltraPlus</h1>
    <p class="muted">Elige un agente o escríbenos por correo.</p>
    <div class="top-actions">
      <a class="btn" href="mailto:<?=h($email_support)?>">✉️ <?=h($email_support)?></a>
      <a class="btn ghost" href="index.php">← Volver al inicio</a>
    </div>

    <div class="support-grid">
      <?php foreach($contacts as $c): ?>
        <div class="support-card">
          <img class="support-avatar" src="<?=h($c['img'])?>" alt="<?=h($c['name'])?>" width="56" height="56" loading="lazy">
          <div class="support-info">
            <div class="support-name"><?=h($c['name'])?></div>
            <div class="support-phone"><?=h($c['phone'])?></div>
            <div class="support-actions">
              <a class="btn" href="<?=h(wa_link($c['phone']))?>" target="_blank" rel="noopener">💬 WhatsApp</a>
              <a class="btn ghost" href="tel:<?=h(digits($c['phone']))?>">📞 Llamar</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>
</body>
</html>
