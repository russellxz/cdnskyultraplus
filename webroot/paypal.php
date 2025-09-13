<?php
declare(strict_types=1);

require_once __DIR__.'/db.php';

// === LOG ===
if (!defined('PAYPAL_LOG')) {
  define('PAYPAL_LOG', __DIR__ . '/paypal.log');
}
function pp_log($msg): void {
  try {
    $line = '['.date('Y-m-d H:i:s').'] '.(is_string($msg)?$msg:json_encode($msg, JSON_UNESCAPED_SLASHES))."\n";
    @file_put_contents(PAYPAL_LOG, $line, FILE_APPEND);
  } catch(Throwable $e) {}
}

// === CONFIG ===
function paypal_cfg(): array {
  $mode   = setting_get('paypal_mode','sandbox') === 'live' ? 'live' : 'sandbox';
  return [
    'mode'   => $mode,
    'cid'    => setting_get('paypal_client_id',''),
    'secret' => setting_get('paypal_client_secret',''),
    'base'   => ($mode==='live') ? 'https://api.paypal.com' : 'https://api.sandbox.paypal.com',
  ];
}

// === TOKEN ===
function paypal_get_token(?string &$err = null): ?string {
  $cfg = paypal_cfg();
  if (!$cfg['cid'] || !$cfg['secret']) { $err='missing_creds'; return null; }

  $ch = curl_init($cfg['base'].'/v1/oauth2/token');
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
    CURLOPT_USERPWD        => $cfg['cid'].':'.$cfg['secret'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json','Accept-Language: en_US'],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
  ]);
  $body = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ce   = curl_error($ch);
  curl_close($ch);

  if ($http===200) {
    $j = json_decode($body,true);
    $t = $j['access_token'] ?? null;
    if ($t) return $t;
  }
  $err = 'token_http_'.$http.($ce?':'.$ce:'');
  pp_log(['token_err'=>$err,'body'=>$body]);
  return null;
}

// === API GENÉRICA ===
function paypal_api(string $method, string $path, $data, ?int &$http=null, ?string &$err=null) {
  $cfg = paypal_cfg();
  $tok = paypal_get_token($err);
  if (!$tok) { $http=0; return null; }

  $url = $cfg['base'].$path;
  $ch  = curl_init($url);

  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
      'Authorization: Bearer '.$tok,
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
  ];

  $m = strtoupper($method);
  if ($m==='POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($data ?: new stdClass(), JSON_UNESCAPED_SLASHES); }
  elseif ($m==='GET') { /* nada */ }
  else { $opts[CURLOPT_CUSTOMREQUEST] = $m; $opts[CURLOPT_POSTFIELDS] = json_encode($data ?: new stdClass(), JSON_UNESCAPED_SLASHES); }

  curl_setopt_array($ch, $opts);
  $res  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ce   = curl_error($ch);
  curl_close($ch);

  if ($http>=200 && $http<300) {
    $j = json_decode($res,true);
    return $j ?: $res;
  }

  $err = 'api_http_'.$http.($ce?':'.$ce:'');
  pp_log(['api_err'=>$err,'url'=>$url,'method'=>$m,'data'=>$data,'res'=>$res]);
  return json_decode($res,true) ?: $res;
}

// === CATÁLOGO DE PLANES ===
function plans_catalog(): array {
  return [
    'PLUS50'  => ['name'=>'+50 archivos',  'usd'=>1.37, 'inc'=>50],
    'PLUS120' => ['name'=>'+120 archivos', 'usd'=>2.45, 'inc'=>120],
    'PLUS250' => ['name'=>'+250 archivos', 'usd'=>3.55, 'inc'=>250],
    // Futuro: 'DELUXE_SUB' => ['name'=>'Deluxe mensual','usd'=>2.50, 'inc'=>0, 'sub'=>true]
  ];
}
