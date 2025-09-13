<?php
// URL pública (sin slash final)
const BASE_URL = 'https://cdnsky.ultraplus.click';

// WhatsApp (contacto)
const WHATSAPP_URL = 'https://wa.me/15167096032';

// Precios (si los usas en otros lados)
const PRICE_USD = 1.37; // referencia

// NO mostrar planes en el login (queda vacío a propósito)
const PLANS_TEXT = '';

// Límites de subida (MB)
const SIZE_LIMIT_FREE_MB   = 5;    // cuenta normal
const SIZE_LIMIT_DELUXE_MB = 100;  // cuenta deluxe

// SMTP por defecto (pueden sobreescribirse desde Admin)
const SMTP_HOST = 'smtp.hostinger.com';
const SMTP_PORT = 587;
const SMTP_USER = 'ok@ejemplo.com';
const SMTP_PASS = 'aaa123456';
const SMTP_FROM = 'ok@ejemplo.com';
const SMTP_FROM_NAME = 'Skyultraplus';

// Alias para mail.php
const MAIL_FROM = SMTP_FROM;
const MAIL_FROM_NAME = SMTP_FROM_NAME;

// Admin raíz protegido (no se puede eliminar/degradar)
const ROOT_ADMIN_EMAIL = 'lasukisky@gmail.com';

// Generador de claves
function rand_key(int $len = 40): string {
  $n = max(16, $len); // por seguridad, mínimo 16
  return bin2hex(random_bytes(intval($n/2)));
}

// Sanitiza etiqueta (nombre “humano” del archivo)
function clean_label(string $s): string {
  $s = trim(preg_replace('/\s+/', ' ', $s));
  if (function_exists('mb_substr')) return mb_substr($s, 0, 120);
  return substr($s, 0, 120);
}

// Ruta de almacenamiento (uploads)
const UPLOAD_BASE = __DIR__.'/uploads';
if (!is_dir(UPLOAD_BASE)) {
  @mkdir(UPLOAD_BASE, 0775, true);
}