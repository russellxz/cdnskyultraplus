<?php
// webroot/paypal_client.php
require_once __DIR__ . '/db.php';

function paypal_base(): string {
  $mode = setting_get('paypal_mode', 'sandbox');
  return $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}
function paypal_creds(): array {
  return [ setting_get('paypal_client_id', ''), setting_get('paypal_client_secret', '') ];
}
function paypal_token(?string &$err = null): ?string {
  [$cid, $sec] = paypal_creds();
  if (!$cid || !$sec) { $err = 'Faltan Client ID/Secret'; return null; }

  $ch = curl_init(paypal_base().'/v1/oauth2/token');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
    CURLOPT_USERPWD        => $cid.':'.$sec,
    CURLOPT_HTTPHEADER     => ['Accept: application/json','Accept-Language: en_US'],
    CURLOPT_TIMEOUT        => 20,
  ]);
  $res  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $e    = curl_error($ch);
  curl_close($ch);

  if ($http !== 200 || !$res) { $err = "HTTP $http ".($e ?: $res); return null; }
  $j = json_decode($res, true);
  return $j['access_token'] ?? null;
}
function paypal_api(string $method, string $path, array $body = null, int &$http = null, ?string &$err = null) {
  $tok = paypal_token($err); if (!$tok) return null;
  $ch = curl_init(paypal_base().$path);
  $hdr = ['Content-Type: application/json', 'Authorization: Bearer '.$tok];
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $hdr,
    CURLOPT_TIMEOUT        => 25,
  ];
  if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
  curl_setopt_array($ch, $opts);
  $res  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $e    = curl_error($ch);
  curl_close($ch);
  if ($res === false) { $err = $e; return null; }
  return json_decode($res, true);
}

/** Catálogo de planes one-shot */
function plans_catalog(): array {
  return [
    'plus50'  => ['name' => '+50 archivos',  'usd' => 1.37, 'inc' => 50],
    'plus120' => ['name' => '+120 archivos', 'usd' => 2.45, 'inc' => 120],
    'plus250' => ['name' => '+250 archivos', 'usd' => 3.55, 'inc' => 250],
  ];
}
