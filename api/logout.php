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

respond(['ok'=>true]);
