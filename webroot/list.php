<?php
require_once __DIR__.'/db.php';
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];

/* ---------- Utilidades ---------- */
function fmt_size($b){
  $b = (int)$b;
  if ($b>=1073741824) return round($b/1073741824,2).' GB';
  if ($b>=1048576)   return round($b/1048576,2).' MB';
  if ($b>=1024)      return round($b/1024,2).' KB';
  return $b.' B';
}

/* Índice para acelerar búsquedas (idempotente) */
$pdo->exec("CREATE INDEX IF NOT EXISTS files_userid_name ON files(user_id, name)");

/* ---------- Rama AJAX: /list.php?ajax=1&q=... ---------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  header('Content-Type: application/json; charset=utf-8');
  $q = trim($_GET['q'] ?? '');
  $limit = 200;

  if ($q === '') {
    $st = $pdo->prepare("SELECT id,name,url,created_at,size_bytes
                         FROM files WHERE user_id=? ORDER BY id DESC LIMIT $limit");
    $st->execute([$uid]);
  } else {
    // Escapar comodines para LIKE
    $like = "%".str_replace(['%','_','\\'], ['\\%','\\_','\\\\'], $q)."%";
    $st = $pdo->prepare("SELECT id,name,url,created_at,size_bytes
                         FROM files
                         WHERE user_id=? AND (name LIKE ? ESCAPE '\\' OR url LIKE ? ESCAPE '\\')
                         ORDER BY id DESC LIMIT $limit");
    $st->execute([$uid, $like, $like]);
  }

  $items = [];
  foreach ($st->fetchAll() as $r) {
    $items[] = [
      'id'          => (int)$r['id'],
      'name'        => (string)$r['name'],
      'url'         => (string)$r['url'],
      'created_fmt' => date('Y-m-d H:i', strtotime($r['created_at'])),
      'size_fmt'    => fmt_size($r['size_bytes']),
    ];
  }
  echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

/* ---------- HTML normal (el listado lo llena JS por AJAX) ---------- */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mis archivos — CDN SkyUltraPlus</title>
<style>
  body{
    margin:0;font:15px/1.6 system-ui;color:#eaf2ff;
    background:
      radial-gradient(700px 400px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
      radial-gradient(700px 400px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
      linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%);
  }
  .wrap{max-width:900px;margin:0 auto;padding:20px}
  a{color:#93c5fd;text-decoration:none}
  .btn{
    display:inline-flex;align-items:center;gap:6px;
    background:linear-gradient(90deg,#0ea5e9,#22d3ee);
    color:#051425;border:none;border-radius:10px;padding:8px 12px;
    font-weight:800;cursor:pointer
  }
  .btn.ghost{background:transparent;border:1px solid rgba(255,255,255,.25);color:#eaf2ff}
  .input{
    flex:1;min-width:220px;padding:10px;border-radius:10px;
    border:1px solid #334155;background:#0f172a;color:#eaf2ff
  }

  .card{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:14px}
  .top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
  .muted{color:#9fb0c9}

  .searchbar{display:flex;gap:8px;align-items:center;margin-top:10px}
  .list{display:grid;gap:10px;margin-top:12px}
  .item{display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;
        background:#0f172a;border:1px solid #334155;border-radius:10px;padding:10px}
  .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <h2 style="margin:0">📁 Mis archivos</h2>
    <a class="btn ghost" href="profile.php">Volver</a>
  </div>

  <div class="card">
    <form class="searchbar" onsubmit="event.preventDefault(); doSearch();">
      <input id="q" class="input" placeholder="Buscar… (nombre o parte de la URL)">
      <button class="btn" type="submit">Buscar</button>
      <button class="btn ghost" type="button" id="clearBtn" title="Limpiar">Limpiar</button>
    </form>

    <p class="muted" id="hint" style="margin:8px 0 0">Escribe para buscar. Mostrando máx. 200 resultados.</p>
    <div class="list" id="list"></div>
  </div>

</div>

<script>
  const qInput  = document.getElementById('q');
  const listEl  = document.getElementById('list');
  const hintEl  = document.getElementById('hint');
  const clearBtn= document.getElementById('clearBtn');

  // Renderiza resultados
  function render(items){
    if(!items || items.length===0){
      listEl.innerHTML = '<p class="muted" style="margin:8px 0 0">Sin resultados.</p>';
      return;
    }
    const esc = s => (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    listEl.innerHTML = items.map(it => `
      <div class="item">
        <div class="name" title="${esc(it.name)}">${esc(it.name)}</div>
        <div class="muted" style="white-space:nowrap">${esc(it.size_fmt)} · ${esc(it.created_fmt)}</div>
        <div style="display:flex;gap:8px">
          <a class="btn" href="${esc(it.url)}" target="_blank">Abrir</a>
          <button class="btn" type="button" data-url="${esc(it.url)}">Copiar URL</button>
        </div>
      </div>
    `).join('');
  }

  // Copiar URL (delegación)
  listEl.addEventListener('click', async (e)=>{
    const b = e.target.closest('button[data-url]');
    if(!b) return;
    try{
      await navigator.clipboard.writeText(b.dataset.url);
      const old=b.textContent; b.textContent='¡Copiado!'; setTimeout(()=>b.textContent=old,1200);
    }catch{ alert('No se pudo copiar automáticamente.\n'+b.dataset.url); }
  });

  // Búsqueda AJAX con debounce y cancelación
  let timer=null, ctrl=null;
  async function doSearch(){
    const q = qInput.value.trim();
    if (ctrl) ctrl.abort();
    ctrl = new AbortController();
    try{
      hintEl.textContent = 'Buscando…';
      const r = await fetch(`list.php?ajax=1&q=${encodeURIComponent(q)}`, {signal: ctrl.signal});
      const j = await r.json();
      if(!j.ok) throw new Error('Respuesta inválida');
      render(j.items);
      hintEl.textContent = (q ? `Resultados para “${q}”` : 'Últimos archivos') + ' · máx. 200';
    }catch(e){
      if (e.name === 'AbortError') return; // búsqueda anterior cancelada
      listEl.innerHTML = `<p class="muted">Error al buscar.</p>`;
      hintEl.textContent = 'Intenta de nuevo.';
    }
  }
  function debounced(){
    clearTimeout(timer);
    timer = setTimeout(doSearch, 280);
  }

  qInput.addEventListener('input', debounced);
  clearBtn.addEventListener('click', ()=>{
    qInput.value=''; doSearch();
  });

  // primera carga
  doSearch();
</script>
</body>
</html>
