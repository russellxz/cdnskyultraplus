<?php
// Uso: php cli_make_admin.php
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "Ejecuta esto por CLI.\n"); exit(1); }

require_once __DIR__ . '/db.php';

function prompt($label, $required = true) {
  do {
    echo $label . ': ';
    $v = trim(fgets(STDIN));
  } while ($required && $v === '');
  return $v;
}

echo "=== Crear Admin Inicial ===\n";
$first = prompt('Nombre');
$last  = prompt('Apellido');
$user  = prompt('Nombre de usuario');
$email = prompt('Email');
do {
  $pass  = prompt('Contraseña');
  $pass2 = prompt('Repite contraseña');
  if ($pass !== $pass2) echo "No coinciden. Intenta de nuevo.\n";
} while ($pass !== $pass2);

try {
  // ¿Ya existe algún admin?
  $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin=1")->fetchColumn();
  if ($hasAdmin > 0) {
    echo "Ya existe al menos un admin. (De todas formas crearé otro admin)\n";
  }

  $st = $pdo->prepare("INSERT INTO users(email,username,first_name,last_name,pass,api_key,is_admin,is_deluxe,verified,quota_limit,registration_ip)
                       VALUES(?,?,?,?,?,?,?,?,?,?,?)");
  $st->execute([
    $email,
    $user,
    $first,
    $last,
    password_hash($pass, PASSWORD_DEFAULT),
    rand_key(40),
    1,    // is_admin
    0,    // is_deluxe
    1,    // verified
    999999, // cuota inicial grande
    'CLI'
  ]);

  $id = (int)$pdo->lastInsertId();
  echo "✅ Admin creado (ID: $id)\n";
} catch (Throwable $e) {
  fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
  exit(1);
}
