<?php
require_once __DIR__.'/db.php';
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid=(int)$_SESSION['uid'];

$st=$pdo->prepare("SELECT email,username,first_name,last_name,is_admin,is_deluxe,verified,quota_limit,api_key FROM users WHERE id=?");
$st->execute([$uid]); $me=$st->fetch();

$c=$pdo->prepare("SELECT COUNT(*) c FROM files WHERE user_id=?"); $c->execute([$uid]);
$used=(int)$c->fetch()['c']; $remain=max(0,(int)$me['quota_limit']-$used);

// Índice (por si no existe) para que el buscador sea rápido
$pdo->exec("CREATE INDEX IF NOT EXISTS files_userid_name ON files(user_id, name)");

// Límite por plan
$maxMB = ((int)$me['is_deluxe'] === 1) ? SIZE_LIMIT_DELUXE_MB : SIZE_LIMIT_FREE_MB;

// WhatsApp helper
function wa_link($plan){ global $me;
  $msg="Hola, quiero comprar el plan $plan para mi CDN (usuario: ".$me['email'].")";
  return WHATSAPP_URL.'?text='.urlencode($msg);
}
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
</style>
</head><body><div class="wrap">

  <!-- HERO -->
  <div class="hero">
    <img class="logo" src="https://cdn.russellxz.click/47d048e3.png" alt="Sky Ultra Plus">
    <h1>Bienvenido a SkyUltraPlus CDN</h1>
  </div>

  <!-- ESTADO -->
  <div class="card">
    <div class="topline">
      <div>
        <div>Hola, <b><?=htmlspecialchars(trim($me['first_name'].' '.$me['last_name']))?></b></div>
        <div style="margin-top:6px">
          Estado: <?= ((int)$me['is_deluxe']===1)?'💎 <b>Deluxe</b>':'Estándar' ?> <?= $me['is_admin']?'<span class="bad">Admin</span>':'' ?>
        </div>
      </div>
      <div><a href="logout.php">Salir</a></div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px">
      <span class="pill">API Key: <code id="apikey"><?=htmlspecialchars($me['api_key'])?></code></span>
      <button class="pill grad" id="copyKey">Copiar API Key</button>
      <span class="pill grad">Disponibles: <b><?=$remain?></b></span>
      <span class="pill">Límite por archivo: <b><?=$maxMB?>MB</b></span>
    </div>

    <?php if((int)$me['is_deluxe']!==1): ?>
      <p class="muted" style="margin:10px 0 0">
        Cuentas normales: máximo <?=SIZE_LIMIT_FREE_MB?>MB por archivo. Con <b>Deluxe</b> subes hasta <?=SIZE_LIMIT_DELUXE_MB?>MB por archivo (<?= (int)$me['is_deluxe']===1?'activo':'$2.50/mes' ?>).
      </p>
    <?php endif; ?>

    <p style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px">
      <a class="btn" href="list.php">📁 Ver todos mis archivos</a>
      <a class="btn" href="settings.php">👤 Perfil / Configuración</a>
      <?php if($me['is_admin']): ?><a class="btn" href="admin.php">🛠️ Panel Admin</a><?php endif; ?>
    </p>
  </div>

  <!-- SUBIR -->
  <div class="row row2" style="margin-top:14px">
    <div class="card">
      <h3>Subir archivo</h3>
      <?php if(!(int)$me['verified']): ?>
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
          const deluxeCTA = <?= ((int)$me['is_deluxe']===1) ? '""' : '" <a class=\\"btn btn-sm\\" href=\''.wa_link('Deluxe ($2.50/mes)').'\' target=\\"_blank\\">Plan Deluxe</a>"' ?>;

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
              const r = await fetch('upload.php', { method:'POST', body:new FormData(up) });
              if(!r.ok){
                if(r.status === 413){
                  msg(false, `<span>❌</span> Archivo demasiado grande (HTTP 413). Revisa <code>client_max_body_size</code> y <code>upload_max_filesize/post_max_size</code>.`);
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

  <!-- PLANES (debajo) -->
  <div class="card" style="margin-top:14px">
    <h3>Planes y mejoras</h3>
    <div class="plans">
      <div class="plan">
        <img src="https://cdn.russellxz.click/47d048e3.png" alt="">
        <div class="title">+50 archivos</div>
        <div class="price">$1.37</div>
        <div class="muted">Aumenta tu límite en 50 archivos.</div>
        <a class="btn" href="<?=wa_link('+50 archivos ($1.37)')?>" target="_blank">Hablar por WhatsApp</a>
      </div>
      <div class="plan">
        <img src="https://cdn.russellxz.click/47d048e3.png" alt="">
        <div class="title">+120 archivos</div>
        <div class="price">$2.45</div>
        <div class="muted">Aumenta tu límite en 120 archivos.</div>
        <a class="btn" href="<?=wa_link('+120 archivos ($2.45)')?>" target="_blank">Hablar por WhatsApp</a>
      </div>
      <div class="plan">
        <img src="https://cdn.russellxz.click/47d048e3.png" alt="">
        <div class="title">+250 archivos</div>
        <div class="price">$3.55</div>
        <div class="muted">Aumenta tu límite en 250 archivos.</div>
        <a class="btn" href="<?=wa_link('+250 archivos ($3.55)')?>" target="_blank">Hablar por WhatsApp</a>
      </div>
      <div class="plan">
        <img src="https://cdn.russellxz.click/47d048e3.png" alt="">
        <div class="title">Plan Deluxe</div>
        <div class="price">$2.50 / mes</div>
        <div class="muted">Sube hasta <?=SIZE_LIMIT_DELUXE_MB?>MB por archivo.</div>
        <a class="btn" href="<?=wa_link('Deluxe ($2.50/mes)')?>" target="_blank">Hablar por WhatsApp</a>
      </div>
    </div>
  </div>

  <?php
    // --- PayPal: mostrar botones sólo si está configurado ---
    $pp_cid  = setting_get('paypal_client_id','');   // viene de Admin → Pagos
    $pp_mode = setting_get('paypal_mode','sandbox'); // sandbox | live (no lo usamos aquí, pero queda por si acaso)
  ?>

  <?php if ($pp_cid): ?>
    <div class="card" style="margin-top:14px">
      <h3>Pagos automáticos (PayPal)</h3>
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
      </div>

      <script src="https://www.paypal.com/sdk/js?client-id=<?=htmlspecialchars($pp_cid)?>&currency=USD&intent=capture"></script>
      <script>
        function renderBtn(selector, plan){
          const cont = document.querySelector(selector);
          if (!cont) return;

          paypal.Buttons({
            style: { layout:'vertical', shape:'pill', tagline:false },
            createOrder: async function() {
              const r = await fetch('paypal_create_order.php', {
                method: 'POST',
                headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
                body: JSON.stringify({ plan })
              });
              if (!r.ok) {
                const tx = await r.text().catch(()=> '');
                alert('No se pudo crear la orden ('+r.status+').\n'+tx);
                throw new Error('createOrder failed');
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
              if (r.ok && d.ok) { alert('✅ Pago recibido. Tu límite aumentó +'+(d.inc||0)+' archivos.'); location.reload(); }
              else { alert('❌ Error al capturar: '+(d.error || ('HTTP '+r.status))); }
            },
            onError: function(err){ console.error(err); alert('❌ Error con PayPal Buttons. Reintenta.'); }
          }).render(selector);
        }
        renderBtn('#pp-plus50','plus50');
        renderBtn('#pp-plus120','plus120');
        renderBtn('#pp-plus250','plus250');
      </script>
    </div>
  <?php elseif ((int)$me['is_admin'] === 1): ?>
    <div class="card" style="margin-top:14px">
      <h3>Pagos automáticos (PayPal)</h3>
      <p class="muted">Configura PayPal en <a href="admin_payments.php">Admin → Pagos</a> para mostrar los botones.</p>
    </div>
  <?php endif; ?>

</div>

<script>
  // Copiar API Key
  const copyBtn=document.getElementById('copyKey');
  copyBtn?.addEventListener('click',async()=>{
    const t=document.getElementById('apikey')?.innerText||'';
    try{ await navigator.clipboard.writeText(t); copyBtn.textContent='¡Copiada!'; setTimeout(()=>copyBtn.textContent='Copiar API Key',1500);}catch(e){}
  });

  // ---------- Buscador rápido ----------
  const qInput = document.getElementById('q');
  const qBtn   = document.getElementById('qBtn');
  const qRes   = document.getElementById('qRes');
  const qHint  = document.getElementById('qHint');

  function esc(s){return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}

  let t=null, ctl=null;
  async function runSearch(){
    const q = qInput.value.trim();
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

  qRes.addEventListener('click', async (e)=>{
    const b=e.target.closest('button[data-url]'); if(!b) return;
    try{ await navigator.clipboard.writeText(b.dataset.url);
      const old=b.textContent; b.textContent='¡Copiado!'; setTimeout(()=>b.textContent=old,1200);
    }catch{ alert('No se pudo copiar automáticamente.\n'+b.dataset.url); }
  });
</script>
</body></html>
