<?php
require 'db.php';

// función de envío (igual a admin.php)
if (!function_exists('send_custom_email')) {
    function send_custom_email($to, $subject, $message) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: SkyUltraPlus <ventas@skyultraplus.com>\r\n";
        $headers .= "Reply-To: ventas@skyultraplus.com\r\n";
        return mail($to, $subject, $message, $headers);
    }
}

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

$old_status = $user['status'];

if (isset($_POST['save'])) {
    $email = $_POST['email'];
    $username = $_POST['username'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $status = $_POST['status'];

    $sql = "UPDATE users SET email=?, username=?, first_name=?, last_name=?, status=? WHERE id=?";
    $params = [$email, $username, $first_name, $last_name, $status, $id];

    if (!empty($_POST['pass'])) {
        $hashed = password_hash($_POST['pass'], PASSWORD_BCRYPT);
        $sql = "UPDATE users SET email=?, username=?, first_name=?, last_name=?, pass=?, status=? WHERE id=?";
        $params = [$email, $username, $first_name, $last_name, $hashed, $status, $id];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // log de cambio detectado
    error_log("DEBUG " . date("Y-m-d H:i:s") . " → old_status=$old_status | new_status=$status | user=$email");

    // enviar correo si cambió el estado
    if ($old_status !== $status) {
        if ($status === 'suspended') {
            $subject = "🚫 Tu cuenta ha sido suspendida";
            $msg = "<html><body><h2>Cuenta suspendida</h2><p>Hola <b>$first_name</b>, tu cuenta ha sido suspendida.</p></body></html>";
            error_log("DEBUG " . date("Y-m-d H:i:s") . " → preparando correo de SUSPENSIÓN a $email");
        } elseif ($status === 'active' && $old_status === 'suspended') {
            $subject = "✅ Tu cuenta ha sido reactivada";
            $msg = "<html><body><h2>Cuenta reactivada</h2><p>Hola <b>$first_name</b>, tu cuenta ha sido reactivada.</p></body></html>";
            error_log("DEBUG " . date("Y-m-d H:i:s") . " → preparando correo de REACTIVACIÓN a $email");
        }

        if (!empty($msg)) {
            $ok = send_custom_email($email, $subject, $msg);
            error_log("DEBUG " . date("Y-m-d H:i:s") . " → resultado send_custom_email: " . ($ok ? "OK" : "FAIL"));
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
