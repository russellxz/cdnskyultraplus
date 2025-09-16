<?php
require_once __DIR__.'/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid=(int)$_SESSION['uid'];

/* helpers */
function h($s){ return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }
function bytes_fmt($b){
  $u=['B','KB','MB','GB']; $i=0; $b=(float)$b;
  while($b>=1024 && $i<count($u)-1){ $b/=1024; $i++; }
  return ($b>=10?round($b):round($b,1)).' '.$u[$i];
}
function fmt_date($ts){ return date('d/m/Y H:i', strtotime($ts ?: 'now')); }

/* --- endpoint AJAX: /gallery.php?ajax=1&q=... --- */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  $q = trim((string)($_GET['q'] ?? ''));
  try{
    if ($q !== '') {
      $needle = strtolower($q);
      $sql = "SELECT id,name,url,size,mime,created_at
              FROM files
              WHERE user_id=?
                AND (mime LIKE 'image/%' OR LOWER(url) REGEXP '\\\\.(png|jpe?g|gif|webp|svg)$')
                AND (INSTR(LOWER(name), ?) > 0 OR INSTR(LOWER(url), ?) > 0)
              ORDER BY id DESC
              LIMIT 500";
      $st = $pdo->prepare($sql);
      $st->execute([$uid, $needle, $needle]);
    } else {
      $st = $pdo->prepare("SELECT id,name,url,size,mime,created_at
                           FROM files
                           WHERE user_id=? AND (mime LIKE 'image/%' OR LOWER(url) REGEXP '\\\\.(png|jpe?g|gif|webp|svg)$')
                           ORDER BY id DESC
                           LIMIT 120");
      $st->execute([$uid]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $items = array_map(function($r){
      return [
        'id' => (int)$r['id'],
        'name' => (string)($r['name'] ?? ''),
        'url' => (string)($r['url'] ?? ''),
        'size_fmt' => bytes_fmt((int)($r['size'] ?? 0)),
        'created_fmt' => fmt_date($r['created_at'] ?? 'now'),
      ];
    }, $rows ?: []);
    echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }catch(Throwable $e){
    echo json_encode(['ok'=>false,'error'=>'Error al buscar'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

/* --- SSR inicial (muestra algo sin JS) --- */
try{
  $st=$pdo->prepare("SELECT id,name,url,size,mime,created_at
                     FROM files
                     WHERE user_id=? AND (mime LIKE 'image/%' OR LOWER(url) REGEXP '\\\\.(png|jpe?g|gif|webp|svg)$')
                     ORDER BY id DESC
                     LIMIT 60");
  $st->execute([$uid]);
  $initial = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}catch(Throwable $e){ $initial=[]; }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi galería — SkyUltraPlus</title>
<style>
  :root{ --txt:#eaf2ff; --muted:#9fb0c9; --stroke:#334155; --card:#0f172a; --g1:#0ea5e9; --g2:#22d3ee; }
  *{box-sizing:border-box}
  body{
    margin:0;font:15px/1.6 system-ui;color:var(--txt);
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
  .tile{
    position:relative;overflow:hidden;border-radius:12px;border:1px solid #26324a;background:#0b1324
  }
  .thumb{
    width:100%; height:220px; object-fit:cover; display:block; background:#0d1830;
  }
  .meta{
    display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px
  }
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
      <div class="muted">Vista de todas tus <b>imágenes</b> subidas.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn" href="profile.php">⬅️ Volver</a>
      <a class="btn ghost" href="list.php">📁 Ver lista completa</a>
    </div>
  </div>

  <div class="search">
    <input id="q" class="input" placeholder="Buscar por nombre o parte de la URL…" autocomplete="off">
    <button class="btn" id="qBtn" type="button">Buscar</button>
  </div>
  <p class="muted" id="hint" style="margin-top:6px">Se muestran hasta 120 resultados.</p>

  <div id="grid" class="grid">
    <?php foreach ($initial as $it): ?>
      <div class="card">
        <div class="tile">
          <img class="thumb" src="<?=h($it['url'])?>" alt="<?=h($it['name'])?>" loading="lazy">
        </div>
        <div class="meta">
          <div class="name" title="<?=h($it['name'])?>"><?=h($it['name'])?></div>
          <div class="small"><?=h(bytes_fmt($it['size'] ?? 0))?></div>
        </div>
        <div class="small"><?=h(fmt_date($it['created_at'] ?? 'now'))?></div>
        <div class="actions">
          <a class="btn" href="<?=h($it['url'])?>" target="_blank" rel="noopener">Abrir</a>
          <button class="btn ghost" type="button" data-url="<?=h($it['url'])?>">Copiar URL</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
  const grid = document.getElementById('grid');
  const hint = document.getElementById('hint');
  const q    = document.getElementById('q');
  const qBtn = document.getElementById('qBtn');
  let ctl=null, t=null;

  function esc(s){return (s||'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
  function cardHtml(it){
    return `
      <div class="card">
        <div class="tile">
          <img class="thumb" src="${esc(it.url)}" alt="${esc(it.name)}" loading="lazy">
        </div>
        <div class="meta">
          <div class="name" title="${esc(it.name)}">${esc(it.name)}</div>
          <div class="small">${esc(it.size_fmt)}</div>
        </div>
        <div class="small">${esc(it.created_fmt)}</div>
        <div class="actions">
          <a class="btn" href="${esc(it.url)}" target="_blank" rel="noopener">Abrir</a>
          <button class="btn ghost" type="button" data-url="${esc(it.url)}">Copiar URL</button>
        </div>
      </div>`;
  }

  async function runSearch(){
    const s = q.value.trim();
    if (ctl) ctl.abort(); ctl = new AbortController();
    hint.textContent='Buscando…';
    try{
      const r = await fetch('gallery.php?ajax=1&q='+encodeURIComponent(s), {signal: ctl.signal});
      const j = await r.json();
      const items = j.items||[];
      grid.innerHTML = items.length ? items.map(cardHtml).join('') : '<p class="muted">Sin resultados.</p>';
      hint.textContent = s ? `Resultados para “${s}” · ${items.length}` : 'Se muestran hasta 120 resultados.';
    }catch(e){
      if (e.name!=='AbortError'){ grid.innerHTML='<p class="muted">Error al buscar.</p>'; hint.textContent='Intenta de nuevo.'; }
    }
  }
  function deb(){ clearTimeout(t); t=setTimeout(runSearch, 280); }
  q.addEventListener('input', deb);
  qBtn.addEventListener('click', runSearch);

  grid.addEventListener('click', async (e)=>{
    const b=e.target.closest('button[data-url]'); if(!b) return;
    try{ await navigator.clipboard.writeText(b.dataset.url);
      const old=b.textContent; b.textContent='¡Copiada!'; setTimeout(()=>b.textContent=old,1200);
    }catch{ alert('No se pudo copiar automáticamente.\n'+b.dataset.url); }
  });
</script>
</body>
</html>
