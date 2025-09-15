<?php
require_once __DIR__.'/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid=(int)$_SESSION['uid'];

/* Datos del usuario */
$st=$pdo->prepare("SELECT email,username,first_name,last_name,is_admin,is_deluxe,verified,quota_limit,api_key FROM users WHERE id=?");
$st->execute([$uid]); 
$me=$st->fetch();
if(!$me){ header('Location: logout.php'); exit; }

/* Conteo de archivos usados y espacio restante */
$c=$pdo->prepare("SELECT COUNT(*) AS c FROM files WHERE user_id=?");
$c->execute([$uid]);
$used=(int)($c->fetch()['c'] ?? 0);
$remain=max(0,(int)$me['quota_limit']-$used);

/* (MariaDB) Asegurar índice para búsquedas rápidas */
try {
  $ix = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = 'files'
                         AND INDEX_NAME  = 'files_userid_name'");
  $ix->execute();
  if ((int)$ix->fetchColumn() === 0) {
    // name puede ser VARCHAR(191) o TEXT; por eso usamos prefijo (191) para máxima compatibilidad
    $pdo->exec("ALTER TABLE files ADD INDEX files_userid_name (user_id, name(191))");
  }
} catch(Throwable $e){ /* silencioso */ }

/* Límite por plan (con fallback si no existen constantes) */
if (!defined('SIZE_LIMIT_FREE_MB'))   define('SIZE_LIMIT_FREE_MB',   5);
if (!defined('SIZE_LIMIT_DELUXE_MB')) define('SIZE_LIMIT_DELUXE_MB', 200); // Deluxe “pesado”
$maxMB = ((int)$me['is_deluxe'] === 1) ? SIZE_LIMIT_DELUXE_MB : SIZE_LIMIT_FREE_MB;

/* Nombre completo para usar en el mensaje de WhatsApp */
$fullName = trim(($me['first_name']??'').' '.($me['last_name']??''));

/* ===== Datos de soporte (no se muestran números; solo se usan para construir wa.me) ===== */
$support_contacts = [
  ['name'=>'Lucas',   'phone'=>'+57 316 1325891', 'img'=>'https://cdn.skyultraplus.com/uploads/u3/e8e11cfcb94bf312.jpg'],
  ['name'=>'Diego',   'phone'=>'+57 301 7501838', 'img'=>'https://cdn.skyultraplus.com/uploads/u3/2e170b79fef45e4f.png'],
  ['name'=>'Gata',    'phone'=>'+52 453 128 7294', 'img'=>'https://cdn.skyultraplus.com/uploads/u3/a1e24da6a417214d.png'],
  ['name'=>'Mario',   'phone'=>'+57 322 6873710', 'img'=>'https://cdn.skyultraplus.com/uploads/u3/91bf48fe92dc45b0.jpeg'],
  ['name'=>'Russell', 'phone'=>'+1 516-709-6032', 'img'=>'https://cdn.skyultraplus.com/uploads/u3/00ca8c1a45ef1697.jpg'],
];
$waContacts = array_map(function($c){
  return [
    'name'   => $c['name'],
    'img'    => $c['img'],
    'digits' => preg_replace('/\D+/', '', $c['phone']), // solo dígitos para wa.me
  ];
}, $support_contacts);
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel — CDN SkyUltraPlus</title>
<style>
  body{
    margin:0;font:15px/1.6 system-ui;color:#eaf2ff;
    background:
      radial-gradient(800px 500px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
      radial-gradient(800px 500px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
      linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%);
  }
  .wrap{max-width:1100px;margin:0 auto;padding:20px}
  .card{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:18px}
  .btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer;text-decoration:none}
  .btn.ghost{background:transparent;border:1px solid rgba(255,255,255,.2);color:#eaf2ff}
  .btn-sm{padding:6px 10px;border-radius:8px;font-size:13px}
  .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
  a{color:#93c5fd}
  .hero{text-align:center;margin-top:6px;margin-bottom:10px}
  .hero .logo{width:120px;display:block;margin:10px auto 6px;filter:drop-shadow(0 12px 40px rgba(219,39,119,.35))}
  .hero h1{
    margin:6px 0 0;font-size:28px;line-height:1.2;
    background:linear-gradient(90deg,#60a5fa,#22d3ee,#f472b6);
    -webkit-background-clip:text;background-clip:text;color:transparent;font-weight:900;
  }
  .row{display:grid;gap:14px}
  @media(min-width:860px){ .row2{grid-template-columns:1fr} }
  .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);font-weight:800}
  .pill.grad{background:linear-gradient(90deg,#0ea5e9,#22d3ee); color:#051425; border:none}
  .plans{display:grid;gap:16px}
  @media(min-width:900px){.plans{grid-template-columns:repeat(4,1fr)}}
  .plan{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:14px;text-align:center}
  .plan img{width:70px;height:70px;object-fit:contain;margin:6px auto 8px;display:block}
  .plan .title{font-weight:800;margin:6px 0 4px}
  .plan .price{font-size:22px;font-weight:900;margin:2px 0 8px}
  .muted{color:#9fb0c9}
  .topline{display:flex;justify-content:space-between;align-items:center}
  .bad{background:#172554;border:1px solid #334155;border-radius:999px;padding:4px 10px;margin-left:8px}
  code{user-select:all}
  .item{display:flex;align-items:center;gap:10px;border:1px solid #334155;background:#0f172a;border-radius:10px;padding:10px;margin-top:8px}
  .item .url{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .item.err{border-color:#7f1d1d;background:rgba(127,29,29,.15)}
  .quick{display:grid;gap:10px}
  .searchbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .results{display:grid;gap:8px}
  .r{display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:center;background:#0f172a;border:1px solid #334155;border-radius:10px;padding:8px}
  .r .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

  /* ===== Modal WhatsApp ===== */
  .wa-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:9999}
  .wa-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55)}
  .wa-sheet{
    position:relative; z-index:1; width:min(680px,92vw); max-height:82vh; overflow:auto;
    background:#0f172a; border:1px solid #334155; border-radius:16px; padding:16px;
    box-shadow:0 20px 60px rgba(0,0,0,.45)
  }
  .wa-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
  .wa-title{font-weight:900}
  .wa-close{background:transparent;border:1px solid #334155;color:#eaf2ff;border-radius:10px;padding:8px 10px;cursor:pointer}
  .wa-grid{display:grid;gap:12px;margin-top:8px}
  @media(min-width:720px){.wa-grid{grid-template-columns:repeat(2,1fr)}}
  .wa-card{
    display:flex;gap:12px;align-items:center;
    background:#0b1324;border:1px solid #2a3650;border-radius:14px;padding:10px
  }
  .wa-avatar{
    width:56px;height:56px;border-radius:50%;object-fit:cover;aspect-ratio:1/1;flex:0 0 56px;
    border:2px solid rgba(255,255,255,.18);box-shadow:0 8px 24px rgba(0,0,0,.25);display:block
  }
  .wa-info{flex:1;min-width:0}
  .wa-name{font-weight:800}
  .wa-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .wa-actions .btn{padding:7px 12px;border-radius:8px}
</style>
</head>
<body>
<div class="wrap">

  <!-- HERO -->
  <div class="hero">
    <img class="logo" src="https://cdn.russellxz.click/47d048e3.png" alt="Sky Ultra Plus">
    <h1>Bienvenido a SkyUltraPlus CDN</h1>
  </div>

  <!-- ESTADO -->
  <div class="card">
    <div class="topline">
      <div>
        <div>Hola, <b><?=htmlspecialchars(trim(($me['first_name']??'').' '.($me['last_name']??'')))?></b></div>
        <div style="margin-top:6px">
          Estado: <?= ((int)$me['is_deluxe']===1)?'💎 <b>Deluxe</b>':'Estándar' ?> <?= !empty($me['is_admin'])?'<span class="bad">Admin</span>':'' ?>
        </div>
      </div>
      <div><a href="logout.php">Salir</a></div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;align-items:center">
      <span class="pill">
        API Key:
        <code id="apikey" data-full="<?=htmlspecialchars($me['api_key'])?>" data-show="0">••••••••••••••••••••••••</code>
      </span>
      <button class="pill grad" id="toggleKey" type="button">Ver</button>
      <button class="pill grad" id="copyKey" type="button">Copiar API Key</button>
      <span class="pill grad">Disponibles: <b><?=$remain?></b></span>
      <span class="pill">Límite por archivo: <b><?=$maxMB?>MB</b></span>
    </div>

    <?php if((int)$me['is_deluxe']!==1): ?>
      <p class="muted" style="margin:10px 0 0">
        Cuentas normales: máximo <?=SIZE_LIMIT_FREE_MB?>MB por archivo. Con <b>Deluxe</b> subes hasta <?=SIZE_LIMIT_DELUXE_MB?>MB por archivo <b>(pago único $5)</b>.
      </p>
    <?php else: ?>
      <p class="muted" style="margin:10px 0 0">Tu plan <b>Deluxe</b> está activo. Disfruta subidas de hasta <b><?=SIZE_LIMIT_DELUXE_MB?>MB</b> por archivo.</p>
    <?php endif; ?>

    <p style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px">
      <a class="btn" href="list.php">📁 Ver todos mis archivos</a>
      <a class="btn" href="settings.php">👤 Perfil / Configuración</a>
      <?php if(!empty($me['is_admin'])): ?><a class="btn" href="admin.php">🛠️ Panel Admin</a><?php endif; ?>
    </p>
  </div>

  <!-- SUBIR -->
  <div class="row row2" style="margin-top:14px">
    <div class="card">
      <h3>Subir archivo</h3>
      <?php if((int)$me['verified']!==1): ?>
        <p>❌ Debes <b>verificar tu correo</b> antes de subir archivos.</p>
      <?php else: ?>
        <form id="up" enctype="multipart/form-data">
          <input class="input" name="name" placeholder="Nombre para buscar (ej: logo azul, nota #1)" required>
          <input class="input" id="file" type="file" name="file" required style="margin-top:8px">
          <button class="btn" id="upBtn" style="margin-top:8px">Subir</button>
        </form>
        <div id="out" style="margin-top:10px"></div>

        <!-- BUSCADOR RÁPIDO -->
        <div class="card" style="margin-top:14px">
          <div class="quick">
            <div class="searchbar">
              <input id="q" class="input" placeholder="Buscar rápido… (nombre o parte de la URL)" autocomplete="off">
              <button class="btn" id="qBtn" type="button">Buscar</button>
              <a class="btn ghost" href="list.php">Abrir listado completo</a>
            </div>
            <p class="muted" id="qHint">Escribe para buscar. Se muestran hasta 10 coincidencias.</p>
            <div class="results" id="qRes"></div>
          </div>
        </div>

        <script>
          const MAX_MB = <?= (int)$maxMB ?>;
          const IS_DELUXE = <?= ((int)$me['is_deluxe']===1) ? 'true' : 'false' ?>;
          const deluxeCTA = <?= ((int)$me['is_deluxe']===1) ? '""' : '" <a class=\\"btn btn-sm\\" href=\\"#payplans\\">Mejorar a Deluxe</a>"' ?>;

          const up    = document.getElementById('up');
          const out   = document.getElementById('out');
          const upBtn = document.getElementById('upBtn');
          const fileI = document.getElementById('file');

          function msg(ok, html){
            out.innerHTML = `<div class="item ${ok?'':'err'}">${html}</div>` + out.innerHTML;
          }

          up?.addEventListener('submit', async e => {
            e.preventDefault();
            const f = fileI.files?.[0];
            if(!f){ msg(false,'<span>❌</span> Selecciona un archivo.'); return; }
            if (f.size > MAX_MB*1024*1024) {
              msg(false, `<span>❌</span> Tu límite es de <b>${MAX_MB}MB</b>.${deluxeCTA}`);
              return;
            }
            upBtn.disabled = true; const old=upBtn.textContent; upBtn.textContent='Subiendo…';
            try{
              const r = await fetch('upload.php', {
                method:'POST',
                body:new FormData(up),
                headers:{ 'Accept':'application/json' }
              });
              if(!r.ok){
                if(r.status === 413){
                  msg(false, `<span>❌</span> Archivo demasiado grande (HTTP 413). Revisa <code>client_max_body_size</code> en Nginx y <code>upload_max_filesize/post_max_size</code> en PHP.`);
                }else{
                  const tx = await r.text();
                  msg(false, `<span>❌</span> Error del servidor (${r.status}). ${tx ? tx.replace(/</g,'&lt;') : ''}`);
                }
                return;
              }
              const text = await r.text();
              let j = null; try { j = JSON.parse(text); } catch {}
              if (!j) { msg(false, `<span>❌</span> Respuesta inesperada del servidor.`); return; }
              if (j.ok) {
                const url = j.file.url;
                msg(true, `
                  <span>✅</span>
                  <a class="url" href="${url}" target="_blank">${url}</a>
                  <button type="button" class="btn btn-sm" data-url="${url}">Copiar URL</button>
                `);
                if (j.warn) msg(true, `<span>ℹ️</span> ${j.warn}`);
                up.reset();
              } else {
                msg(false, `<span>❌</span> ${j.error || 'Error'}${deluxeCTA}`);
              }
            } catch(e){
              msg(false, `<span>❌</span> Error de red. Revisa conexión o límites del proxy/WAF.`);
            } finally{
              upBtn.disabled = false; upBtn.textContent = old;
            }
          });

          out.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-url]');
            if (!btn) return;
            const url = btn.dataset.url;
            try {
              await navigator.clipboard.writeText(url);
              const old = btn.textContent;
              btn.textContent = '¡Copiado!';
              setTimeout(()=>btn.textContent = old, 1200);
            } catch {
              alert('No se pudo copiar automáticamente. Copia manual:\n' + url);
            }
          });
        </script>
      <?php endif; ?>
    </div>
  </div>

  <?php
    // Mostrar botones PayPal sólo si hay client ID en settings
    $pp_cid  = setting_get('paypal_client_id','');
  ?>

  <?php if ($pp_cid): ?>
    <div id="payplans" class="card" style="margin-top:14px">
      <h3>Pagos (PayPal)</h3>
      <div class="plans">
        <div class="plan">
          <img src="https://cdn.russellxz.click/47d048e3.png" alt="">
          <div class="title">+50 archivos</div>
          <div class="price">$1.37</div>
          <div id="pp-plus50"></div>
        </div>
        <div class="plan">
          <img src="https://cdn.russellxz.click/47d048e3.png" alt="">
          <div class="title">+120 archivos</div>
          <div class="price">$2.45</div>
          <div id="pp-plus120"></div>
        </div>
        <div class="plan">
          <img src="https://cdn.russellxz.click/47d048e3.png" alt="">
          <div class="title">+250 archivos</div>
          <div class="price">$3.55</div>
          <div id="pp-plus250"></div>
        </div>
        <div class="plan">
          <img src="https://cdn.russellxz.click/47d048e3.png" alt="">
          <div class="title">Plan Deluxe</div>
          <div class="price">$5.00 (pago único)</div>
          <?php if((int)$me['is_deluxe']===1): ?>
            <div class="muted">Ya eres Deluxe 💎</div>
          <?php else: ?>
            <div id="pp-deluxe"></div>
          <?php endif; ?>
        </div>
      </div>

      <script src="https://www.paypal.com/sdk/js?client-id=<?=htmlspecialchars($pp_cid)?>&currency=USD&intent=capture&debug=true"></script>
      <script>
        function waitForPayPal(ms=8000){
          return new Promise((res, rej)=>{
            const t0 = Date.now();
            (function tick(){
              if (window.paypal && typeof paypal.Buttons === 'function') return res();
              if (Date.now() - t0 > ms) return rej(new Error('SDK de PayPal no cargó'));
              setTimeout(tick, 120);
            })();
          });
        }
        async function renderBtn(selector, plan){
          const cont = document.querySelector(selector);
          if (!cont) return;
          try{
            await waitForPayPal();
            paypal.Buttons({
              style: { layout:'vertical', shape:'pill', tagline:false },
              createOrder: async function() {
                const r = await fetch('paypal_create_order.php', {
                  method: 'POST',
                  headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
                  body: JSON.stringify({ plan }) // PLUS50 | PLUS120 | PLUS250 | DELUXE
                });
                if (!r.ok) {
                  const tx = await r.text().catch(()=> '');
                  alert('No se pudo crear la orden (HTTP '+r.status+').\n'+tx);
                  throw new Error('createOrder failed: '+r.status);
                }
                const d = await r.json().catch(()=> ({}));
                if (!d.id) { alert('Respuesta inválida al crear la orden.'); throw new Error('missing order id'); }
                return d.id;
              },
              onApprove: async function(data) {
                if (!data?.orderID) { alert('Falta orderID para capturar.'); return; }
                const r = await fetch('paypal_capture.php', {
                  method:'POST',
                  headers:{ 'Content-Type':'application/json', 'Accept':'application/json' },
                  body: JSON.stringify({ orderID: data.orderID })
                });
                const d = await r.json().catch(()=> ({}));
                if (r.ok && d.ok) {
                  let msg = '✅ Pago recibido.';
                  if (d.deluxe) msg += ' Plan Deluxe activado.';
                  else msg += ' Tu límite aumentó +'+(d.inc||0)+' archivos.';
                  alert(msg); location.reload();
                } else {
                  alert('❌ Error al capturar: '+(d.error || ('HTTP '+r.status)));
                }
              },
              onError: function(err){
                console.error('PayPal onError:', err);
                const msg = (err && (err.message || (err.toString && err.toString()))) || 'desconocido';
                alert('❌ Error con PayPal Buttons: '+msg);
              }
            }).render(selector);
          }catch(e){
            console.error('PayPal render catch:', e);
            alert('❌ No se pudo inicializar PayPal: '+(e.message||e));
          }
        }
        renderBtn('#pp-plus50','PLUS50');
        renderBtn('#pp-plus120','PLUS120');
        renderBtn('#pp-plus250','PLUS250');
        <?php if((int)$me['is_deluxe']!==1): ?>renderBtn('#pp-deluxe','DELUXE');<?php endif; ?>
      </script>
    </div>
  <?php elseif (!empty($me['is_admin'])): ?>
    <div class="card" style="margin-top:14px">
      <h3>Pagos (PayPal)</h3>
      <p class="muted">Configura PayPal en <a class="btn btn-sm ghost" href="admin_payments.php">Admin → Pagos</a> para mostrar los botones.</p>
    </div>
  <?php endif; ?>


  <!-- ====== Pagar con tarjeta (Stripe) — mismas medidas que PayPal ====== -->
  <div class="card" style="margin-top:14px">
    <h3>Pagar con tarjeta (Stripe)</h3>

    <style>
      /* Solo ajusta la línea de marca Stripe; el ancho/alto de tarjetas
         lo hereda de .plans .plan (el mismo CSS que usan las de PayPal) */
      .stripeMark{
        display:flex;align-items:center;justify-content:center;
        gap:6px;margin-top:8px;color:#9fb0c9;font-size:13px
      }
      .stripeMark img{height:18px;width:auto;display:inline-block;opacity:.95}
    </style>

    <?php
      // Mismos textos/precios que PayPal
      $stripePlans = [
        ['code'=>'PLUS50',  'title'=>'+50 archivos',  'price'=>'$1.37'],
        ['code'=>'PLUS120', 'title'=>'+120 archivos', 'price'=>'$2.45'],
        ['code'=>'PLUS250', 'title'=>'+250 archivos', 'price'=>'$3.55'],
      ];
      // Logos
      $logoSky    = 'https://cdn.skyultraplus.com/uploads/u3/2023398962d380d9.png';
      $logoStripe = 'https://cdn.skyultraplus.com/uploads/u3/9ebb61359445e3db.png';
    ?>

    <div class="plans"><!-- misma grilla que PayPal -->
      <?php foreach($stripePlans as $p): ?>
        <div class="plan"><!-- misma tarjeta que PayPal -->
          <img src="<?=htmlspecialchars($logoSky)?>" alt="Sky Ultra Plus"><!-- se escala igual que PayPal -->
          <div class="title"><?=htmlspecialchars($p['title'])?></div>
          <div class="price"><?=htmlspecialchars($p['price'])?></div>

          <a class="btn" href="stripe_checkout.php?plan=<?=urlencode($p['code'])?>">Pagar con tarjeta</a>

          <div class="stripeMark">
            <img src="<?=htmlspecialchars($logoStripe)?>" alt="Stripe">
            <span>Procesado por Stripe</span>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if ((int)($me['is_deluxe'] ?? 0) !== 1): ?>
        <div class="plan">
          <img src="<?=htmlspecialchars($logoSky)?>" alt="Sky Ultra Plus">
          <div class="title">Plan Deluxe</div>
          <div class="price">$5.00 (pago único)</div>

          <a class="btn" href="stripe_checkout.php?plan=DELUXE">Pagar con tarjeta</a>

          <div class="stripeMark">
            <img src="<?=htmlspecialchars($logoStripe)?>" alt="Stripe">
            <span>Procesado por Stripe</span>
          </div>
        </div>
      <?php else: ?>
        <div class="plan" style="opacity:.6">
          <img src="<?=htmlspecialchars($logoSky)?>" alt="Sky Ultra Plus">
          <div class="title">Plan Deluxe</div>
          <div class="price">$5.00 (pago único)</div>
          <div class="muted" style="margin-top:10px">Ya eres Deluxe 💎</div>

          <div class="stripeMark">
            <img src="<?=htmlspecialchars($logoStripe)?>" alt="Stripe">
            <span>Procesado por Stripe</span>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <!-- ====== /Stripe ====== -->

  <!-- ====== Soporte por WhatsApp (botón único; sin números visibles) ====== -->
  <div class="card" style="margin-top:14px">
    <h3>¿Necesitas ayuda?</h3>
    <p class="muted" style="margin:6px 0 12px">Escríbenos por WhatsApp.</p>
    <button class="btn" id="waOpen" type="button">💬 WhatsApp soporte</button>
  </div>

  <div class="wa-modal" id="waModal" aria-hidden="true" role="dialog" aria-labelledby="waTitle">
    <div class="wa-backdrop" id="waBackdrop"></div>
    <div class="wa-sheet">
      <div class="wa-top">
        <div class="wa-title" id="waTitle">Elige un agente</div>
        <button class="wa-close" id="waClose" type="button">✕</button>
      </div>
      <div class="wa-grid" id="waGrid"><!-- se rellena por JS --></div>
    </div>
  </div>
  <!-- ====== /Soporte WhatsApp ====== -->

</div> <!-- /.wrap -->

<script>
  // Ver/Ocultar API Key + copiar
  const keyCode = document.getElementById('apikey');
  const toggleBtn = document.getElementById('toggleKey');
  const copyBtn = document.getElementById('copyKey');
  toggleBtn?.addEventListener('click', ()=>{
    const showing = keyCode.dataset.show === '1';
    if(!showing){ keyCode.textContent = keyCode.dataset.full; keyCode.dataset.show='1'; toggleBtn.textContent='Ocultar'; }
    else{ keyCode.textContent = '••••••••••••••••••••••••'; keyCode.dataset.show='0'; toggleBtn.textContent='Ver'; }
  });
  copyBtn?.addEventListener('click', async ()=>{
    const v = keyCode.dataset.full || '';
    try{ await navigator.clipboard.writeText(v); copyBtn.textContent='¡Copiada!'; setTimeout(()=>copyBtn.textContent='Copiar API Key',1500);}catch(e){}
  });

  // ---------- Buscador rápido ----------
  const qInput = document.getElementById('q');
  const qBtn   = document.getElementById('qBtn');
  const qRes   = document.getElementById('qRes');
  const qHint  = document.getElementById('qHint');

  function esc(s){return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}

  let t=null, ctl=null;
  async function runSearch(){
    const q = qInput?.value?.trim() || '';
    if (ctl) ctl.abort(); ctl = new AbortController();
    try{
      qHint.textContent='Buscando…';
      const r = await fetch(`list.php?ajax=1&q=${encodeURIComponent(q)}`, {signal: ctl.signal});
      const j = await r.json();
      const items = (j.items||[]).slice(0,10);
      if(items.length===0){ qRes.innerHTML='<p class="muted">Sin resultados.</p>'; }
      else{
        qRes.innerHTML = items.map(it=>`
          <div class="r">
            <div class="name" title="${esc(it.name)}">${esc(it.name)}</div>
            <div class="muted" style="white-space:nowrap">${esc(it.size_fmt)} · ${esc(it.created_fmt)}</div>
            <div style="display:flex;gap:8px">
              <a class="btn" href="${esc(it.url)}" target="_blank">Abrir</a>
              <button class="btn" type="button" data-url="${esc(it.url)}">Copiar URL</button>
            </div>
          </div>
        `).join('');
      }
      qHint.textContent = q ? `Resultados para “${q}” · máx. 10` : 'Escribe para buscar';
    }catch(e){
      if (e.name!=='AbortError'){ qRes.innerHTML='<p class="muted">Error al buscar.</p>'; qHint.textContent='Intenta de nuevo.'; }
    }
  }
  function deb(){ clearTimeout(t); t=setTimeout(runSearch, 280); }
  qInput?.addEventListener('input', deb);
  qBtn?.addEventListener('click', runSearch);

  qRes?.addEventListener('click', async (e)=>{
    const b=e.target.closest('button[data-url]'); if(!b) return;
    try{ await navigator.clipboard.writeText(b.dataset.url);
      const old=b.textContent; b.textContent='¡Copiado!'; setTimeout(()=>b.textContent=old,1200);
    }catch{ alert('No se pudo copiar automáticamente.\n'+b.dataset.url); }
  });

  // ---------- WhatsApp soporte (modal) ----------
  (function(){
    const contacts = <?= json_encode($waContacts, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
    const msg = <?= json_encode('Hola, necesito soporte de SkyUltraPlus CDN' . ($fullName!=='' ? ' ('.$fullName.')' : '')) ?>;

    const modal   = document.getElementById('waModal');
    const grid    = document.getElementById('waGrid');
    const openBtn = document.getElementById('waOpen');
    const closeBtn= document.getElementById('waClose');
    const back    = document.getElementById('waBackdrop');

    function esc(s){return (''+s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]))}
    function waLink(digits){ return `https://wa.me/${digits}?text=`+encodeURIComponent(msg); }

    function render(){
      if (!grid) return;
      grid.innerHTML = contacts.map(c => `
        <div class="wa-card">
          <img class="wa-avatar" src="${esc(c.img)}" alt="${esc(c.name)}" width="56" height="56" loading="lazy">
          <div class="wa-info">
            <div class="wa-name">${esc(c.name)}</div>
            <div class="wa-actions">
              <a class="btn" href="${waLink(c.digits)}" target="_blank" rel="noopener">💬 Chatear</a>
            </div>
          </div>
        </div>
      `).join('');
    }

    function open(){ if(modal){ modal.style.display='flex'; modal.setAttribute('aria-hidden','false'); } }
    function close(){ if(modal){ modal.style.display='none'; modal.setAttribute('aria-hidden','true'); } }

    openBtn?.addEventListener('click', ()=>{ if(!grid.innerHTML) render(); open(); });
    closeBtn?.addEventListener('click', close);
    back?.addEventListener('click', close);
    document.addEventListener('keydown', (e)=>{ if(e.key==='Escape' && modal?.getAttribute('aria-hidden')==='false') close(); });
  })();
</script>
</body></html>
