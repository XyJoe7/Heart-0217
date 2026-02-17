<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

// Apply security headers and rate limiting
Security::addSecurityHeaders();

$ip = ip();
$rateLimitFile = __DIR__ . '/../data/ratelimit.json';
Security::loadRateLimitData($rateLimitFile);

if (!Security::checkRateLimit("track:{$ip}", 300, 60)) {
    Security::saveRateLimitData($rateLimitFile);
    respond(['ok'=>false,'error'=>'rate_limit_exceeded','message'=>'请求过于频繁'], 429);
}

require_method('POST');
$in = json_input();

// Sanitize inputs
$eventType = Security::sanitizeString(trim(strval($in['eventType'] ?? '')), 50);
$testId = Security::sanitizeString(trim(strval($in['testId'] ?? '')), 100);
$source = Security::sanitizeString(trim(strval($in['source'] ?? '')), 100);

if ($eventType === '') respond(['ok'=>false,'error'=>'missing_event_type'], 400);

$analyticsFile = __DIR__ . '/../data/analytics.json';
$lock = __DIR__ . '/../data/.lock';

$out = with_lock($lock, function() use ($analyticsFile, $eventType, $testId, $source){
  $analyticsDb = load_json_file($analyticsFile);
  $events = $analyticsDb['events'] ?? [];
  $events[] = [
    'type' => $eventType,
    'testId' => $testId,
    'source' => $source,
    'time' => now(),
  ];
  // Keep only last 10000 events
  if (count($events) > 10000) {
    $events = array_slice($events, -10000);
  }
  $analyticsDb['events'] = $events;
  save_json_file_atomic($analyticsFile, $analyticsDb);
  return ['ok'=>true];
});

Security::saveRateLimitData($rateLimitFile);
respond($out);
