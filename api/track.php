<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

require_method('POST');
$in = json_input();

$eventType = trim(strval($in['eventType'] ?? ''));
$testId = trim(strval($in['testId'] ?? ''));
$source = trim(strval($in['source'] ?? ''));

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

respond($out);
