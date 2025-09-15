<?php
// Carga el autoloader de Composer sin importar desde dónde se incluya
$paths = [
  __DIR__ . '/vendor/autoload.php',          // /var/www/cdnskyultraplus/vendor/autoload.php
  __DIR__ . '/../vendor/autoload.php',       // por si mueves este archivo
  dirname(__DIR__) . '/vendor/autoload.php', // fallback
];
$loaded = false;
foreach ($paths as $p) {
  if (is_file($p)) { require_once $p; $loaded = true; break; }
}
if (!$loaded) {
  error_log('Stripe SDK autoload no encontrado. Verifica vendor/autoload.php');
}
