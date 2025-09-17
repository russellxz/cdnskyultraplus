<?php
require_once __DIR__.'/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php'); exit;
}

$user = trim($_POST['user'] ?? '');
$pass = $_POST['pass'] ?? '';

if ($user === '' || $pass === '') {
  $_SESSION['flash_err'] = 'Completa usuario/correo y contraseña.';
  header('Location: index.php'); exit;
}

try {
  // ¿Ingresó un email?
  $isEmail = filter_var($user, FILTER_VALIDATE_EMAIL);
  if ($isEmail) {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($user) : strtolower($user);
    $st = $pdo->prepare("SELECT id, pass, verified, status FROM users WHERE email = ? LIMIT 1");
    $st->execute([$needle]);
  } else {
    $st = $pdo->prepare("SELECT id, pass, verified, status FROM users WHERE username = ? LIMIT 1");
    $st->execute([$user]);
  }

  $u = $st->fetch();

  if (!$u || !password_verify($pass, $u['pass'])) {
    $_SESSION['flash_err'] =
      'No encontramos los datos de tu cuenta — revisa usuario/correo y contraseña. ' .
      '¿Aún no te registraste? <a href="register.php"><b>Regístrate aquí</b></a>.';
    header('Location: index.php'); exit;
  }

  // 🚨 Bloqueo de cuentas suspendidas
  if (isset($u['status']) && $u['status'] === 'suspended') {
    $_SESSION['flash_err'] = '❌ Tu cuenta está suspendida. Contacta al soporte.';
    header('Location: index.php'); exit;
  }

  if ((int)$u['verified'] !== 1) {
    $_SESSION['flash_err'] = 'Debes verificar tu correo antes de ingresar.';
    header('Location: index.php'); exit;
  }

  // OK: login
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  header('Location: profile.php'); exit;

} catch (Throwable $e) {
  error_log('LOGIN_ERR: '.$e->getMessage());
  $_SESSION['flash_err'] = 'Error interno al iniciar sesión. Inténtalo de nuevo.';
  header('Location: index.php'); exit;
}
