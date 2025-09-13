<?php
require_once __DIR__.'/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php');
  exit;
}

$user = trim($_POST['user'] ?? '');
$pass = $_POST['pass'] ?? '';

if ($user === '' || $pass === '') {
  $_SESSION['flash_err'] = 'Completa usuario/correo y contraseña.';
  header('Location: index.php');
  exit;
}

// ¿Es email o username?
$isEmail = filter_var($user, FILTER_VALIDATE_EMAIL);

// Buscar usuario (emails en minúsculas; username case-insensitive)
if ($isEmail) {
  $needle = function_exists('mb_strtolower') ? mb_strtolower($user) : strtolower($user);
  $st = $pdo->prepare("SELECT id, pass, verified FROM users WHERE email = ? LIMIT 1");
  $st->execute([$needle]);
} else {
  $st = $pdo->prepare("SELECT id, pass, verified FROM users WHERE username = ? COLLATE NOCASE LIMIT 1");
  $st->execute([$user]);
}
$u = $st->fetch();

if (!$u) {
  $_SESSION['flash_err'] =
    'No encontramos los datos de tu cuenta — tal vez no estás registrado o escribiste mal tu correo/usuario o contraseña. ' .
    '¿Aún no te registraste? <a href="register.php"><b>Regístrate aquí</b></a>.';
  header('Location: index.php');
  exit;
}

if (!password_verify($pass, $u['pass'])) {
  $_SESSION['flash_err'] = 'Contraseña incorrecta. Inténtalo otra vez.';
  header('Location: index.php');
  exit;
}

if ((int)$u['verified'] !== 1) {
  $_SESSION['flash_err'] = 'Debes verificar tu correo antes de ingresar.';
  header('Location: index.php');
  exit;
}

// OK -> sesión y al panel
$_SESSION['uid'] = (int)$u['id'];
header('Location: profile.php');
exit;