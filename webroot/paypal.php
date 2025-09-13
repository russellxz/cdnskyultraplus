<?php
declare(strict_types=1);

require_once __DIR__.'/db.php';

/** Lee config actual de PayPal desde settings */
function paypal_cfg(): array {
  $mode   = setting_get('pp_mode', 'sandbox'); // sandbox|live
  $cid    = setting_get('pp_client_id', '');
  $secret = setting_get('pp_client_secret', '');
  $base   = ($mode === 'live')
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
  return [
    'mode'          => $mode,
    'client_id'     => $cid,
    'client_secret' => $secret,
    'base'          => $base,
  ];
}

/** Pequeño cliente HTTP + decode JSON */
function http_json(string $method, string $url, array $headers = [], array|string|null $body = null, ?int &$status = null, ?string &$err = null) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  if ($body !== null) {
    if (is_array($body)) $body = http_build_query($body);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return null;
  }
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = json_decode($resp, true);
  if ($json === null) { $err = 'JSON inválido'; }
  return $json;
}

/** Obtiene un access_token OAuth2 con client_credentials */
function paypal_get_token(?string &$error = null): ?string {
  $cfg = paypal_cfg();
  if (!$cfg['client_id'] || !$cfg['client_secret']) {
    $error = 'Faltan pp_client_id/pp_client_secret';
    return null;
  }
  $url = $cfg['base'].'/v1/oauth2/token';
  $headers = [
    'Accept: application/json',
    'Accept-Language: es_ES',
    'Content-Type: application/x-www-form-urlencoded',
    'Authorization: Basic '.base64_encode($cfg['client_id'].':'.$cfg['client_secret']),
  ];
  $status = null; $err = null;
  $res = http_json('POST', $url, $headers, ['grant_type'=>'client_credentials'], $status, $err);
  if ($res && $status === 200 && !empty($res['access_token'])) {
    return (string)$res['access_token'];
  }
  $error = $err ?: ('HTTP '.$status.' '.json_encode($res));
  return null;
}
