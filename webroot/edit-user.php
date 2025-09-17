<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php'; // <- igual que admin.php / forgot.php

if (!isset($_GET['id'])) {
    die("ID de usuario no especificado");
}

$id = intval($_GET['id']);
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    die("Usuario no encontrado");
}

// Guardamos status actual antes de editar
$old_status = $user['status'];

if (isset($_POST['save'])) {
    $email      = $_POST['email'];
    $username   = $_POST['username'];
    $first_name = $_POST['first_name'];
    $last_name  = $_POST['last_name'];
    $status     = $_POST['status'];

    // Actualiza usuario (con o sin password)
    $sql    = "UPDATE users SET email=?, username=?, first_name=?, last_name=?, status=? WHERE id=?";
    $params = [$email, $username, $first_name, $last_name, $status, $id];

    if (!empty($_POST['pass'])) {
        $hashed = password_hash($_POST['pass'], PASSWORD_BCRYPT);
        $sql    = "UPDATE users SET email=?, username=?, first_name=?, last_name=?, pass=?, status=? WHERE id=?";
        $params = [$email, $username, $first_name, $last_name, $hashed, $status, $id];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Log del cambio de estado
    error_log("DEBUG " . date("Y-m-d H:i:s") . " → old_status={$old_status} | new_status={$status} | user={$email}");

    // Enviar correo si cambió el estado
    if ($old_status !== $status) {
        $subject = '';
        $msg     = '';

        if ($status === 'suspended') {
    $subject = "🚫 Tu cuenta ha sido suspendida";
    $msg = "
    <html>
    <body style='margin:0;padding:0;background:#0f1115;font-family:Arial,Helvetica,sans-serif;'>
      <div style='max-width:640px;margin:0 auto;background:#12141b;color:#fff'>
        <!-- preheader -->
        <div style='display:none;opacity:0;height:0;overflow:hidden'>Tu cuenta ha sido suspendida</div>

        <!-- header -->
        <div style='text-align:center;padding:28px 24px;border-bottom:1px solid #1f2330'>
          <img src='https://cdn.russellxz.click/logo-skyultraplus.png' alt='SkyUltraPlus' style='max-width:180px;height:auto'>
        </div>

        <!-- body -->
        <div style='padding:28px 24px'>
          <h1 style='margin:0 0 10px;font-size:24px;line-height:1.3;color:#ff4d4f'>Cuenta suspendida</h1>
          <p style='margin:0 0 12px;color:#cfd3dc;font-size:15px'>Hola <b style=\"color:#fff;\">{$first_name}</b>,</p>
          <p style='margin:0 0 12px;color:#cfd3dc;font-size:15px'>
            Tu cuenta ha sido <b style='color:#fff'>suspendida</b> por un administrador.
          </p>
          <p style='margin:0 20px 22px;color:#9aa3af;font-size:13px'>
            Si crees que es un error o necesitas más información, contacta a nuestro equipo de soporte.
          </p>

          <!-- CTA -->
          <div style='text-align:center;margin:22px 0 8px'>
            <a href='https://skyultraplus.com/discord' target='_blank'
               style='display:inline-block;background:#5865F2;color:#fff;text-decoration:none;
                      padding:12px 20px;border-radius:10px;font-weight:bold'>
              Contactar soporte
            </a>
          </div>

          <!-- note -->
          <p style='margin:16px 0 0;color:#6b7280;font-size:12px;text-align:center'>
            También puedes copiar y pegar este enlace en tu navegador:<br>
            <span style='color:#9CA3AF'>https://skyultraplus.com/discord</span>
          </p>
        </div>

        <!-- footer -->
        <div style='padding:18px 24px;border-top:1px solid #1f2330;color:#6b7280;font-size:12px;text-align:center'>
          © ".date('Y')." SkyUltraPlus — Este es un mensaje automático
        </div>
      </div>
    </body>
    </html>";
    error_log('DEBUG '.date('Y-m-d H:i:s').' → preparando correo de SUSPENSIÓN a '.$email);
}
elseif ($status === 'active' && $old_status === 'suspended') {
    $subject = "✅ Tu cuenta ha sido reactivada";
    $msg = "
    <html>
    <body style='margin:0;padding:0;background:#0f1115;font-family:Arial,Helvetica,sans-serif;'>
      <div style='max-width:640px;margin:0 auto;background:#12141b;color:#fff'>
        <!-- preheader -->
        <div style='display:none;opacity:0;height:0;overflow:hidden'>Tu cuenta fue reactivada</div>

        <!-- header -->
        <div style='text-align:center;padding:28px 24px;border-bottom:1px solid #1f2330'>
          <img src='https://cdn.russellxz.click/logo-skyultraplus.png' alt='SkyUltraPlus' style='max-width:180px;height:auto'>
        </div>

        <!-- body -->
        <div style='padding:28px 24px'>
          <h1 style='margin:0 0 10px;font-size:24px;line-height:1.3;color:#22c55e'>Cuenta reactivada</h1>
          <p style='margin:0 0 12px;color:#cfd3dc;font-size:15px'>Hola <b style=\"color:#fff;\">{$first_name}</b>,</p>
          <p style='margin:0 0 12px;color:#cfd3dc;font-size:15px'>
            Nos alegra informarte que tu cuenta ha sido <b style='color:#fff'>reactivada</b>.
          </p>
          <p style='margin:0 20px 22px;color:#9aa3af;font-size:13px'>
            Si tienes alguna duda o ves algo raro en tu cuenta, contáctanos.
          </p>

          <!-- CTA -->
          <div style='text-align:center;margin:22px 0 8px'>
            <a href='https://skyultraplus.com/discord' target='_blank'
               style='display:inline-block;background:#5865F2;color:#fff;text-decoration:none;
                      padding:12px 20px;border-radius:10px;font-weight:bold'>
              Contactar soporte
            </a>
          </div>

          <!-- note -->
          <p style='margin:16px 0 0;color:#6b7280;font-size:12px;text-align:center'>
            Enlace directo: <span style='color:#9CA3AF'>https://skyultraplus.com/discord</span>
          </p>
        </div>

        <!-- footer -->
        <div style='padding:18px 24px;border-top:1px solid #1f2330;color:#6b7280;font-size:12px;text-align:center'>
          © ".date('Y')." SkyUltraPlus — Este es un mensaje automático
        </div>
      </div>
    </body>
    </html>";
    error_log('DEBUG '.date('Y-m-d H:i:s').' → preparando correo de REACTIVACIÓN a '.$email);
}

        if ($subject !== '' && $msg !== '') {
            // Usa la MISMA función que admin.php/forgot.php (firma con 4 parámetros)
            $err = '';
            $ok  = send_custom_email($email, $subject, $msg, $err);
            error_log("DEBUG " . date("Y-m-d H:i:s") . " → resultado send_custom_email: " . ($ok ? "OK" : "FAIL") . ($err ? " | err={$err}" : ""));
        }
    }

    echo "<p style='color:lime;font-weight:bold;text-align:center;'>✅ Cambios guardados.</p>";
}
?>

<div class="card" style="max-width:800px;margin:40px auto;padding:30px;border-radius:14px;box-shadow:0 8px 20px rgba(0,0,0,0.5);background:#1c1c28;color:#fff;">
  <h2 style="margin-bottom:25px;font-size:22px;">✏️ Editar Usuario</h2>
  <form method="post" class="form" style="display:flex;flex-direction:column;gap:20px">
    
    <div style="display:flex;flex-direction:column;gap:6px">
      <label>Email</label>
      <input class="input" type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" style="padding:10px;border-radius:6px;width:100%">
    </div>

    <div style="display:flex;flex-direction:column;gap:6px">
      <label>Usuario</label>
      <input class="input" type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" style="padding:10px;border-radius:6px;width:100%">
    </div>

    <div style="display:flex;gap:15px">
      <div style="flex:1;display:flex;flex-direction:column;gap:6px">
        <label>Nombre</label>
        <input class="input" type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" style="padding:10px;border-radius:6px;width:100%">
      </div>
      <div style="flex:1;display:flex;flex-direction:column;gap:6px">
        <label>Apellido</label>
        <input class="input" type="text" name="last_name" value <?= htmlspecialchars($user['last_name']) ?> style="padding:10px;border-radius:6px;width:100%">
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:6px">
      <label>Nueva contraseña</label>
      <input class="input" type="password" name="pass" placeholder="Dejar en blanco si no cambia" style="padding:10px;border-radius:6px;width:100%">
    </div>

    <div style="display:flex;flex-direction:column;gap:6px">
      <label>Status</label>
      <select class="input" name="status" style="padding:10px;border-radius:6px;width:100%">
        <option value="active"    <?= $user['status']=='active'?'selected':'' ?>>Activo</option>
        <option value="suspended" <?= $user['status']=='suspended'?'selected':'' ?>>Suspendido</option>
      </select>
    </div>

    <div style="margin-top:25px;display:flex;gap:15px;justify-content:flex-end">
      <button class="btn" type="submit" name="save" style="padding:10px 20px;border-radius:6px;background:#4CAF50;color:#fff;font-weight:bold;">💾 Guardar</button>
      <a href="delete-user.php?id=<?= $user['id'] ?>" style="padding:10px 20px;border-radius:6px;background:#e53935;color:#fff;font-weight:bold;text-decoration:none;">🗑️ Borrar</a>
      <a href="admin.php" style="padding:10px 20px;border-radius:6px;background:#555;color:#fff;font-weight:bold;text-decoration:none;">⬅️ Volver</a>
    </div>
  </form>
</div>
