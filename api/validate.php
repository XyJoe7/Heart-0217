<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

require_method('POST');
$cfg = cfg();
$in = json_input();
$token = trim(strval($in['token'] ?? ''));

if ($token === '') respond(['ok'=>false,'error'=>'empty_token'], 400);

$payload = verify_token($token, strval($cfg['SECRET_KEY']));
if (!$payload) respond(['ok'=>false,'error'=>'invalid_token'], 403);

$exp = intval($payload['exp'] ?? 0);
if ($exp > 0 && $exp < now()) respond(['ok'=>false,'error'=>'expired_token'], 403);

$sid = strval($payload['sid'] ?? '');
if ($sid === '') respond(['ok'=>false,'error'=>'invalid_token'], 403);

$lock = __DIR__ . '/../data/.lock';
$out = with_lock($lock, function() use ($cfg, $sid){
  $sessionsDb = load_json_file($cfg['SESSIONS_FILE']);
  $sessions = $sessionsDb['sessions'] ?? [];
  cleanup_expired_sessions($sessions);

  if (!isset($sessions[$sid])) {
    // 会话不存在（可能被销毁/过期清理）
    $sessionsDb['sessions'] = $sessions;
    save_json_file_atomic($cfg['SESSIONS_FILE'], $sessionsDb);
    return ['ok'=>false,'error'=>'session_missing'];
  }

  $s = $sessions[$sid];

  // UA 绑定校验
  if (!empty($cfg['BIND_UA'])) {
    $expect = strval($s['uaHash'] ?? '');
    if ($expect !== '' && $expect !== ua_hash(ua())) {
      return ['ok'=>false,'error'=>'ua_mismatch','message'=>'当前设备与激活设备不一致'];
    }
  }

  // 如果码被后台禁用，也应失效
  $codesDb = load_json_file($cfg['CODES_FILE']);
  $codes = $codesDb['codes'] ?? [];
  $code = strval($s['code'] ?? '');
  if ($code && isset($codes[$code]) && !empty($codes[$code]['disabled'])) {
    return ['ok'=>false,'error'=>'code_disabled','message'=>'权限已被停用'];
  }

  return ['ok'=>true,'expiresAt'=>intval($s['expiresAt'] ?? 0),'code'=>$code];
});

if (!$out['ok']) respond($out, 403);
respond($out);
