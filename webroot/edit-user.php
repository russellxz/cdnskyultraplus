<?php
require 'db.php';

if (!isset($_GET['id'])) {
    die("ID de usuario no especificado");
}

$id = $_GET['id'];

// cargar datos del usuario
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    die("Usuario no encontrado");
}

// guardar cambios
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

    echo "✅ Cambios guardados.";
}
?>

<h2>Editar Usuario</h2>
<form method="post">
  <label>Email</label>
  <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>">

  <label>Usuario</label>
  <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>">

  <label>Nombre</label>
  <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>">

  <label>Apellido</label>
  <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>">

  <label>Nueva contraseña</label>
  <input type="password" name="pass" placeholder="Dejar en blanco si no cambia">

  <label>Status</label>
  <select name="status">
    <option value="active" <?= $user['status']=='active'?'selected':'' ?>>Activo</option>
    <option value="suspended" <?= $user['status']=='suspended'?'selected':'' ?>>Suspendido</option>
  </select>

  <br><br>
  <button type="submit" name="save">Guardar cambios</button>
  <a href="delete-user.php?id=<?= $user['id'] ?>" class="btn btn-danger">Borrar</a>
</form>
