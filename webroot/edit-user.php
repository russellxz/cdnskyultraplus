<?php
require 'db.php';

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

    if ($status === 'suspended') {
        $subject = "Tu cuenta ha sido suspendida";
        $msg = "Hola $first_name,\n\nTu cuenta fue suspendida por un administrador.\n\nSi crees que es un error, contacta soporte.";
        mail($email, $subject, $msg, "From: soporte@tudominio.com");
    }

    echo "<p style='color:lime;font-weight:bold;text-align:center;'>✅ Cambios guardados.</p>";
}
?>

<div class="card" style="max-width:700px;margin:30px auto;padding:25px;border-radius:12px;box-shadow:0 0 12px rgba(0,0,0,0.4);background:#1c1c28;color:#fff;">
  <h2 style="margin-bottom:20px;">✏️ Editar Usuario</h2>
  <form method="post" class="form" style="display:flex;flex-direction:column;gap:15px">
    
    <div>
      <label>Email</label>
      <input class="input" type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" style="width:100%">
    </div>

    <div>
      <label>Usuario</label>
      <input class="input" type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" style="width:100%">
    </div>

    <div style="display:flex;gap:10px">
      <div style="flex:1">
        <label>Nombre</label>
        <input class="input" type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" style="width:100%">
      </div>
      <div style="flex:1">
        <label>Apellido</label>
        <input class="input" type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" style="width:100%">
      </div>
    </div>

    <div>
      <label>Nueva contraseña</label>
      <input class="input" type="password" name="pass" placeholder="Dejar en blanco si no cambia" style="width:100%">
    </div>

    <div>
      <label>Status</label>
      <select class="input" name="status" style="width:100%">
        <option value="active" <?= $user['status']=='active'?'selected':'' ?>>Activo</option>
        <option value="suspended" <?= $user['status']=='suspended'?'selected':'' ?>>Suspendido</option>
      </select>
    </div>

    <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end">
      <button class="btn" type="submit" name="save" style="background:#4CAF50;color:#fff;">💾 Guardar cambios</button>
      <a href="delete-user.php?id=<?= $user['id'] ?>" class="btn danger" style="background:#e53935;color:#fff;">🗑️ Borrar</a>
      <a href="admin.php" class="btn" style="background:#555;color:#fff;">⬅️ Volver</a>
    </div>
  </form>
</div>
