<?php
// paypal.php — cliente PayPal (live/sandbox) + catálogo de planes
declare(strict_types=1);

require_once __DIR__.'/db.php';

function paypal_cfg(): array {
  $mode = strtolower(setting_get('paypal_mode','sandbox')) === 'live' ? 'live' : 'sandbox';
  return [
    'mode'   => $mode,
    'base'   => $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com',
    'cid'    => trim(setting_get('paypal_client_id','')),
    'secret' => trim(setting_get('paypal_client_secret','')),
    'brand'  => setting_get('invoice_business', setting_get('biz_name','SkyUltraPlus')),
  ];
}

function pp_log(string $line): void {
  // si no existe la carpeta, no pasa nada
  $file = __DIR__.'/paypal.log';
  @file_put_contents($file, '['.date('Y-m-d H:i:s')."] $line\n", FILE_APPEND);
}

function paypal_get_token(?string &$err = null): ?string {
  $cfg = paypal_cfg();
  if ($cfg['cid']==='' || $cfg['secret']==='') {
    $err = 'Faltan credenciales (client_id/secret) en Admin → Pagos';
    pp_log("token ERROR: $err");
    return null;
  }
  $url = $cfg['base'].'/v1/oauth2/token';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_USERPWD        => $cfg['cid'].':'.$cfg['secret'],
    CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_TIMEOUT        => 20,
  ]);
  $res  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);

  if ($res === false) {
    $err = 'cURL error: '.$cerr;
    pp_log("token cURL ERROR: $err");
    return null;
  }
  $j = json_decode($res, true);
  if ($http !== 200 || empty($j['access_token'])) {
    $err = "OAuth HTTP $http ".($j['error_description'] ?? $res);
    pp_log("token HTTP ERROR: $err");
    return null;
  }
  return $j['access_token'];
}

/**
 * Llama API REST de PayPal
 * @return array|null  Respuesta JSON decodificada (o null si no es JSON). $http y $err devuelven detalles.
 */
function paypal_api(string $method, string $path, $body, ?int &$http = null, ?string &$err = null) {
  $cfg = paypal_cfg();
  $token = paypal_get_token($err);
  if (!$token) { $http = 0; return null; }

  $url = $cfg['base'].$path;
  $ch  = curl_init($url);
  $json = is_string($body) ? $body : json_encode($body);
  $hdrs = [
    'Authorization: Bearer '.$token,
    'Content-Type: application/json',
    'Accept: application/json'
  ];
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_HTTPHEADER     => $hdrs,
    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
  ];
  if ($method !== 'GET') $opts[CURLOPT_POSTFIELDS] = $json;
  curl_setopt_array($ch, $opts);

  $res  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);

  if ($res === false) {
    $err = 'cURL error: '.$cerr;
    pp_log("API $method $path cURL ERROR: $err");
    return null;
  }

  $dec = json_decode($res, true);
  if ($http < 200 || $http >= 300) {
    $err = "HTTP $http ".($dec['message'] ?? $res);
    pp_log("API $method $path HTTP ERROR: $err; req=$json; res=$res");
  } else {
    pp_log("API $method $path OK $http; req=$json; res=".substr($res,0,300).'…');
  }
  return $dec ?? $res;
}

/** Catálogo de planes one-time */
function plans_catalog(): array {
  return [
    'PLUS50'  => ['name'=>'+50 archivos',  'usd'=>1.37, 'inc'=>50 ],
    'PLUS120' => ['name'=>'+120 archivos', 'usd'=>2.45, 'inc'=>120],
    'PLUS250' => ['name'=>'+250 archivos', 'usd'=>3.55, 'inc'=>250],
    // más tarde: 'DELUXE_SUB' => ['name'=>'Deluxe mensual', 'usd'=>2.50, 'inc'=>0],
  ];
}
