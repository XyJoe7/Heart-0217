<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

// Rate limit configuration
const ADMIN_GENERAL_RATE_LIMIT = 120;
const ADMIN_LOGIN_RATE_LIMIT = 5;
const ADMIN_LOGIN_WINDOW = 900; // 15 minutes

// Apply security headers and rate limiting
Security::addSecurityHeaders();

$ip = ip();
$rateLimitFile = __DIR__ . '/../data/ratelimit.json';
Security::loadRateLimitData($rateLimitFile);

// General rate limit for admin endpoint
if (!Security::checkRateLimit("admin:general:{$ip}", ADMIN_GENERAL_RATE_LIMIT, 60)) {
    Security::saveRateLimitData($rateLimitFile);
    respond(['ok'=>false,'error'=>'rate_limit_exceeded','message'=>'请求过于频繁'], 429);
}

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
  // Rate limit login attempts more strictly
  $loginKey = "login:admin:{$ip}";
  
  if (!Security::checkRateLimit($loginKey, ADMIN_LOGIN_RATE_LIMIT, ADMIN_LOGIN_WINDOW)) {
    Security::logSecurityEvent('admin_login_rate_limited', ['ip' => $ip]);
    Security::saveRateLimitData($rateLimitFile);
    respond(['ok'=>false,'error'=>'rate_limit_exceeded','message'=>'登录尝试次数过多，请15分钟后再试'], 429);
  }
  
  $password = strval($in['password'] ?? '');
  if ($password === '') {
    Security::saveRateLimitData($rateLimitFile);
    respond(['ok'=>false,'error'=>'missing_password','message'=>'请输入密码'], 400);
  }
  
  // Use timing-safe comparison for password
  if (!Security::timingSafeCompare(strval($cfg['ADMIN_PASSWORD']), $password)) {
    Security::logSecurityEvent('admin_login_failed', ['ip' => $ip]);
    Security::saveRateLimitData($rateLimitFile);
    respond(['ok'=>false,'error'=>'bad_password','message'=>'密码错误'], 403);
  }
  
  Security::logSecurityEvent('admin_login_success', ['ip' => $ip]);
  Security::saveRateLimitData($rateLimitFile);
  
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

  // ═══════════════════════════════════════════
  // 量表编辑（tests CRUD）
  // ═══════════════════════════════════════════

  $testsFile = __DIR__ . '/../data/tests.json';

  if ($action === 'listTests') {
    $tests = load_json_file($testsFile);
    if (!is_array($tests) || !isset($tests[0])) $tests = [];
    $items = array_map(function($t){
      // Count base questions
      $baseQuestions = count($t['questions'] ?? []);
      
      // If there are variants, get the max question count from variants
      $variantQuestions = 0;
      if (isset($t['variants']) && is_array($t['variants'])) {
        foreach ($t['variants'] as $v) {
          $count = count($v['questions'] ?? []);
          if ($count > $variantQuestions) {
            $variantQuestions = $count;
          }
        }
      }
      
      // Use the higher count (variants or base)
      $questionCount = max($baseQuestions, $variantQuestions);
      
      return [
        'id' => $t['id'] ?? '',
        'title' => $t['title'] ?? '',
        'category' => $t['category'] ?? '',
        'tags' => $t['tags'] ?? [],
        'questionCount' => $questionCount,
        'estimated' => $t['estimated'] ?? 0,
      ];
    }, $tests);
    return ['ok'=>true,'tests'=>$items,'total'=>count($items)];
  }

  if ($action === 'getTest') {
    $id = trim(strval($in['id'] ?? ''));
    if ($id === '') return ['ok'=>false,'error'=>'missing_id'];
    $tests = load_json_file($testsFile);
    if (!is_array($tests) || !isset($tests[0])) $tests = [];
    foreach ($tests as $t) {
      if (($t['id'] ?? '') === $id) return ['ok'=>true,'test'=>$t];
    }
    return ['ok'=>false,'error'=>'not_found'];
  }

  if ($action === 'updateTest') {
    $id = trim(strval($in['id'] ?? ''));
    if ($id === '') return ['ok'=>false,'error'=>'missing_id'];
    $testData = $in['test'] ?? null;
    if (!is_array($testData)) return ['ok'=>false,'error'=>'invalid_test_data'];
    $tests = load_json_file($testsFile);
    if (!is_array($tests) || !isset($tests[0])) $tests = [];
    $found = false;
    foreach ($tests as $i => $t) {
      if (($t['id'] ?? '') === $id) {
        $testData['id'] = $id; // preserve original ID
        $tests[$i] = $testData;
        $found = true;
        break;
      }
    }
    if (!$found) return ['ok'=>false,'error'=>'not_found'];
    save_json_file_atomic($testsFile, $tests);
    rebuild_tests_js($testsFile);
    return ['ok'=>true];
  }

  if ($action === 'addTest') {
    $testData = $in['test'] ?? null;
    if (!is_array($testData)) return ['ok'=>false,'error'=>'invalid_test_data'];
    $id = trim(strval($testData['id'] ?? ''));
    // Enhanced validation: check format, length, and dangerous patterns
    if ($id === '' || 
        strlen($id) > 50 || 
        !preg_match('/^[a-z0-9_-]+$/', $id) ||
        strpos($id, '..') !== false ||
        $id[0] === '.') {
      return ['ok'=>false,'error'=>'invalid_id'];
    }
    $tests = load_json_file($testsFile);
    if (!is_array($tests) || !isset($tests[0])) $tests = [];
    foreach ($tests as $t) {
      if (($t['id'] ?? '') === $id) return ['ok'=>false,'error'=>'id_exists'];
    }
    $tests[] = $testData;
    save_json_file_atomic($testsFile, $tests);
    rebuild_tests_js($testsFile);
    return ['ok'=>true];
  }

  if ($action === 'deleteTest') {
    $id = trim(strval($in['id'] ?? ''));
    if ($id === '') return ['ok'=>false,'error'=>'missing_id'];
    $tests = load_json_file($testsFile);
    if (!is_array($tests) || !isset($tests[0])) $tests = [];
    $newTests = array_values(array_filter($tests, function($t) use ($id) {
      return ($t['id'] ?? '') !== $id;
    }));
    if (count($newTests) === count($tests)) return ['ok'=>false,'error'=>'not_found'];
    save_json_file_atomic($testsFile, $newTests);
    rebuild_tests_js($testsFile);
    return ['ok'=>true];
  }

  if ($action === 'exportTest') {
    $id = trim(strval($in['id'] ?? ''));
    if ($id === '') return ['ok'=>false,'error'=>'missing_id'];
    $tests = load_json_file($testsFile);
    if (!is_array($tests) || !isset($tests[0])) $tests = [];
    foreach ($tests as $t) {
      if (($t['id'] ?? '') === $id) {
        // Return the test data as JSON for download
        return ['ok'=>true,'test'=>$t,'filename'=>$id . '_export.json'];
      }
    }
    return ['ok'=>false,'error'=>'not_found'];
  }

  if ($action === 'importTest') {
    $testData = $in['test'] ?? null;
    if (!is_array($testData)) return ['ok'=>false,'error'=>'invalid_test_data'];
    $id = trim(strval($testData['id'] ?? ''));
    // Enhanced validation: check format, length, and dangerous patterns
    if ($id === '' || 
        strlen($id) > 50 || 
        !preg_match('/^[a-z0-9_-]+$/', $id) ||
        strpos($id, '..') !== false ||
        $id[0] === '.') {
      return ['ok'=>false,'error'=>'invalid_id'];
    }
    
    $tests = load_json_file($testsFile);
    if (!is_array($tests) || !isset($tests[0])) $tests = [];
    
    // Check if ID already exists
    $found = false;
    foreach ($tests as $i => $t) {
      if (($t['id'] ?? '') === $id) {
        $tests[$i] = $testData;
        $found = true;
        break;
      }
    }
    
    if (!$found) {
      $tests[] = $testData;
    }
    
    save_json_file_atomic($testsFile, $tests);
    rebuild_tests_js($testsFile);
    return ['ok'=>true,'imported'=>$id,'updated'=>$found];
  }

  // ═══════════════════════════════════════════
  // 分销功能（Referral / Affiliate）
  // ═══════════════════════════════════════════

  $refFile = __DIR__ . '/../data/referrals.json';

  if ($action === 'listReferrers') {
    $refDb = load_json_file($refFile);
    $referrers = $refDb['referrers'] ?? [];
    $items = [];
    foreach ($referrers as $code => $r) {
      $items[] = array_merge(['code'=>$code], $r);
    }
    usort($items, function($a,$b){
      return intval($b['createdAt'] ?? 0) <=> intval($a['createdAt'] ?? 0);
    });
    return ['ok'=>true,'referrers'=>$items];
  }

  if ($action === 'createReferrer') {
    $name = trim(strval($in['name'] ?? ''));
    $commissionPct = intval($in['commissionPct'] ?? 10);
    if ($name === '') return ['ok'=>false,'error'=>'missing_name'];
    if ($commissionPct < 0 || $commissionPct > 100) $commissionPct = 10;
    $refDb = load_json_file($refFile);
    $referrers = $refDb['referrers'] ?? [];
    // Generate a short referral code
    $refCode = 'REF-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $referrers[$refCode] = [
      'name' => $name,
      'commissionPct' => $commissionPct,
      'createdAt' => now(),
      'totalOrders' => 0,
      'totalRevenue' => 0,
      'note' => trim(strval($in['note'] ?? '')),
      'disabled' => false,
    ];
    $refDb['referrers'] = $referrers;
    save_json_file_atomic($refFile, $refDb);
    return ['ok'=>true,'referralCode'=>$refCode];
  }

  if ($action === 'toggleReferrer') {
    $code = trim(strval($in['code'] ?? ''));
    $disabled = boolval($in['disabled'] ?? false);
    $refDb = load_json_file($refFile);
    $referrers = $refDb['referrers'] ?? [];
    if (!isset($referrers[$code])) return ['ok'=>false,'error'=>'not_found'];
    $referrers[$code]['disabled'] = $disabled;
    $refDb['referrers'] = $referrers;
    save_json_file_atomic($refFile, $refDb);
    return ['ok'=>true];
  }

  if ($action === 'deleteReferrer') {
    $code = trim(strval($in['code'] ?? ''));
    $refDb = load_json_file($refFile);
    $referrers = $refDb['referrers'] ?? [];
    if (!isset($referrers[$code])) return ['ok'=>false,'error'=>'not_found'];
    unset($referrers[$code]);
    $refDb['referrers'] = $referrers;
    save_json_file_atomic($refFile, $refDb);
    return ['ok'=>true];
  }

  // ═══════════════════════════════════════════
  // 运营数据汇总（Analytics Dashboard）
  // ═══════════════════════════════════════════

  if ($action === 'getAnalytics') {
    $t = now();
    $today = strtotime('today');
    $weekAgo = $t - 7*86400;
    $monthAgo = $t - 30*86400;

    // Code stats
    $totalCodes = count($codes);
    $totalUses = 0;
    $activeCodes = 0;
    $sourceStats = [];

    foreach ($codes as $code => $c) {
      $uses = intval($c['uses'] ?? 0);
      $totalUses += $uses;
      $exp = intval($c['expiresAt'] ?? 0);
      $max = intval($c['maxUses'] ?? 1);
      if (!($c['disabled'] ?? false) && ($exp === 0 || $exp > $t) && $uses < $max) $activeCodes++;

      // Source tracking
      $source = strval($c['meta']['source'] ?? 'direct');
      if (!isset($sourceStats[$source])) $sourceStats[$source] = 0;
      $sourceStats[$source]++;
    }

    // Session stats (count by issuedAt for accurate time-based metrics)
    $totalSessions = count($sessions);
    $todaySessions = 0;
    $weekSessions = 0;
    $monthSessions = 0;
    foreach ($sessions as $s) {
      $issued = intval($s['issuedAt'] ?? 0);
      if ($issued >= $today) $todaySessions++;
      if ($issued >= $weekAgo) $weekSessions++;
      if ($issued >= $monthAgo) $monthSessions++;
    }

    // Referral stats
    $refDb = load_json_file($refFile);
    $referrers = $refDb['referrers'] ?? [];
    $refLogs = $refDb['logs'] ?? [];
    $totalReferrals = count($refLogs);
    $todayReferrals = 0;
    foreach ($refLogs as $log) {
      if (intval($log['time'] ?? 0) >= $today) $todayReferrals++;
    }

    // Source distribution from sessions
    $sessionSources = [];
    foreach ($sessions as $s) {
      $src = strval($s['source'] ?? 'direct');
      if (!isset($sessionSources[$src])) $sessionSources[$src] = 0;
      $sessionSources[$src]++;
    }

    // Analytics events
    $analyticsFile = __DIR__ . '/../data/analytics.json';
    $analyticsDb = load_json_file($analyticsFile);
    $events = $analyticsDb['events'] ?? [];
    $testCompletions = 0;
    $todayCompletions = 0;
    $testPopularity = [];
    foreach ($events as $evt) {
      if (($evt['type'] ?? '') === 'test_complete') {
        $testCompletions++;
        if (intval($evt['time'] ?? 0) >= $today) $todayCompletions++;
        $tid = $evt['testId'] ?? 'unknown';
        if (!isset($testPopularity[$tid])) $testPopularity[$tid] = 0;
        $testPopularity[$tid]++;
      }
    }
    arsort($testPopularity);

    return ['ok'=>true,'analytics'=>[
      'codes' => [
        'total'=>$totalCodes, 'active'=>$activeCodes,
        'totalUses'=>$totalUses,
      ],
      'sessions' => [
        'active'=>$totalSessions, 'today'=>$todaySessions,
        'week'=>$weekSessions, 'month'=>$monthSessions,
      ],
      'referrals' => [
        'total'=>$totalReferrals, 'today'=>$todayReferrals,
        'referrerCount'=>count($referrers),
      ],
      'sources' => $sessionSources,
      'testCompletions' => [
        'total'=>$testCompletions, 'today'=>$todayCompletions,
      ],
      'popularTests' => array_slice($testPopularity, 0, 10, true),
    ]];
  }

  // ═══════════════════════════════════════════
  // 来源追踪（Source Tracking）
  // ═══════════════════════════════════════════

  if ($action === 'getSourceReport') {
    $sessionSources = [];
    $refSources = [];
    $utmCampaigns = [];

    foreach ($sessions as $sid => $s) {
      $src = strval($s['source'] ?? 'direct');
      if (!isset($sessionSources[$src])) $sessionSources[$src] = 0;
      $sessionSources[$src]++;

      $ref = strval($s['refCode'] ?? '');
      if ($ref !== '') {
        if (!isset($refSources[$ref])) $refSources[$ref] = 0;
        $refSources[$ref]++;
      }

      $campaign = strval($s['utmCampaign'] ?? '');
      if ($campaign !== '') {
        if (!isset($utmCampaigns[$campaign])) $utmCampaigns[$campaign] = 0;
        $utmCampaigns[$campaign]++;
      }
    }

    arsort($sessionSources);
    arsort($refSources);
    arsort($utmCampaigns);

    return ['ok'=>true,'sourceReport'=>[
      'bySource'=>$sessionSources,
      'byReferral'=>$refSources,
      'byCampaign'=>$utmCampaigns,
      'totalSessions'=>count($sessions),
    ]];
  }

  // ═══════════════════════════════════════════
  // SEO 优化设置
  // ═══════════════════════════════════════════

  if ($action === 'getSeoSettings') {
    $seoPath = __DIR__ . '/../data/site.json';
    $siteData = load_json_file($seoPath);
    return ['ok'=>true,'seo'=>[
      'globalTitle' => $siteData['seoTitle'] ?? ($siteData['siteName'] ?? '心象研究所'),
      'globalDescription' => $siteData['seoDescription'] ?? '专业心理测评平台，涵盖情绪量表、人格测试、职业天赋、恋爱关系等多种测评工具。',
      'globalKeywords' => $siteData['seoKeywords'] ?? '心理测评,性格测试,MBTI,SCL-90,职业测试,情绪评估',
      'ogImage' => $siteData['ogImage'] ?? '',
      'robotsExtra' => $siteData['robotsExtra'] ?? '',
      'autoSitemap' => $siteData['autoSitemap'] ?? true,
      'sitemapFreq' => $siteData['sitemapFreq'] ?? 'weekly',
      'canonical' => $siteData['canonical'] ?? '',
    ]];
  }

  if ($action === 'updateSeoSettings') {
    $seoPath = __DIR__ . '/../data/site.json';
    $siteData = load_json_file($seoPath);
    $siteData['seoTitle'] = trim(strval($in['globalTitle'] ?? $siteData['seoTitle'] ?? ''));
    $siteData['seoDescription'] = trim(strval($in['globalDescription'] ?? $siteData['seoDescription'] ?? ''));
    $siteData['seoKeywords'] = trim(strval($in['globalKeywords'] ?? $siteData['seoKeywords'] ?? ''));
    $siteData['ogImage'] = trim(strval($in['ogImage'] ?? $siteData['ogImage'] ?? ''));
    $siteData['robotsExtra'] = trim(strval($in['robotsExtra'] ?? $siteData['robotsExtra'] ?? ''));
    $siteData['autoSitemap'] = boolval($in['autoSitemap'] ?? $siteData['autoSitemap'] ?? true);
    $siteData['sitemapFreq'] = trim(strval($in['sitemapFreq'] ?? $siteData['sitemapFreq'] ?? 'weekly'));
    $siteData['canonical'] = trim(strval($in['canonical'] ?? $siteData['canonical'] ?? ''));
    save_json_file_atomic($seoPath, $siteData);

    // Auto-regenerate sitemap if enabled
    if ($siteData['autoSitemap']) {
      rebuild_sitemap($siteData);
    }

    return ['ok'=>true];
  }

  // ═══════════════════════════════════════════
  // 轮播图管理
  // ═══════════════════════════════════════════
  
  if ($action === 'getCarousel') {
    $seoPath = __DIR__ . '/../data/site.json';
    $siteData = load_json_file($seoPath);
    $carousel = $siteData['carousel'] ?? [
      ['bg'=>'linear-gradient(135deg,#4facfe,#00f2fe)','title'=>'专业心理测评平台','sub'=>'涵盖情绪、人格、恋爱、职业等多维度心理自测','image'=>'','description'=>'涵盖情绪、人格、恋爱、职业等多维度心理自测','link'=>''],
      ['bg'=>'linear-gradient(135deg,#f093fb,#f5576c)','title'=>'恋爱 · 人格 · 情绪','sub'=>'40+ 科学量表，随时随地测评','image'=>'','description'=>'40+ 科学量表，随时随地测评','link'=>''],
      ['bg'=>'linear-gradient(135deg,#667eea,#764ba2)','title'=>'了解自己，才能更好前行','sub'=>'结果仅供自我觉察，请对自己保持善意','image'=>'','description'=>'结果仅供自我觉察，请对自己保持善意','link'=>'']
    ];
    return ['ok'=>true,'carousel'=>$carousel];
  }

  if ($action === 'updateCarousel') {
    $seoPath = __DIR__ . '/../data/site.json';
    $siteData = load_json_file($seoPath);
    $carousel = $in['carousel'] ?? [];
    $siteData['carousel'] = $carousel;
    save_json_file_atomic($seoPath, $siteData);
    return ['ok'=>true];
  }

  // ═══════════════════════════════════════════
  // 精选内容管理（热门、新品、推荐）
  // ═══════════════════════════════════════════
  
  if ($action === 'getFeaturedContent') {
    $seoPath = __DIR__ . '/../data/site.json';
    $siteData = load_json_file($seoPath);
    $featured = $siteData['featured'] ?? [
      'hot' => ['enabled'=>true,'title'=>'🔥 热门爆款','items'=>[]],
      'new' => ['enabled'=>true,'title'=>'🆕 新品首发','items'=>[]],
      'recommended' => ['enabled'=>true,'title'=>'⭐ 精选推荐','items'=>[]]
    ];
    return ['ok'=>true,'featured'=>$featured];
  }

  if ($action === 'updateFeaturedContent') {
    $seoPath = __DIR__ . '/../data/site.json';
    $siteData = load_json_file($seoPath);
    $featured = $in['featured'] ?? [];
    $siteData['featured'] = $featured;
    save_json_file_atomic($seoPath, $siteData);
    return ['ok'=>true];
  }

  // ═══════════════════════════════════════════
  // 分析事件记录（用于前端上报）
  // ═══════════════════════════════════════════

  if ($action === 'recordEvent') {
    $analyticsFile = __DIR__ . '/../data/analytics.json';
    $analyticsDb = load_json_file($analyticsFile);
    $events = $analyticsDb['events'] ?? [];
    $events[] = [
      'type' => trim(strval($in['eventType'] ?? 'unknown')),
      'testId' => trim(strval($in['testId'] ?? '')),
      'source' => trim(strval($in['source'] ?? '')),
      'time' => now(),
    ];
    // Keep only last 10000 events
    if (count($events) > 10000) {
      $events = array_slice($events, -10000);
    }
    $analyticsDb['events'] = $events;
    save_json_file_atomic($analyticsFile, $analyticsDb);
    return ['ok'=>true];
  }

  // ═══════════════════════════════════════════
  // 分类管理（Categories Management）
  // ═══════════════════════════════════════════
  
  if ($action === 'getCategories') {
    $seoPath = __DIR__ . '/../data/site.json';
    $siteData = load_json_file($seoPath);
    $categories = $siteData['categories'] ?? [
      ['id'=>'emotion','name'=>'情绪量表','icon'=>'😊','image'=>''],
      ['id'=>'personality','name'=>'人格性格','icon'=>'🎭','image'=>''],
      ['id'=>'relationship','name'=>'恋爱关系','icon'=>'💕','image'=>''],
      ['id'=>'career','name'=>'职业天赋','icon'=>'💼','image'=>''],
      ['id'=>'self','name'=>'自我探索','icon'=>'🔍','image'=>''],
      ['id'=>'fun','name'=>'趣味外貌','icon'=>'✨','image'=>'']
    ];
    return ['ok'=>true,'categories'=>$categories];
  }

  if ($action === 'updateCategories') {
    $seoPath = __DIR__ . '/../data/site.json';
    $siteData = load_json_file($seoPath);
    $categories = $in['categories'] ?? [];
    $siteData['categories'] = $categories;
    save_json_file_atomic($seoPath, $siteData);
    return ['ok'=>true];
  }

  return ['ok'=>false,'error'=>'unknown_action'];
});

if (!$out['ok'] && isset($out['error'])) respond($out, 400);
respond($out);
