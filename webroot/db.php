<?php
// db.php
require_once __DIR__ . '/config.php';
session_start();

/* === Conexión SQLite === */
$pdo = new PDO('sqlite:' . __DIR__ . '/data.sqlite', null, null, [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA busy_timeout = 30000');

/* === Tablas base (idempotentes) === */
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT UNIQUE NOT NULL,
  username TEXT UNIQUE,
  first_name TEXT,
  last_name  TEXT,
  pass TEXT NOT NULL,
  api_key TEXT,
  is_admin   INTEGER DEFAULT 0,
  is_deluxe  INTEGER DEFAULT 0,
  verified   INTEGER DEFAULT 0,
  verify_token TEXT,
  quota_limit INTEGER DEFAULT 50,
  registration_ip TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS files (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  url  TEXT NOT NULL,
  path TEXT NOT NULL,
  size_bytes INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Nota: usamos \"key\" entre comillas para evitar choques con la palabra clave
CREATE TABLE IF NOT EXISTS settings (
  \"key\" TEXT PRIMARY KEY,
  value  TEXT
);

CREATE TABLE IF NOT EXISTS password_resets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  token TEXT UNIQUE NOT NULL,
  expires_at INTEGER NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
");

/* === Migrador sencillo: añade columnas si faltan === */
function ensure_column($table, $col, $type) {
  global $pdo;
  $st = $pdo->prepare("PRAGMA table_info($table)");
  $st->execute();
  $cols = array_column($st->fetchAll(), 'name');
  if (!in_array($col, $cols, true)) {
    $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
  }
}
ensure_column('users', 'username',       'TEXT UNIQUE');
ensure_column('users', 'first_name',     'TEXT');
ensure_column('users', 'last_name',      'TEXT');
ensure_column('users', 'registration_ip','TEXT');

/* === Helpers de settings (clave/valor) === */
function setting_get(string $key, $default = null) {
  global $pdo;
  $st = $pdo->prepare('SELECT value FROM settings WHERE "key"=? LIMIT 1');
  $st->execute([$key]);
  $v = $st->fetchColumn();
  return ($v === false) ? $default : $v;
}
function setting_set(string $key, string $value): void {
  global $pdo;
  $st = $pdo->prepare('INSERT INTO settings("key",value) VALUES(?,?)
                       ON CONFLICT("key") DO UPDATE SET value=excluded.value');
  $st->execute([$key, $value]);
}

/* === Wrappers compatibles para código antiguo (admin.php, etc.) === */
function get_setting(string $name, string $default = ''): string {
  $v = setting_get($name, $default);
  return ($v === null) ? $default : (string)$v;
}
function set_setting(string $name, string $value): void {
  setting_set($name, $value);
}

/* === SMTP desde settings (con fallback a config.php) === */
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

/* === Flags iniciales === */
/* Bloqueo por IP: ON por defecto si aún no existe */
if (setting_get('ip_block_enabled') === null) {
  setting_set('ip_block_enabled', '1');
}

/* === Utilidades varias usadas en otros scripts === */
if (!function_exists('rand_key')) {
  // Por si no está en config.php
  function rand_key(int $len = 40): string { return bin2hex(random_bytes((int)($len/2))); }
}

/** ¿Usuario admin? */
function is_admin(int $uid): bool {
  global $pdo;
  $st = $pdo->prepare("SELECT is_admin FROM users WHERE id=? LIMIT 1");
  $st->execute([$uid]);
  return (int)$st->fetchColumn() === 1;
}

/* === Usuario root admin por primera vez === */
if (!defined('ROOT_ADMIN_EMAIL')) {
  define('ROOT_ADMIN_EMAIL', 'yemilpty1998@gmail.com');
}
if (!defined('ROOT_ADMIN_PASSWORD')) {
  define('ROOT_ADMIN_PASSWORD', 'Flowpty1998@'); // cámbialo luego desde el panel
}

$st = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$st->execute([ROOT_ADMIN_EMAIL]);
if (!$st->fetch()) {
  $pdo->prepare("
    INSERT INTO users(email,username,first_name,last_name,pass,api_key,is_admin,is_deluxe,verified,quota_limit)
    VALUES(?,?,?,?,?,?,?,?,?,?)
  ")->execute([
    ROOT_ADMIN_EMAIL,
    'rootadmin',
    'Root', 'Admin',
    password_hash(ROOT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
    rand_key(40),
    1, // is_admin
    0, // is_deluxe
    1, // verified
    999999
  ]);
}

/* === Carpeta de uploads (si la necesitas central) === */
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
  @mkdir($uploadsDir, 0775, true);
}