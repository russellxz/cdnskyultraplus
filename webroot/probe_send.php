<?php
// webroot/probe_send.php
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "Comprobando archivos requeridos...\n";
$must = [
  __DIR__.'/config.php',
  __DIR__.'/mail.php',
  __DIR__.'/lib/PHPMailer/src/PHPMailer.php',
  __DIR__.'/lib/PHPMailer/src/SMTP.php',
  __DIR__.'/lib/PHPMailer/src/Exception.php',
];
foreach ($must as $p) {
  echo (file_exists($p) ? "OK  " : "FALTA  ").$p."\n";
}
echo "\nIncluyendo mail.php...\n";

require_once __DIR__.'/config.php';
require_once __DIR__.'/mail.php';

echo "Enviando correo de prueba...\n";
$to  = SMTP_USER; // envíate a ti mismo
$tok = bin2hex(random_bytes(8));
$err = '';

$ok = send_verify_email($to, $tok, $err);  // admite ?debug=1 para ver log SMTP
if ($ok) {
  echo "✅ Enviado a $to\n";
} else {
  echo "❌ Error: $err\n";
  $log = __DIR__.'/logs/mail.log';
  if (file_exists($log)) {
    echo "Revisa también: $log\n";
  }
}