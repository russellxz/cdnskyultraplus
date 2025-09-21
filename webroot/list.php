<?php
require_once __DIR__.'/db.php';
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid = (int)$_SESSION['uid'];

/* ==== Asegurar índice (MariaDB) para búsquedas rápidas ==== */
try {
  $ix = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = 'files'
                         AND INDEX_NAME  = 'files_userid_name'");
  $ix->execute();
  if ((int)$ix->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE files ADD INDEX files_userid_name (user_id, name(191))");
  }
} catch (Throwable $e) {}

/* ==== Helpers ==== */
function bytes_fmt($b){
  $b = (float)$b; $u=['B','KB','MB','GB','TB']; $i=0;
  while($b>=1024 && $i<count($u)-1){ $b/=1024; $i++; }
  return ($b>=10?round($b):round($b,1)).' '.$u[$i];
}
function dt_fmt($ts){
  if (!$ts) return '';
  $t = strtotime($ts); if ($t<=0) return $ts;
  return date('Y-m-d H:i', $t);
}
function json_out($arr, $code=200){
  header('Content-Type: application/json; charset=utf-8');
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

/* ==== Endpoint eliminar archivo ==== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='delete') {
  $fid = (int)($_POST['id'] ?? 0);
  try {
    $st = $pdo->prepare("SELECT path FROM files WHERE id=? AND user_id=? LIMIT 1");
    $st->execute([$fid, $uid]);
    $path = $st->fetchColumn();
    if ($path && is_file($path)) @unlink($path);
    $pdo->prepare("DELETE FROM files WHERE id=? AND user_id=?")->execute([$fid, $uid]);
    json_out(['ok'=>true]);
  } catch(Throwable $e) {
    json_out(['ok'=>false,'error'=>'Error al eliminar'],500);
  }
}

/* ==== Endpoint AJAX (list.php?ajax=1&q=...) ==== */
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
  $q = trim($_GET['q'] ?? '');
  try {
    if ($q === '') {
      $st = $pdo->prepare("SELECT id,name,url,size_bytes,created_at
                           FROM files
                           WHERE user_id=?
                           ORDER BY id DESC
                           LIMIT 20");
      $st->execute([$uid]);
    } else {
      $like = '%'.$q.'%';
      $st = $pdo->prepare("SELECT id,name,url,size_bytes,created_at
                           FROM files
                           WHERE user_id=?
                             AND (name LIKE ? OR url LIKE ?)
                           ORDER BY id DESC
                           LIMIT 20");
      $st->execute([$uid, $like, $like]);
    }
    $rows = $st->fetchAll();
    $items = [];
    foreach ($rows as $r) {
      $items[] = [
        'id'          => (int)$r['id'],
        'name'        => (string)$r['name'],
        'url'         => (string)$r['url'],
        'size_fmt'    => bytes_fmt($r['size_bytes'] ?? 0),
        'created_fmt' => dt_fmt($r['created_at'] ?? ''),
      ];
    }
    json_out(['ok'=>true,'items'=>$items]);
  } catch (Throwable $e) {
    json_out(['ok'=>false, 'error'=>'Error en la búsqueda'], 500);
  }
}

/* ==== Render HTML (Ver mis archivos) ==== */
$st = $pdo->prepare("SELECT email,username,first_name,last_name,is_admin,is_deluxe,verified,quota_limit,api_key FROM users WHERE id=?");
$st->execute([$uid]);
$me = $st->fetch();
if (!$me) { header('Location: logout.php'); exit; }
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mis archivos — CDN SkyUltraPlus</title>
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
  .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
  a{color:#93c5fd}
  .results{display:grid;gap:8px;margin-top:10px}
  .r{display:grid;grid-template-columns:1fr auto auto;gap:8px;align-items:center;background:#0f172a;border:1px solid #334155;border-radius:10px;padding:8px}
  .r .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .muted{color:#9fb0c9}
</style>
</head>
<body>
<div class="wrap">
  <h2>📁 Mis archivos</h2>
  <div style="margin-bottom:10px;display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn" href="profile.php">⬅️ Volver al panel</a>
    <a class="btn" href="settings.php">👤 Perfil</a>
  </div>

  <div class="card">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input id="q" class="input" placeholder="Buscar por nombre o URL…" autocomplete="off" style="flex:1 1 320px">
      <button class="btn" id="qBtn" type="button">Buscar</button>
      <span class="muted" id="qHint">Escribe para buscar. Se muestran hasta 20 resultados.</span>
    </div>
    <div class="results" id="qRes"></div>
  </div>
</div>

<script>
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
      if (!r.ok) {
        qRes.innerHTML = '<p class="muted">Error del servidor al buscar.</p>';
        qHint.textContent = 'Intenta de nuevo.';
        return;
      }
      const j = await r.json();
      const items = (j.items||[]);
      if(items.length===0){
        qRes.innerHTML='<p class="muted">Sin resultados.</p>';
      }else{
        qRes.innerHTML = items.map(it=>`
          <div class="r">
            <div class="name" title="${esc(it.name)}">${esc(it.name)}</div>
            <div class="muted" style="white-space:nowrap">${esc(it.size_fmt)} · ${esc(it.created_fmt)}</div>
            <div style="display:flex;gap:8px">
              <a class="btn" href="${esc(it.url)}" target="_blank">Abrir</a>
              <button class="btn ghost" type="button" data-url="${esc(it.url)}">Copiar URL</button>
              <button class="btn" type="button" data-del="${it.id}">Eliminar</button>
            </div>
          </div>
        `).join('');
      }
      qHint.textContent = q ? `Resultados para “${q}” · máx. 20` : 'Escribe para buscar. Se muestran hasta 20 resultados.';
    }catch(e){
      if (e.name!=='AbortError'){
        qRes.innerHTML='<p class="muted">Error al buscar.</p>';
        qHint.textContent='Intenta de nuevo.';
      }
    }
  }
  function deb(){ clearTimeout(t); t=setTimeout(runSearch, 250); }
  qInput?.addEventListener('input', deb);
  qBtn?.addEventListener('click', runSearch);

  // Copiar URL
  qRes.addEventListener('click', async (e)=>{
    const b=e.target.closest('button[data-url]');
    if(b){
      try{
        await navigator.clipboard.writeText(b.dataset.url||'');
        const old=b.textContent; b.textContent='¡Copiada!'; setTimeout(()=>b.textContent=old,1200);
      }catch{
        alert('No se pudo copiar automáticamente.\n'+(b.dataset.url||''));
      }
      return;
    }
    const d=e.target.closest('button[data-del]');
    if(d){
      if(!confirm("¿Seguro que quieres eliminar este archivo?")) return;
      const id=d.dataset.del;
      const r=await fetch('list.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=delete&id='+encodeURIComponent(id)
      });
      const j=await r.json();
      if(j.ok){
        d.closest('.r').remove();
      }else{
        alert("Error: "+(j.error||"no se pudo eliminar"));
      }
    }
  });

  runSearch();
</script>
</body>
</html>
