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

    echo "<p style='color:lime;'>✅ Cambios guardados.</p>";
}
?>

<div class="card" style="max-width:600px;margin:20px auto;padding:20px">
  <h2>Editar Usuario</h2>
  <form method="post" class="form">
    
    <label>Email</label>
    <input class="input" type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>">

    <label>Usuario</label>
    <input class="input" type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>">

    <label>Nombre</label>
    <input class="input" type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>">

    <label>Apellido</label>
    <input class="input" type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>">

    <label>Nueva contraseña</label>
    <input class="input" type="password" name="pass" placeholder="Dejar en blanco si no cambia">

    <label>Status</label>
    <select class="input" name="status">
      <option value="active" <?= $user['status']=='active'?'selected':'' ?>>Activo</option>
      <option value="suspended" <?= $user['status']=='suspended'?'selected':'' ?>>Suspendido</option>
    </select>

    <div style="margin-top:15px;display:flex;gap:10px">
      <button class="btn" type="submit" name="save">💾 Guardar cambios</button>
      <a href="delete-user.php?id=<?= $user['id'] ?>" class="btn danger">🗑️ Borrar</a>
      <a href="admin.php" class="btn">⬅️ Volver</a>
    </div>
  </form>
</div>
