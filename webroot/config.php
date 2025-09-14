<?php
/* ===========================
 *  CONFIGURACIÓN PÚBLICA
 * =========================== */

// URL pública (sin slash final)
const BASE_URL = 'https://cdn.skyultraplus.com';

// WhatsApp (contacto) — opcional
const WHATSAPP_URL = 'https://wa.me/00000000000';

// Precios de referencia
const PRICE_USD = 1.37;       // recargas
const PRICE_DELUXE_LIFETIME = 5.00; // Deluxe de por vida

// No mostrar planes en el login
const PLANS_TEXT = '';

// Límites de subida (MB)
const SIZE_LIMIT_FREE_MB    = 5;    // cuenta normal
const SIZE_LIMIT_DELUXE_MB  = 100;  // cuenta deluxe

// SMTP de ejemplo (seguro para repos públicos)
const SMTP_HOST = 'smtp.example.com';
const SMTP_PORT = 587;
const SMTP_USER = 'no-reply@example.com';
const SMTP_PASS = 'cámbiame';
const SMTP_FROM = 'no-reply@example.com';
const SMTP_FROM_NAME = 'SkyUltraPlus (Demo)';

// Alias para mail.php (si existiera)
const MAIL_FROM = SMTP_FROM;
const MAIL_FROM_NAME = SMTP_FROM_NAME;

/* ===========================
 *  BASE DE DATOS: MySQL/MariaDB
 *  (sin .env; ajusta estos valores en producción)
 * =========================== */
const DB_HOST    = '127.0.0.1';
const DB_PORT    = 3306;
const DB_NAME    = 'cdn_skyultra';
const DB_USER    = 'cdn_user';
const DB_PASS    = 'superseguro';
const DB_CHARSET = 'utf8mb4';

// Carpeta de almacenamiento (uploads)
const UPLOAD_BASE = __DIR__ . '/uploads';
if (!is_dir(UPLOAD_BASE)) {
  @mkdir(UPLOAD_BASE, 0775, true);
}

/* ===========================
 *  Helpers genéricos
 * =========================== */
function rand_key(int $len = 40): string {
  $n = max(16, $len);
  return bin2hex(random_bytes(intval($n/2)));
}

function clean_label(string $s): string {
  $s = trim(preg_replace('/\s+/', ' ', $s));
  if (function_exists('mb_substr')) return mb_substr($s, 0, 120);
  return substr($s, 0, 120);
}
