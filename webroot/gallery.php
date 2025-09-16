<?php
require_once __DIR__.'/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid=(int)$_SESSION['uid'];

function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }
function ext_from($s){
  $e = strtolower(pathinfo((string)$s, PATHINFO_EXTENSION));
  return $e ?: '';
}
function infer_type($name,$url){
  $t = strtolower($name.' '.$url);
  foreach (['.png','.jpg','.jpeg','.webp','.gif','.svg'] as $e) if (strpos($t,$e)!==false) return 'image';
  foreach (['.mp4','.webm','.mov','.m4v'] as $e) if (strpos($t,$e)!==false) return 'video';
  foreach (['.mp3','.aac','.ogg','.wav'] as $e) if (strpos($t,$e)!==false) return 'audio';
  return 'other';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi galería — SkyUltraPlus</title>
<style>
  :root{ --txt:#eaf2ff; --muted:#9fb0c9; --stroke:#334155; --g1:#0ea5e9; --g2:#22d3ee; }
  *{box-sizing:border-box}
  body{
    margin:0;font:15px/1.6 system-ui,color:var(--txt);
    color:var(--txt);
    background:
      radial-gradient(800px 500px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
      radial-gradient(800px 500px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
      linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%);
  }
  a{color:#93c5fd;text-decoration:none}
  .wrap{max-width:1100px;margin:0 auto;padding:20px}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
  .btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(90deg,var(--g1),var(--g2));color:#051425;border:none;border-radius:10px;padding:9px 14px;font-weight:800;text-decoration:none}
  .btn.ghost{background:transparent;color:var(--txt);border:1px solid var(--stroke)}
  .input{width:100%;padding:10px;border-radius:10px;border:1px solid var(--stroke);background:#0f172a;color:var(--txt)}
  .muted{color:var(--muted)}
  h1{margin:6px 0 10px;font-size:26px;line-height:1.2}

  .search{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:10px}
  .grid{display:grid;gap:12px;margin-top:14px}
  @media(min-width:700px){ .grid{grid-template-columns:repeat(2,1fr)} }
  @media(min-width:1020px){ .grid{grid-template-columns:repeat(3,1fr)} }
  @media(min-width:1320px){ .grid{grid-template-columns:repeat(4,1fr)} }

  .card{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:12px}
  .tile{position:relative;overflow:hidden;border-radius:12px;border:1px solid #26324a;background:#0b1324}
  .thumb{width:100%; height:220px; object-fit:cover; display:block; background:#0d1830;}
  .thumb-audio, .thumb-file{
    display:flex;align-items:center;justify-content:center;
    font-size:42px;height:220px; text-transform:uppercase; letter-spacing:.5px; color:#c6d4ff;
  }
  .meta{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px}
  .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .small{font-size:12px;color:var(--muted)}
  .actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
  .actions .btn{border-radius:8px;padding:7px 10px}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <h1>Mi galería</h1>
      <div class="muted">Usa la misma búsqueda de <b>Mis archivos</b>. Verás imágenes, videos, audio y más.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn" href="profile.php">⬅️ Volver</a>
      <a class="btn ghost" href="list.php">📁 Ver lista</a>
    </div>
  </div>

  <div class="search">
    <input id="q" class="input" placeholder="Buscar por nombre o parte de la URL…" autocomplete="off">
    <button class="btn" id="qBtn" type="button">Buscar</button>
  </div>
  <p class="muted" id="hint" style="margin-top:6px">Se muestran los últimos 20 o los 20 que coincidan con tu búsqueda.</p>

  <div id="grid" class="grid"><p class="muted">Cargando…</p></div>
</div>

<script>
  const ENDPOINT = 'list.php?ajax=1'; // usa EXACTAMENTE el mismo endpoint de búsqueda
  const grid = document.getElementById('grid');
  const hint = document.getElementById('hint');
  const q    = document.getElementById('q');
  const qBtn = document.getElementById('qBtn');
  let ctl=null, t=null;

  function esc(s){return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
  function inferType(name,url){
    const t = (name+' '+url).toLowerCase();
    const has = (arr)=>arr.some(e=>t.includes(e));
    if (has(['.png','.jpg','.jpeg','.webp','.gif','.svg'])) return 'image';
    if (has(['.mp4','.webm','.mov','.m4v'])) return 'video';
    if (has(['.mp3','.aac','.ogg','.wav'])) return 'audio';
    return 'other';
  }
  function extFrom(s){
    const m = /\.([a-z0-9]{1,8})(?:\?|#|$)/i.exec(s||'');
    return (m?m[1]:'').toLowerCase();
  }
  function cardHtml(it){
    const type = inferType(it.name||'', it.url||'');
    const ext  = extFrom(it.url||'') || extFrom(it.name||'') || 'file';
    let media = '';
    if (type==='image'){
      media = `<img class="thumb" src="${esc(it.url)}" alt="${esc(it.name)}" loading="lazy">`;
    } else if (type==='video'){
      media = `<video class="thumb" src="${esc(it.url)}" preload="metadata" playsinline muted controls></video>`;
    } else if (type==='audio'){
      media = `<div class="thumb thumb-audio">🎵</div>`;
    } else {
      media = `<div class="thumb thumb-file">${esc(ext)}</div>`;
    }
    return `
      <div class="card">
        <div class="tile">${media}</div>
        <div class="meta">
          <div class="name" title="${esc(it.name)}">${esc(it.name)}</div>
          <div class="small">${esc(it.size_fmt||'')}</div>
        </div>
        <div class="small">${esc(it.created_fmt||'')}</div>
        <div class="actions">
          <a class="btn" href="${esc(it.url)}" target="_blank" rel="noopener">Abrir</a>
          ${type==='audio' ? `<audio src="${esc(it.url)}" controls style="width:100%;margin-top:6px"></audio>` : ''}
          <button class="btn ghost" type="button" data-url="${esc(it.url)}">Copiar URL</button>
        </div>
      </div>`;
  }

  async function runSearch(){
    const s = q.value.trim();
    if (ctl) ctl.abort(); ctl = new AbortController();
    hint.textContent='Buscando…';
    try{
      const url = ENDPOINT + (s?('&q='+encodeURIComponent(s)):'');
      const r = await fetch(url, {signal: ctl.signal});
      if (!r.ok){ grid.innerHTML='<p class="muted">Error del servidor.</p>'; hint.textContent='Intenta de nuevo.'; return; }
      const j = await r.json();
      const items = j.items||[];
      grid.innerHTML = items.length ? items.map(cardHtml).join('') : '<p class="muted">Sin resultados.</p>';
      hint.textContent = s ? `Resultados para “${s}” · ${items.length}` : 'Se muestran los últimos 20 archivos.';
    }catch(e){
      if (e.name!=='AbortError'){ grid.innerHTML='<p class="muted">Error al buscar.</p>'; hint.textContent='Intenta de nuevo.'; }
    }
  }
  function deb(){ clearTimeout(t); t=setTimeout(runSearch, 250); }
  q.addEventListener('input', deb);
  qBtn.addEventListener('click', runSearch);

  grid.addEventListener('click', async (e)=>{
    const b=e.target.closest('button[data-url]'); if(!b) return;
    try{ await navigator.clipboard.writeText(b.dataset.url||''); const old=b.textContent; b.textContent='¡Copiada!'; setTimeout(()=>b.textContent=old,1200); }catch{}
  });

  // Carga inicial: mismos “últimos 20” que list.php cuando q=''
  runSearch();
</script>
</body>
</html>
