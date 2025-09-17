<?php
include 'db.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Si no hay ID válido
if ($id <= 0) {
    die("❌ ID de usuario inválido.");
}

// Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username)) {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET username=?, password=? WHERE id=?");
            $stmt->bind_param("ssi", $username, $hashed, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=? WHERE id=?");
            $stmt->bind_param("si", $username, $id);
        }
        if ($stmt->execute()) {
            echo "<div class='alert alert-success text-center'>✅ Usuario actualizado correctamente</div>";
        } else {
            echo "<div class='alert alert-danger text-center'>❌ Error al actualizar</div>";
        }
        $stmt->close();
    }
}

// Cargar datos usuario
$user = $conn->query("SELECT * FROM users WHERE id=$id")->fetch_assoc();
if (!$user) {
    die("❌ Usuario no encontrado.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Editar Usuario</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container mt-5">
    <div class="card shadow-lg">
      <div class="card-header bg-primary text-white text-center">
        ✏️ Editar Usuario (ID: <?= $user['id'] ?>)
      </div>
      <div class="card-body">
        <form method="post">
          <div class="mb-3">
            <label class="form-label">Nombre de usuario</label>
            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Nueva Contraseña</label>
            <input type="password" name="password" class="form-control" placeholder="Dejar vacío si no deseas cambiarla">
          </div>
          <div class="d-flex justify-content-between">
            <a href="admin.php" class="btn btn-secondary">⬅ Volver</a>
            <button type="submit" class="btn btn-success">💾 Guardar cambios</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
