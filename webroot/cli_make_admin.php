#!/usr/bin/env php
<?php
// CLI: crear (o elevar) el primer admin con MySQL/MariaDB.
// Uso no interactivo (opcional):
//   php cli_make_admin.php --email=you@mail.com --user=admin --first=Nombre --last=Apellido --pass='TuPass123' --deluxe=0 --quota=999999

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "Solo CLI.\n"); exit(1); }

require_once __DIR__.'/db.php'; // crea $pdo y helpers
if (!function_exists('rand_key')) {
  function rand_key(int $len=40): string { return bin2hex(random_bytes((int)($len/2))); }
}

function argval(string $name): ?string {
  foreach ($GLOBALS['argv'] as $a) {
    if (str_starts_with($a, "--$name=")) return substr($a, strlen($name)+3);
  }
  return null;
}
function prompt(string $q, ?string $def=null, bool $hidden=false): string {
  $suf = $def!==null ? " [$def]" : "";
  fwrite(STDOUT, "$q$suf: ");
  if ($hidden && strtoupper(substr(PHP_OS,0,3)) !== 'WIN') {
    shell_exec('stty -echo');
    $in = trim(fgets(STDIN) ?: '');
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
  } else {
    $in = trim(fgets(STDIN) ?: '');
  }
  return $in !== '' ? $in : (string)($def ?? '');
}

$email = argval('email') ?? prompt('Correo');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR,"Email inválido.\n"); exit(1); }

$username = argval('user')  ?? prompt('Usuario', 'admin');
$first    = argval('first') ?? prompt('Nombre', 'Root');
$last     = argval('last')  ?? prompt('Apellido', 'Admin');
$pass     = argval('pass')  ?? prompt('Contraseña (no se mostrará)', null, true);
if ($pass === '') { fwrite(STDERR,"La contraseña no puede estar vacía.\n"); exit(1); }

$deluxe   = argval('deluxe'); $deluxe = ($deluxe!==null) ? (int)$deluxe : (int)(strtolower(prompt('¿Deluxe? (y/N)','N'))==='y');
$quota    = argval('quota');  $quota  = ($quota!==null)  ? (int)$quota  : (int)prompt('Quota (número de archivos)', '999999');

$pdo->beginTransaction();

try {
  // ¿Existe ya?
  $st = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $id = (int)($st->fetchColumn() ?: 0);

  if ($id > 0) {
    // Elevar/actualizar
    $api = rand_key(40);
    $pdo->prepare("UPDATE users
                     SET username=?,
                         first_name=?,
                         last_name=?,
                         pass=?,
                         api_key=?,
                         is_admin=1,
                         is_deluxe=?,
                         verified=1,
                         quota_limit=?
                   WHERE id=?")
        ->execute([
          $username, $first, $last,
          password_hash($pass, PASSWORD_DEFAULT),
          $api, $deluxe, $quota, $id
        ]);
  } else {
    // Crear nuevo
    $api = rand_key(40);
    $pdo->prepare("INSERT INTO users(email,username,first_name,last_name,pass,api_key,is_admin,is_deluxe,verified,quota_limit,registration_ip)
                   VALUES(?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
          $email, $username, $first, $last,
          password_hash($pass, PASSWORD_DEFAULT),
          $api, 1, $deluxe, 1, $quota, '127.0.0.1'
        ]);
    $id = (int)$pdo->lastInsertId();
  }

  $pdo->commit();

  fwrite(STDOUT, "\n✅ Admin listo.\n");
  fwrite(STDOUT, "ID: $id\n");
  fwrite(STDOUT, "Usuario: $username\n");
  fwrite(STDOUT, "Email: $email\n");
  fwrite(STDOUT, "Deluxe: ".($deluxe? 'Sí':'No')."\n");
  fwrite(STDOUT, "Quota: $quota archivos\n");
  fwrite(STDOUT, "API Key nueva: $api\n\n");
  fwrite(STDOUT, "👉 Ya puedes iniciar sesión en el sitio.\n");
} catch (Throwable $e) {
  $pdo->rollBack();
  fwrite(STDERR, "ERROR: ".$e->getMessage()."\n");
  exit(1);
}
