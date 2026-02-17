<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

require_method('POST');
$cfg = cfg();
$in = json_input();
$action = strval($in['action'] ?? '');

function admin_auth(array $cfg, array $in): array {
  $token = strval($in['adminToken'] ?? '');
  if ($token === '') return [false, null];
  $p = verify_token($token, strval($cfg['SECRET_KEY']));
  if (!$p) return [false, null];
  if (strval($p['typ'] ?? '') !== 'admin') return [false, null];
  if (intval($p['exp'] ?? 0) < now()) return [false, null];
  return [true, $p];
}

if ($action === 'login') {
  $password = strval($in['password'] ?? '');
  if ($password === '' || $password !== strval($cfg['ADMIN_PASSWORD'])) {
    respond(['ok'=>false,'error'=>'bad_password'], 403);
  }
  $t = now();
  $payload = ['typ'=>'admin','iat'=>$t,'exp'=>$t + 12*3600,'v'=>1];
  $adminToken = sign_token($payload, strval($cfg['SECRET_KEY']));
  respond(['ok'=>true,'adminToken'=>$adminToken,'expiresAt'=>$payload['exp']]);
}

list($ok, $adminPayload) = admin_auth($cfg, $in);
if (!$ok) respond(['ok'=>false,'error'=>'admin_unauthorized'], 403);

$lock = __DIR__ . '/../data/.lock';

$out = with_lock($lock, function() use ($cfg, $in, $action){
  $codesDb = load_json_file($cfg['CODES_FILE']);
  $sessionsDb = load_json_file($cfg['SESSIONS_FILE']);
  $codes = $codesDb['codes'] ?? [];
  $sessions = $sessionsDb['sessions'] ?? [];
  cleanup_expired_sessions($sessions);

  if ($action === 'stats') {
    $t = now();
    $total = count($codes);
    $disabled = 0; $expired = 0; $usedup = 0;
    foreach ($codes as $c) {
      if (!empty($c['disabled'])) $disabled++;
      $exp = intval($c['expiresAt'] ?? 0);
      if ($exp>0 && $exp<$t) $expired++;
      $uses = intval($c['uses'] ?? 0);
      $max = intval($c['maxUses'] ?? 1);
      if ($uses >= $max) $usedup++;
    }
    return ['ok'=>true,'stats'=>[
      'total'=>$total,'disabled'=>$disabled,'expired'=>$expired,'usedUp'=>$usedup,'activeSessions'=>count($sessions)
    ]];
  }

  if ($action === 'listCodes') {
    // filter
    $q = trim(strval($in['q'] ?? ''));
    $items = [];
    foreach ($codes as $code => $c) {
      if ($q !== '' && stripos($code, $q) === false) continue;
      $items[] = array_merge(['code'=>$code], $c);
    }
    usort($items, function($a,$b){
      return intval($b['createdAt'] ?? 0) <=> intval($a['createdAt'] ?? 0);
    });
    return ['ok'=>true,'codes'=>$items];
  }

  if ($action === 'createCodes') {
    $count = intval($in['count'] ?? 1);
    if ($count < 1) $count = 1;
    if ($count > 500) $count = 500;

    $prefix = strtoupper(trim(strval($in['prefix'] ?? 'H')));
    if ($prefix === '') $prefix = 'H';
    $maxUses = intval($in['maxUses'] ?? 1);
    if ($maxUses < 1) $maxUses = 1;

    $grantDays = intval($in['grantDays'] ?? ($cfg['DEFAULT_GRANT_DAYS'] ?? 3));
    if ($grantDays < 1) $grantDays = 3;

    $expiresAt = intval($in['expiresAt'] ?? 0); // unix ts; 0 = never
    $note = trim(strval($in['note'] ?? ''));
    $scope = trim(strval($in['scope'] ?? 'all')); // for future use

    $t = now();
    $created = [];
    for ($i=0; $i<$count; $i++){
      do {
        $code = random_code($prefix);
      } while (isset($codes[$code]));
      $codes[$code] = [
        'createdAt' => $t,
        'expiresAt' => $expiresAt,
        'maxUses' => $maxUses,
        'uses' => 0,
        'lastUsedAt' => 0,
        'disabled' => false,
        'grantDays' => $grantDays,
        'meta' => ['note'=>$note,'scope'=>$scope],
      ];
      $created[] = $code;
    }

    $codesDb['codes'] = $codes;
    $sessionsDb['sessions'] = $sessions;
    save_json_file_atomic($cfg['CODES_FILE'], $codesDb);
    save_json_file_atomic($cfg['SESSIONS_FILE'], $sessionsDb);

    return ['ok'=>true,'created'=>$created];
  }

  if ($action === 'toggleCode') {
    $code = trim(strval($in['code'] ?? ''));
    $disabled = boolval($in['disabled'] ?? false);
    if ($code === '' || !isset($codes[$code])) return ['ok'=>false,'error'=>'not_found'];
    $codes[$code]['disabled'] = $disabled;
    $codesDb['codes'] = $codes;
    save_json_file_atomic($cfg['CODES_FILE'], $codesDb);
    return ['ok'=>true];
  }

  if ($action === 'deleteCode') {
    $code = trim(strval($in['code'] ?? ''));
    if ($code === '' || !isset($codes[$code])) return ['ok'=>false,'error'=>'not_found'];
    unset($codes[$code]);
    // 同时销毁相关会话
    foreach ($sessions as $sid => $s) {
      if (strval($s['code'] ?? '') === $code) unset($sessions[$sid]);
    }
    $codesDb['codes'] = $codes;
    $sessionsDb['sessions'] = $sessions;
    save_json_file_atomic($cfg['CODES_FILE'], $codesDb);
    save_json_file_atomic($cfg['SESSIONS_FILE'], $sessionsDb);
    return ['ok'=>true];
  }


  if ($action === 'getSiteSettings') {
    $path = __DIR__ . '/../data/site.json';
    $settings = load_json_file($path);
    if (!$settings) {
      $settings = [
        'siteName'=>'心象研究所',
        'siteSub'=>'测评 · 性格 · 关系 · 职业',
        'icp'=>'请填写ICP备案号',
        'about'=>'/about/',
        'faq'=>'/faq/',
        'sitemap'=>'/sitemap-page/',
        'analyticsCode'=>''
      ];
    }
    return ['ok'=>true,'settings'=>$settings];
  }

  if ($action === 'updateSiteSettings') {
    $path = __DIR__ . '/../data/site.json';
    $settings = [
      'siteName' => trim(strval($in['siteName'] ?? '心象研究所')),
      'siteSub' => trim(strval($in['siteSub'] ?? '测评 · 性格 · 关系 · 职业')),
      'icp' => trim(strval($in['icp'] ?? '')),
      'about' => trim(strval($in['about'] ?? '/about/')),
      'faq' => trim(strval($in['faq'] ?? '/faq/')),
      'sitemap' => trim(strval($in['sitemap'] ?? '/sitemap-page/')),
      'analyticsCode' => strval($in['analyticsCode'] ?? ''),
    ];
    save_json_file_atomic($path, $settings);
    return ['ok'=>true,'settings'=>$settings];
  }

  if ($action === 'destroyExpired') {
    $t = now();
    $removed = 0;
    foreach ($codes as $code => $c) {
      $exp = intval($c['expiresAt'] ?? 0);
      if ($exp > 0 && $exp < $t) { unset($codes[$code]); $removed++; }
    }
    // 清理会话
    cleanup_expired_sessions($sessions);

    $codesDb['codes'] = $codes;
    $sessionsDb['sessions'] = $sessions;
    save_json_file_atomic($cfg['CODES_FILE'], $codesDb);
    save_json_file_atomic($cfg['SESSIONS_FILE'], $sessionsDb);

    return ['ok'=>true,'removed'=>$removed,'activeSessions'=>count($sessions)];
  }

  return ['ok'=>false,'error'=>'unknown_action'];
});

if (!$out['ok'] && isset($out['error'])) respond($out, 400);
respond($out);
