<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

// Apply security headers and rate limiting
Security::addSecurityHeaders();

$ip = ip();
$rateLimitFile = __DIR__ . '/../data/ratelimit.json';
Security::loadRateLimitData($rateLimitFile);

if (!Security::checkRateLimit("logout:{$ip}", 60, 60)) {
    Security::saveRateLimitData($rateLimitFile);
    respond(['ok'=>false,'error'=>'rate_limit_exceeded','message'=>'请求过于频繁'], 429);
}

require_method('POST');
$cfg = cfg();
$in = json_input();
$token = trim(strval($in['token'] ?? ''));

if ($token === '') respond(['ok'=>false,'error'=>'empty_token'], 400);
$payload = verify_token($token, strval($cfg['SECRET_KEY']));
if (!$payload) respond(['ok'=>false,'error'=>'invalid_token'], 403);
$sid = strval($payload['sid'] ?? '');
if ($sid === '') respond(['ok'=>false,'error'=>'invalid_token'], 403);

$lock = __DIR__ . '/../data/.lock';
with_lock($lock, function() use ($cfg, $sid){
  $sessionsDb = load_json_file($cfg['SESSIONS_FILE']);
  $sessions = $sessionsDb['sessions'] ?? [];
  if (isset($sessions[$sid])) unset($sessions[$sid]);
  $sessionsDb['sessions'] = $sessions;
  save_json_file_atomic($cfg['SESSIONS_FILE'], $sessionsDb);
  return true;
});

Security::saveRateLimitData($rateLimitFile);
respond(['ok'=>true]);
