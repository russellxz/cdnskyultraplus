<?php
require_once __DIR__ . '/config.php';
session_start();

/* ===========================
 *  Conexión PDO (MySQL/MariaDB)
 * =========================== */
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
  // Modo estricto recomendado
  $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
} catch (Throwable $e) {
  http_response_code(500);
  exit('DB connection failed');
}

/* ===========================
 *  Esquema (idempotente)
 *  Collation: utf8mb4_unicode_ci
 * =========================== */
$ddl = <<<SQL
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(191) NOT NULL UNIQUE,
  username      VARCHAR(191) UNIQUE,
  first_name    VARCHAR(100),
  last_name     VARCHAR(100),
  pass          VARCHAR(255) NOT NULL,
  api_key       VARCHAR(191),
  is_admin      TINYINT(1) DEFAULT 0,
  is_deluxe     TINYINT(1) DEFAULT 0,
  verified      TINYINT(1) DEFAULT 0,
  verify_token  VARCHAR(191),
  quota_limit   INT DEFAULT 50,
  registration_ip VARCHAR(45),
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS files (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(191) NOT NULL,
  url         TEXT NOT NULL,
  path        TEXT NOT NULL,
  size_bytes  BIGINT UNSIGNED DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, name(191)),
  CONSTRAINT fk_files_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla settings clave/valor (usamos k/v para evitar palabra reservada)
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(191) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  token      VARCHAR(191) UNIQUE NOT NULL,
  expires_at INT UNSIGNED NOT NULL,
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pagos (one-off)
CREATE TABLE IF NOT EXISTS payments (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  order_id     VARCHAR(191) NOT NULL UNIQUE,
  provider     VARCHAR(50) DEFAULT 'paypal',
  plan_code    VARCHAR(50) NOT NULL,    -- PLUS50 | PLUS120 | PLUS250 | DELUXE_LIFE
  amount_usd   DECIMAL(10,2) NOT NULL,
  currency     VARCHAR(10) DEFAULT 'USD',
  status       VARCHAR(50) NOT NULL,    -- created | completed | failed | refunded
  payer_email  VARCHAR(191),
  raw_json     MEDIUMTEXT,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  captured_at  DATETIME NULL,
  CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Facturas
CREATE TABLE IF NOT EXISTS invoices (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number      VARCHAR(191) NOT NULL UNIQUE,  -- INV-YYYYMM-0001
  user_id     INT UNSIGNED NOT NULL,
  payment_id  INT UNSIGNED NULL,
  title       VARCHAR(191) NOT NULL,
  amount_usd  DECIMAL(10,2) NOT NULL,
  currency    VARCHAR(10) DEFAULT 'USD',
  status      VARCHAR(50) NOT NULL,          -- issued | paid | refunded | void
  pdf_path    TEXT,
  data_json   MEDIUMTEXT,
  issued_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  paid_at     DATETIME NULL,
  CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
  if ($stmt !== '') $pdo->exec($stmt);
}

/* ===========================
 *  Migrador simple: añade columnas si faltan
 * =========================== */
function ensure_column(string $table, string $col, string $type): void {
  global $pdo;
  $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $q->execute([$table, $col]);
  if ((int)$q->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $type");
  }
}
ensure_column('users', 'registration_ip', 'VARCHAR(45) NULL');

/* ===========================
 *  Settings helpers (k/v)
 * =========================== */
function setting_get(string $key, $default = null) {
  global $pdo;
  $st = $pdo->prepare('SELECT v FROM settings WHERE k = ? LIMIT 1');
  $st->execute([$key]);
  $v = $st->fetchColumn();
  return ($v === false) ? $default : $v;
}
function setting_set(string $key, string $value): void {
  global $pdo;
  $st = $pdo->prepare('INSERT INTO settings(k, v) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE v = VALUES(v)');
  $st->execute([$key, $value]);
}

/* Compat wrappers para código viejo */
function get_setting(string $name, string $default = ''): string {
  $v = setting_get($name, $default);
  return ($v === null) ? $default : (string)$v;
}
function set_setting(string $name, string $value): void {
  setting_set($name, $value);
}

/* ===========================
 *  SMTP helpers (lee de settings con fallback config.php)
 * =========================== */
function smtp_get(): array {
  return [
    'host' => setting_get('smtp_host', defined('SMTP_HOST') ? SMTP_HOST : ''),
    'port' => (int) setting_get('smtp_port', defined('SMTP_PORT') ? (string)SMTP_PORT : '587'),
    'user' => setting_get('smtp_user', defined('SMTP_USER') ? SMTP_USER : ''),
    'pass' => setting_get('smtp_pass', defined('SMTP_PASS') ? SMTP_PASS : ''),
    'from' => setting_get('smtp_from', defined('SMTP_FROM') ? SMTP_FROM : (defined('MAIL_FROM') ? MAIL_FROM : '')),
    'name' => setting_get('smtp_name', defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'CDN')),
  ];
}
function smtp_set(array $cfg): void {
  set_setting('smtp_host', (string)$cfg['host']);
  set_setting('smtp_port', (string)$cfg['port']);
  set_setting('smtp_user', (string)$cfg['user']);
  set_setting('smtp_pass', (string)$cfg['pass']);
  set_setting('smtp_from', (string)$cfg['from']);
  set_setting('smtp_name', (string)$cfg['name']);
}

/* ===========================
 *  Pagos / Facturas utilities
 * =========================== */
function payment_upsert(int $user_id, string $order_id, string $plan_code, float $amount_usd, string $status, array $raw = []): void {
  global $pdo;
  $st = $pdo->prepare("INSERT INTO payments(user_id,order_id,plan_code,amount_usd,status,raw_json)
                       VALUES(?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE
                         status = VALUES(status),
                         raw_json = VALUES(raw_json),
                         captured_at = CASE WHEN VALUES(status)='completed' THEN CURRENT_TIMESTAMP ELSE captured_at END");
  $st->execute([$user_id, $order_id, $plan_code, $amount_usd, $status, json_encode($raw)]);
}

function invoice_next_number(): string {
  $seq = (int) setting_get('invoice_seq', '0') + 1;
  $ym  = date('Ym');
  $num = sprintf('%s%s-%04d', setting_get('invoice_prefix','INV-'), $ym, $seq);
  setting_set('invoice_seq', (string)$seq);
  return $num;
}

function invoice_create(int $user_id, ?int $payment_id, string $title, float $amount_usd, string $currency='USD', string $status='issued', array $data=[]): int {
  global $pdo;
  $number = invoice_next_number();
  $st = $pdo->prepare("INSERT INTO invoices(number,user_id,payment_id,title,amount_usd,currency,status,data_json)
                       VALUES(?,?,?,?,?,?,?,?)");
  $st->execute([$number,$user_id,$payment_id,$title,$amount_usd,$currency,$status,json_encode($data)]);
  return (int)$pdo->lastInsertId();
}

/* ===========================
 *  Otras utilidades
 * =========================== */
function is_admin(int $uid): bool {
  global $pdo;
  $st = $pdo->prepare("SELECT is_admin FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  return (int)$st->fetchColumn() === 1;
}

/* Flags iniciales */
if (setting_get('ip_block_enabled') === null) {
  setting_set('ip_block_enabled', '1');
}
