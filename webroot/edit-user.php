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
            <body style='font-family: Arial, sans-serif; background:#f9f9f9; padding:20px;'>
                <div style='max-width:600px; margin:auto; background:white; border-radius:8px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.1);'>
                    <div style='text-align:center;'>
                        <img src='https://cdn.russellxz.click/logo-skyultraplus.png' alt='SkyUltraPlus' style='max-width:180px; margin-bottom:20px;'>
                        <h2 style='color:#e53935;'>Cuenta suspendida</h2>
                    </div>
                    <p>Hola <b>{$first_name}</b>,</p>
                    <p>Tu cuenta ha sido <b>suspendida</b> por un administrador.</p>
                    <p>Si crees que es un error, por favor contacta a nuestro equipo de soporte.</p>
                    <br>
                    <p style='color:#777;'>Atentamente,<br>El equipo de <b>SkyUltraPlus</b></p>
                </div>
            </body>
            </html>";
            error_log("DEBUG " . date("Y-m-d H:i:s") . " → preparando correo de SUSPENSIÓN a {$email}");
        } elseif ($status === 'active' && $old_status === 'suspended') {
            $subject = "✅ Tu cuenta ha sido reactivada";
            $msg = "
            <html>
            <body style='font-family: Arial, sans-serif; background:#f9f9f9; padding:20px;'>
                <div style='max-width:600px; margin:auto; background:white; border-radius:8px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.1);'>
                    <div style='text-align:center;'>
                        <img src='https://cdn.russellxz.click/logo-skyultraplus.png' alt='SkyUltraPlus' style='max-width:180px; margin-bottom:20px;'>
                        <h2 style='color:#4CAF50;'>Cuenta reactivada</h2>
                    </div>
                    <p>Hola <b>{$first_name}</b>,</p>
                    <p>Nos alegra informarte que tu cuenta ha sido <b>reactivada</b> por el administrador.</p>
                    <p>Ya puedes volver a ingresar normalmente y disfrutar de nuestros servicios.</p>
                    <br>
                    <p style='color:#777;'>Atentamente,<br>El equipo de <b>SkyUltraPlus</b></p>
                </div>
            </body>
            </html>";
            error_log("DEBUG " . date("Y-m-d H:i:s") . " → preparando correo de REACTIVACIÓN a {$email}");
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
