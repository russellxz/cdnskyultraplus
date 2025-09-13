<?php
require_once __DIR__.'/db.php';
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid=(int)$_SESSION['uid'];
$st=$pdo->prepare("SELECT name,url,created_at,size_bytes FROM files WHERE user_id=? ORDER BY id DESC");
$st->execute([$uid]); $rows=$st->fetchAll();
?><!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mis archivos — CDN</title>
<style>
 body{margin:0;background:
  radial-gradient(700px 400px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
  radial-gradient(700px 400px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
  linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%); color:#eaf2ff; font:15px/1.6 system-ui}
 .wrap{max-width:900px;margin:0 auto;padding:20px}
 .item{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:10px;padding:10px;margin-bottom:10px}
 a{color:#93c5fd}
</style></head><body><div class="wrap">
  <h2>📁 Mis archivos</h2>
  <p><a href="profile.php">Volver</a></p>
  <?php if(!$rows): ?><p>Sin archivos.</p><?php endif; ?>
  <?php foreach($rows as $r): ?>
    <div class="item"><b><?=htmlspecialchars($r['name'])?></b> ·
      <a href="<?=$r['url']?>" target="_blank">Abrir</a>
      <div style="color:#9fb0c9"><?=date('Y-m-d H:i', strtotime($r['created_at']))?> · <?=round($r['size_bytes']/1024,1)?> KB</div>
    </div>
  <?php endforeach; ?>
</div></body></html>