<?php
require_once __DIR__.'/db.php';
if (empty($_SESSION['uid'])) { header('Location: index.php'); exit; }
$uid=(int)$_SESSION['uid'];

$st=$pdo->prepare("SELECT first_name,last_name,username,is_deluxe,api_key FROM users WHERE id=?");
$st->execute([$uid]);
$me=$st->fetch();
if (!$me) { header('Location: logout.php'); exit; }

$isDeluxe = (int)($me['is_deluxe'] ?? 0) === 1;
$apiKey   = (string)($me['api_key'] ?? '');

/* flashes */
if (session_status() === PHP_SESSION_NONE) session_start();
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configuración — CDN</title>
<style>
 body{margin:0;font:15px/1.6 system-ui;background:
  radial-gradient(700px 400px at 100% -10%, rgba(219,39,119,.25), transparent 60%),
  radial-gradient(700px 400px at 0% 110%, rgba(37,99,235,.25), transparent 60%),
  linear-gradient(160deg,#0a0b12 10%, #120e1a 40%, #051436 100%); color:#eaf2ff}
 .wrap{max-width:720px;margin:0 auto;padding:20px}
 .card{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:18px}
 .btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;border:none;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer;text-decoration:none}
 .btn.ghost{background:transparent;border:1px solid rgba(255,255,255,.25);color:#eaf2ff}
 .input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#eaf2ff}
 a{color:#93c5fd}
 .row{display:grid;gap:10px}
 .field{position:relative}
 .toggle{position:absolute;right:8px;top:8px;height:32px;padding:0 10px;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#eaf2ff;cursor:pointer}
 .hint{color:#9fb0c9;font-size:13px;margin-top:6px}
 .btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
 .alert{padding:10px;border-radius:10px;margin-bottom:10px}
 .ok{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.35)}
 .err{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.35)}
</style>
</head><body><div class="wrap">
  <h2>⚙️ Configuración de perfil</h2>
  <div style="margin-bottom:10px">
    <a class="btn" href="profile.php">⬅️ Volver al panel</a>
    <a class="btn" href="list.php">📁 Ver mis archivos</a>
  </div>

  <?php if($flash_ok): ?><div class="alert ok"><?=htmlspecialchars($flash_ok)?></div><?php endif; ?>
  <?php if($flash_err): ?><div class="alert err"><?=htmlspecialchars($flash_err)?></div><?php endif; ?>

  <div class="card">
    <form id="profileForm" method="post" action="profile_save.php" onsubmit="return validateForm()">
      <div class="row">
        <div>
          <label>Nombre</label>
          <input class="input" name="first_name" value="<?=htmlspecialchars($me['first_name']??'')?>" required>
        </div>
        <div>
          <label style="margin-top:8px">Apellido</label>
          <input class="input" name="last_name" value="<?=htmlspecialchars($me['last_name']??'')?>" required>
        </div>
        <div>
          <label style="margin-top:8px">Usuario</label>
          <input class="input" name="username" value="<?=htmlspecialchars($me['username']??'')?>" required>
        </div>

        <hr style="border-color:#334155;margin:12px 0;width:100%">

        <!-- API KEY solo editable si es Deluxe -->
        <div style="width:100%">
          <label>API Key <?= $isDeluxe ? '(editable)' : '(solo lectura — activa Deluxe para editar)' ?></label>
          <div class="row" style="grid-template-columns:1fr auto auto">
            <div class="field">
              <input class="input" id="api_key" name="api_key"
                     value="<?=htmlspecialchars($apiKey)?>" type="password" autocomplete="off"
                     <?= $isDeluxe ? '' : 'readonly' ?>>
              <button class="toggle" type="button" id="showApi">Ver</button>
            </div>
            <button class="btn ghost" type="button" id="copyApi">Copiar</button>
            <button class="btn" type="submit">Guardar</button>
          </div>
          <div class="hint">
            La API key debe tener entre 6 y 64 caracteres y sólo puede usar: letras, números, guion y guion-bajo
            (<code>A-Za-z0-9_-</code>). Cambiarla invalidará la anterior.
          </div>
          <?php if(!$isDeluxe): ?>
            <div class="hint">Para poder editar tu API Key, primero activa el plan <b>Deluxe</b>.</div>
          <?php endif; ?>
        </div>

        <hr style="border-color:#334155;margin:12px 0;width:100%">

        <div class="field">
          <label>Contraseña actual</label>
          <input class="input" id="current" type="password" name="current" placeholder="Obligatoria si cambiarás la contraseña" autocomplete="current-password">
          <button class="toggle" type="button" data-target="current">Ver</button>
        </div>

        <div class="field">
          <label style="margin-top:8px">Nueva contraseña (6+)</label>
          <input class="input" id="new" type="password" name="new" placeholder="Opcional" autocomplete="new-password" minlength="6">
          <button class="toggle" type="button" data-target="new">Ver</button>
        </div>

        <div class="field">
          <label style="margin-top:8px">Repetir nueva contraseña</label>
          <input class="input" id="new2" type="password" name="new2" placeholder="Repite la nueva" autocomplete="new-password" minlength="6">
          <button class="toggle" type="button" data-target="new2">Ver</button>
        </div>

        <div class="btns">
          <button class="btn">Guardar cambios</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
  // Toggle ver/ocultar inputs con data-target
  document.querySelectorAll('.toggle[data-target]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.getAttribute('data-target');
      const inp = document.getElementById(id);
      if (!inp) return;
      if (inp.type === 'password'){ inp.type='text'; btn.textContent='Ocultar'; }
      else { inp.type='password'; btn.textContent='Ver'; }
    });
  });

  // API key: ver/ocultar / copiar
  const apiI = document.getElementById('api_key');
  const showApi = document.getElementById('showApi');
  const copyApi = document.getElementById('copyApi');

  showApi?.addEventListener('click', ()=>{
    if (apiI.type === 'password'){ apiI.type='text'; showApi.textContent='Ocultar'; }
    else { apiI.type='password'; showApi.textContent='Ver'; }
  });
  copyApi?.addEventListener('click', async ()=>{
    try { await navigator.clipboard.writeText(apiI.value||''); copyApi.textContent='¡Copiada!'; setTimeout(()=>copyApi.textContent='Copiar',1200); }
    catch {}
  });

  // Validación en cliente (API key si es Deluxe + cambio de contraseña)
  function validateForm(){
    const isDeluxe = <?= $isDeluxe ? 'true' : 'false' ?>;
    const api = (apiI?.value||'').trim();
    if (isDeluxe) {
      if (api.length < 6) { alert('La API key debe tener al menos 6 caracteres.'); apiI.focus(); return false; }
      if (!/^[A-Za-z0-9_-]{6,64}$/.test(api)) { alert('La API key sólo puede usar A-Z, a-z, 0-9, - y _. Máx. 64.'); apiI.focus(); return false; }
    }

    const cur = document.getElementById('current');
    const n1  = document.getElementById('new');
    const n2  = document.getElementById('new2');
    const wantsChange = (n1.value.trim() !== '' || n2.value.trim() !== '');
    if (wantsChange) {
      if (cur.value.trim() === '') { alert('Debes escribir tu contraseña actual.'); cur.focus(); return false; }
      if (n1.value.length < 6) { alert('La nueva contraseña debe tener al menos 6 caracteres.'); n1.focus(); return false; }
      if (n1.value !== n2.value) { alert('Las contraseñas nuevas no coinciden.'); n2.focus(); return false; }
    }
    return true;
  }
</script>
</body></html>
