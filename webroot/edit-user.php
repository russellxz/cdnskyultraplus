<?php
// 1) Cargar DB con ruta ABSOLUTA (evita rutas raras de php-fpm)
require_once __DIR__ . '/db.php';

// 2) Asegurar que la función que USAS en admin.php esté disponible aquí.
//    Si no existe, la importamos desde admin.php SIN imprimir su HTML.
if (!function_exists('send_custom_email')) {
    $adminFile = __DIR__ . '/admin.php';
    if (is_file($adminFile)) {
        error_log("DEBUG " . date("Y-m-d H:i:s") . " → Cargando send_custom_email() desde admin.php");
        $lvl = ob_get_level();
        ob_start();
        require_once $adminFile;
        // limpiar cualquier salida que admin.php pueda haber generado
        while (ob_get_level() > $lvl) { ob_end_clean(); }
    }
}

// 3) Fallback: si AÚN no existe (por lo que sea), definimos una de respaldo con mail()
//    (esto evita el 'undefined function' y deja rastro en logs)
if (!function_exists('send_custom_email')) {
    error_log("DEBUG " . date("Y-m-d H:i:s") . " → send_custom_email() no existe tras cargar admin.php. Usaré fallback con mail().");
    function send_custom_email($to, $subject, $htmlBody) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: SkyUltraPlus <soporte@skyultraplus.com>\r\n";
        $headers .= "Reply-To: soporte@skyultraplus.com\r\n";
        return mail($to, $subject, $htmlBody, $headers);
    }
}

if (!isset($_GET['id'])) {
    die("ID de usuario no especificado");
}

$id = intval($_GET['id']);

// Obtener usuario actual
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    die("Usuario no encontrado");
}

$old_status = $user['status'];

if (isset($_POST['save'])) {
    $email = $_POST['email'];
    $username = $_POST['username'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $status = $_POST['status'];

    // Armar SQL dinámico si cambia pass
    $sql = "UPDATE users SET email=?, username=?, first_name=?, last_name=?, status=? WHERE id=?";
    $params = [$email, $username, $first_name, $last_name, $status, $id];

    if (!empty($_POST['pass'])) {
        $hashed = password_hash($_POST['pass'], PASSWORD_BCRYPT);
        $sql = "UPDATE users SET email=?, username=?, first_name=?, last_name=?, pass=?, status=? WHERE id=?";
        $params = [$email, $username, $first_name, $last_name, $hashed, $status, $id];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Log de lo que pasó con estados
    error_log("DEBUG " . date("Y-m-d H:i:s") . " → old_status=$old_status | new_status=$status | user=$email");

    // Enviar correo sólo si cambió el estado
    if ($old_status !== $status) {
        $subject = "";
        $msg = "";

        if ($status === 'suspended') {
            $subject = "🚫 Tu cuenta ha sido suspendida";
            $msg = "
            <html>
            <body style='font-family: Arial, sans-serif; background:#f9f9f9; padding:20px;'>
                <div style='max-width:600px; margin:auto; background:white; border-radius:8px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.1);'>
                    <div style='text-align:center;'>
                        <h2 style='color:#e53935;margin:0 0 10px;'>Cuenta suspendida</h2>
                    </div>
                    <p>Hola <b>{$first_name}</b>,</p>
                    <p>Tu cuenta ha sido <b>suspendida</b> por un administrador.</p>
                    <p>Si crees que es un error, por favor contacta a nuestro equipo de soporte.</p>
                    <p style='color:#777;margin-top:24px;'>— Equipo <b>SkyUltraPlus</b></p>
                </div>
            </body>
            </html>";
        } elseif ($status === 'active' && $old_status === 'suspended') {
            $subject = "✅ Tu cuenta ha sido reactivada";
            $msg = "
            <html>
            <body style='font-family: Arial, sans-serif; background:#f9f9f9; padding:20px;'>
                <div style='max-width:600px; margin:auto; background:white; border-radius:8px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.1);'>
                    <div style='text-align:center;'>
                        <h2 style='color:#4CAF50;margin:0 0 10px;'>Cuenta reactivada</h2>
                    </div>
                    <p>Hola <b>{$first_name}</b>,</p>
                    <p>Tu cuenta ha sido <b>reactivada</b> por el administrador. Ya puedes volver a ingresar normalmente.</p>
                    <p style='color:#777;margin-top:24px;'>— Equipo <b>SkyUltraPlus</b></p>
                </div>
            </body>
            </html>";
        }

        if (!empty($msg)) {
            error_log("DEBUG " . date("Y-m-d H:i:s") . " → Intentando enviar correo a $email con asunto '$subject'");
            $ok = send_custom_email($email, $subject, $msg);
            error_log("DEBUG " . date("Y-m-d H:i:s") . " → Resultado envío: " . ($ok ? "OK" : "FAIL"));
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
        <input class="input" type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" style="padding:10px;border-radius:6px;width:100%">
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:6px">
      <label>Nueva contraseña</label>
      <input class="input" type="password" name="pass" placeholder="Dejar en blanco si no cambia" style="padding:10px;border-radius:6px;width:100%">
    </div>

    <div style="display:flex;flex-direction:column;gap:6px">
      <label>Status</label>
      <select class="input" name="status" style="padding:10px;border-radius:6px;width:100%">
        <option value="active" <?= $user['status']=='active'?'selected':'' ?>>Activo</option>
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
