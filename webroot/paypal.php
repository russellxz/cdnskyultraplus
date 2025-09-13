<?php
// paypal.php
// Helpers para hablar con PayPal v2 + catálogo de planes

require_once __DIR__.'/db.php';

function setting_any($k, $def=''){
  // lee primero clave nueva "pp_*", luego la vieja "paypal_*"
  $v = setting_get($k, null);
  if ($v !== null) return $v;
  if (str_starts_with($k, 'pp_')) {
    $alt = 'paypal_'.substr($k, 3);
    $v2 = setting_get($alt, null);
    if ($v2 !== null) return $v2;
  }
  return $def;
}

function paypal_cfg(){
  $mode   = setting_any('pp_mode','sandbox');                    // sandbox|live
  $cid    = setting_any('pp_client_id','');
  $secret = setting_any('pp_client_secret','');
  $base   = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
  return ['mode'=>$mode,'client_id'=>$cid,'client_secret'=>$secret,'base'=>$base];
}

function paypal_log($msg){
  $file = __DIR__.'/paypal.log';
  $line = '['.date('Y-m-d H:i:s').'] '.$msg."\n";
  // intenta escribir, si no, manda a error_log
  @file_put_contents($file, $line, FILE_APPEND);
  if (!file_exists($file)) error_log('[paypal] '.$msg);
}

function paypal_get_token(&$err=null){
  $cfg = paypal_cfg();
  if (!$cfg['client_id'] || !$cfg['client_secret']) { $err='Faltan credenciales'; return null; }

  $ch = curl_init($cfg['base'].'/v1/oauth2/token');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en_US'],
    CURLOPT_USERPWD => $cfg['client_id'].':'.$cfg['client_secret'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
  ]);
  $res = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $ce   = curl_error($ch);
  curl_close($ch);

  if ($http !== 200) {
    $err = 'OAuth HTTP '.$http.' '.($ce?:$res);
    paypal_log('OAuth ERROR '.$err);
    return null;
  }
  $j = json_decode($res, true);
  $tok = $j['access_token'] ?? null;
  if (!$tok) { $err='OAuth sin token'; paypal_log('OAuth sin token: '.$res); }
  return $tok;
}

/**
 * Llama a la REST API de PayPal v2.
 * $body:
 *  - null   => sin cuerpo (para capture también es válido)
 *  - '{}'   => manda exactamente JSON vacío {}
 *  - array  => se serializa con json_encode
 */
function paypal_api($method, $path, $body, &$http=null, &$err=null){
  $cfg = paypal_cfg();
  $tok = paypal_get_token($err);
  if (!$tok) return null;

  $url = (str_starts_with($path,'http')) ? $path : $cfg['base'].$path;

  $ch = curl_init($url);
  $hdr = ['Accept: application/json', 'Authorization: Bearer '.$tok];

  if ($method === 'POST' || $method === 'PATCH' || $method === 'PUT') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body === '{}') {
      $hdr[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    } elseif (is_array($body)) {
      $hdr[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    } elseif ($body === null) {
      // sin cuerpo; no seteamos POSTFIELDS
      curl_setopt($ch, CURLOPT_POSTFIELDS, null);
    } else {
      // string crudo (poco común)
      $hdr[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
    }
  } else {
    curl_setopt($ch, CURLOPT_HTTPGET, true);
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $hdr,
  ]);

  $res  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $ce   = curl_error($ch);
  curl_close($ch);

  paypal_log(sprintf('API %s %s HTTP %d; req=%s; res=%s',
    $method, $path, $http,
    is_array($body)?json_encode($body):($body===null?'(null)':$body),
    $res
  ));

  if ($res === false) { $err = 'cURL: '.$ce; return null; }
  $j = json_decode($res, true);
  if ($j === null) { $err='JSON inválido de PayPal'; return null; }
  if ($http >= 400) { $err='HTTP '.$http.' '.$res; }
  return $j;
}

/** Catálogo de planes (en lugar de archivo aparte) */
function plans_catalog(){
  return [
    'PLUS50'  => ['name'=>'+50 archivos',  'inc'=>50,  'usd'=>1.37],
    'PLUS120' => ['name'=>'+120 archivos', 'inc'=>120, 'usd'=>2.45],
    'PLUS250' => ['name'=>'+250 archivos', 'inc'=>250, 'usd'=>3.55],
  ];
}
