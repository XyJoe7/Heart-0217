<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

// Apply security headers and rate limiting
Security::addSecurityHeaders();

$ip = ip();
$rateLimitFile = __DIR__ . '/../data/ratelimit.json';
Security::loadRateLimitData($rateLimitFile);

if (!Security::checkRateLimit("redeem:{$ip}", 30, 60)) {
    Security::saveRateLimitData($rateLimitFile);
    respond(['ok'=>false,'error'=>'rate_limit_exceeded','message'=>'请求过于频繁'], 429);
}

require_method('POST');
$cfg = cfg();
$in = json_input();
$code = trim(strval($in['code'] ?? ''));

// Validate code format to prevent injection attacks
if ($code !== '' && !preg_match('/^[A-Z0-9-]+$/i', $code)) {
    Security::logSecurityEvent('invalid_code_format', ['code' => substr($code, 0, 20), 'ip' => $ip]);
    Security::saveRateLimitData($rateLimitFile);
    respond(['ok'=>false,'error'=>'invalid_code_format','message'=>'激活码格式无效'], 400);
}

if ($code === '') respond(['ok'=>false,'error'=>'empty_code','message'=>'请输入激活码'], 400);

$lock = __DIR__ . '/../data/.lock';
$result = with_lock($lock, function() use ($cfg, $code, $in){
  $codesDb = load_json_file($cfg['CODES_FILE']);
  $sessionsDb = load_json_file($cfg['SESSIONS_FILE']);
  $codes = $codesDb['codes'] ?? [];
  $sessions = $sessionsDb['sessions'] ?? [];

  // 清理过期会话（顺手）
  cleanup_expired_sessions($sessions);

  if (!isset($codes[$code])) {
    return ['ok'=>false,'error'=>'not_found','message'=>'激活码不存在或已失效'];
  }

  $c = $codes[$code];
  if (!empty($c['disabled'])) return ['ok'=>false,'error'=>'disabled','message'=>'激活码已停用'];
  $t = now();
  $exp = intval($c['expiresAt'] ?? 0);
  if ($exp > 0 && $exp < $t) return ['ok'=>false,'error'=>'expired','message'=>'激活码已过期'];
  $maxUses = intval($c['maxUses'] ?? 1);
  $uses = intval($c['uses'] ?? 0);
  if ($uses >= $maxUses) return ['ok'=>false,'error'=>'used_up','message'=>'激活码已被使用'];

  // 核销一次
  $uses++;
  $c['uses'] = $uses;
  $c['lastUsedAt'] = $t;
  $codes[$code] = $c;

  // 生成会话 token
  $grantDays = intval($c['grantDays'] ?? $cfg['DEFAULT_GRANT_DAYS'] ?? 3);
  if ($grantDays <= 0) $grantDays = 3;

  $sessionExp = $t + $grantDays * 86400;
  if ($exp > 0) $sessionExp = min($sessionExp, $exp); // 不超过码的过期时间

  $sid = bin2hex(random_bytes(16));
  $payload = [
    'sid' => $sid,
    'code' => $code,
    'exp' => $sessionExp,
    'iat' => $t,
    'v' => 1
  ];

  $token = sign_token($payload, strval($cfg['SECRET_KEY']));
  $uaBind = !empty($cfg['BIND_UA']);
  $uaHash = $uaBind ? ua_hash(ua()) : '';

  // 来源追踪
  $source = trim(strval($in['source'] ?? 'direct'));
  $refCode = trim(strval($in['refCode'] ?? ''));
  $utmSource = trim(strval($in['utmSource'] ?? ''));
  $utmMedium = trim(strval($in['utmMedium'] ?? ''));
  $utmCampaign = trim(strval($in['utmCampaign'] ?? ''));

  $sessions[$sid] = [
    'code' => $code,
    'issuedAt' => $t,
    'expiresAt' => $sessionExp,
    'ip' => ip(),
    'uaHash' => $uaHash,
    'source' => $source,
    'refCode' => $refCode,
    'utmSource' => $utmSource,
    'utmMedium' => $utmMedium,
    'utmCampaign' => $utmCampaign,
  ];

  // 分销记录
  if ($refCode !== '') {
    $refFile = __DIR__ . '/../data/referrals.json';
    $refDb = load_json_file($refFile);
    $referrers = $refDb['referrers'] ?? [];
    if (isset($referrers[$refCode]) && empty($referrers[$refCode]['disabled'])) {
      $referrers[$refCode]['totalOrders'] = intval($referrers[$refCode]['totalOrders'] ?? 0) + 1;
      $refDb['referrers'] = $referrers;
      $logs = $refDb['logs'] ?? [];
      $logs[] = [
        'refCode' => $refCode,
        'activationCode' => $code,
        'time' => $t,
        'ip' => ip(),
      ];
      $refDb['logs'] = $logs;
      save_json_file_atomic($refFile, $refDb);
    }
  }

  // 保存
  $codesDb['codes'] = $codes;
  $sessionsDb['sessions'] = $sessions;
  save_json_file_atomic($cfg['CODES_FILE'], $codesDb);
  save_json_file_atomic($cfg['SESSIONS_FILE'], $sessionsDb);

  return ['ok'=>true,'token'=>$token,'expiresAt'=>$sessionExp,'grantDays'=>$grantDays];
});

Security::saveRateLimitData($rateLimitFile);

if (!$result['ok']) respond($result, 403);
respond($result);
