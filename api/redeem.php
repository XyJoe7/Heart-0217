<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

require_method('POST');
$cfg = cfg();
$in = json_input();
$code = trim(strval($in['code'] ?? ''));

if ($code === '') respond(['ok'=>false,'error'=>'empty_code','message'=>'请输入激活码'], 400);

$lock = __DIR__ . '/../data/.lock';
$result = with_lock($lock, function() use ($cfg, $code){
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

  $sessions[$sid] = [
    'code' => $code,
    'issuedAt' => $t,
    'expiresAt' => $sessionExp,
    'ip' => ip(),
    'uaHash' => $uaHash,
  ];

  // 保存
  $codesDb['codes'] = $codes;
  $sessionsDb['sessions'] = $sessions;
  save_json_file_atomic($cfg['CODES_FILE'], $codesDb);
  save_json_file_atomic($cfg['SESSIONS_FILE'], $sessionsDb);

  return ['ok'=>true,'token'=>$token,'expiresAt'=>$sessionExp,'grantDays'=>$grantDays];
});

if (!$result['ok']) respond($result, 403);
respond($result);
