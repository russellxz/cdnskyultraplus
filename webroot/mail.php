<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/mail_tmpl.php';   // 👈 plantilla separada

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/* Fallback si db.php no expone smtp_get() */
if (!function_exists('smtp_get')) {
  function smtp_get(): array {
    return [
      'host' => defined('SMTP_HOST') ? SMTP_HOST : 'localhost',
      'port' => defined('SMTP_PORT') ? SMTP_PORT : 587,
      'user' => defined('SMTP_USER') ? SMTP_USER : '',
      'pass' => defined('SMTP_PASS') ? SMTP_PASS : '',
      'from' => defined('SMTP_FROM') ? SMTP_FROM : (defined('MAIL_FROM') ? MAIL_FROM : 'no-reply@example.com'),
      'name' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Mailer'),
    ];
  }
}

/* Log interno (no imprime nada) */
function mail_log(string $msg): void {
  $dir = __DIR__ . '/logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents($dir . '/mail.log', '[' . date('c') . "] $msg\n", FILE_APPEND);
}

/* Crea el PHPMailer configurado */
function mailer_new(): PHPMailer {
  $s = smtp_get();
  $m = new PHPMailer(true);
  $m->isSMTP();
  $m->Host       = (string)$s['host'];
  $m->Port       = (int)$s['port'];
  $m->SMTPAuth   = true;
  $m->Username   = (string)$s['user'];
  $m->Password   = (string)$s['pass'];
  $m->SMTPSecure = ($m->Port === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
  $m->CharSet    = 'UTF-8';
  $m->Encoding   = 'base64';
  $m->Timeout    = 20;
  $m->SMTPKeepAlive = false;
  $m->SMTPDebug  = SMTP::DEBUG_OFF;

  $m->setFrom((string)$s['from'], (string)$s['name']);
  $m->addReplyTo((string)$s['from'], (string)$s['name']);
  return $m;
}

/* Verificación de cuenta */
function send_verify_email(string $to, string $token, ?string &$error = null): bool {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'Email inválido'; return false; }
  $m = mailer_new();
  $link = BASE_URL . '/verify.php?token=' . urlencode($token) . '&email=' . urlencode($to);

  [$html, $alt] = mail_build_brand_html(
    'Confirma tu correo',
    '<p>Hola 👋</p><p>Confirma tu email para activar tu cuenta en <b>SkyUltraPlus CDN</b>.</p>',
    'Verificar correo',
    $link
  );

  $m->addAddress($to);
  $m->Subject = 'Verifica tu correo — SkyUltraPlus CDN';
  $m->isHTML(true);
  $m->Body    = $html;
  $m->AltBody = $alt;

  try { return $m->send(); }
  catch (\Throwable $e) { $error = $m->ErrorInfo ?: $e->getMessage(); mail_log("VERIFY: $error"); return false; }
}

/* Restablecer contraseña */
function send_reset_email(string $to, string $token, ?string &$error = null): bool {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'Email inválido'; return false; }
  $m = mailer_new();
  $link = BASE_URL . '/reset.php?token=' . urlencode($token);

  [$html, $alt] = mail_build_brand_html(
    'Restablece tu contraseña',
    '<p>Recibimos una solicitud para restablecer la contraseña de tu cuenta en <b>SkyUltraPlus CDN</b>.</p>
     <p>Haz clic en el botón para crear una nueva contraseña. El enlace vence en <b>30 minutos</b>.</p>',
    'Restablecer contraseña',
    $link
  );

  $m->addAddress($to);
  $m->Subject = 'Restablece tu contraseña — SkyUltraPlus CDN';
  $m->isHTML(true);
  $m->Body    = $html;
  $m->AltBody = $alt;

  try { return $m->send(); }
  catch (\Throwable $e) { $error = $m->ErrorInfo ?: $e->getMessage(); mail_log("RESET: $error"); return false; }
}

/* Envío genérico HTML */
function send_custom_email(string $to, string $subject, string $html, ?string &$error = null): bool {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'Email inválido'; return false; }
  $m = mailer_new();
  $m->addAddress($to);
  $m->Subject = $subject;
  $m->isHTML(true);
  $m->Body    = $html;
  $m->AltBody = strip_tags($html);
  try { return $m->send(); }
  catch (\Throwable $e) { $error = $m->ErrorInfo ?: $e->getMessage(); mail_log("SEND: $error"); return false; }
}