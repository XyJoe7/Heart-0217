<?php
declare(strict_types=1);

function cfg(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $path = __DIR__ . '/config.php';
  if (!file_exists($path)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'missing_config','message'=>'缺少 api/config.php（请由 config.sample.php 复制并修改）'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $cfg = require $path;
  return $cfg;
}

function json_input(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function respond(array $data, int $status=200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function now(): int { return time(); }

function ua(): string {
  return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

function ua_hash(string $ua): string {
  return hash('sha256', $ua);
}

function ip(): string {
  // 注意：真实 IP 需要结合反代配置；这里只做记录展示，不做强绑定
  return $_SERVER['REMOTE_ADDR'] ?? '';
}

function random_code(string $prefix='H', int $groups=4, int $len=4): string {
  // 形如 H-ABCD-EF12-9KLM
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $parts = [$prefix];
  for ($g=0; $g<$groups; $g++){
    $s = '';
    for ($i=0; $i<$len; $i++){
      $s .= $alphabet[random_int(0, strlen($alphabet)-1)];
    }
    $parts[] = $s;
  }
  return implode('-', $parts);
}

function load_json_file(string $path): array {
  if (!file_exists($path)) return [];
  $raw = file_get_contents($path);
  if ($raw === false) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function save_json_file_atomic(string $path, array $data): void {
  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) $json = '{}';
  file_put_contents($tmp, $json, LOCK_EX);
  rename($tmp, $path);
}

function with_lock(string $lockPath, callable $fn) {
  $fp = fopen($lockPath, 'c+');
  if (!$fp) throw new Exception('lock_failed');
  try{
    if (!flock($fp, LOCK_EX)) throw new Exception('lock_failed');
    return $fn();
  } finally {
    flock($fp, LOCK_UN);
    fclose($fp);
  }
}

// Token: base64url(payload).base64url(hmac)
function b64url_encode(string $s): string {
  return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64url_decode(string $s): string {
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4-$pad);
  return base64_decode(strtr($s, '-_', '+/')) ?: '';
}

function sign_token(array $payload, string $secret): string {
  $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
  $sig = hash_hmac('sha256', $p, $secret, true);
  return $p . '.' . b64url_encode($sig);
}

function verify_token(string $token, string $secret): ?array {
  $parts = explode('.', $token);
  if (count($parts) !== 2) return null;
  [$p, $s] = $parts;
  $sig = b64url_decode($s);
  $expect = hash_hmac('sha256', $p, $secret, true);
  if (!hash_equals($expect, $sig)) return null;
  $payload = json_decode(b64url_decode($p), true);
  return is_array($payload) ? $payload : null;
}

function require_method(string $method): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
    respond(['ok'=>false,'error'=>'method_not_allowed'], 405);
  }
}

function allow_cors_same_origin(): void {
  // 同域部署无需 CORS；这里保持最小化
}

function cleanup_expired_sessions(array &$sessions): int {
  $n = 0;
  $t = now();
  foreach ($sessions as $sid => $s) {
    $exp = intval($s['expiresAt'] ?? 0);
    if ($exp > 0 && $exp < $t) { unset($sessions[$sid]); $n++; }
  }
  return $n;
}

/**
 * Rebuild assets/tests.js from data/tests.json
 */
function rebuild_tests_js(string $testsJsonPath): void {
  $tests = load_json_file($testsJsonPath);
  if (!is_array($tests) || empty($tests)) return;
  $json = json_encode($tests, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) return;
  $jsContent = "const TESTS = " . $json . ";\n";
  $jsPath = dirname($testsJsonPath) . '/../assets/tests.js';
  $realPath = realpath(dirname($jsPath));
  if ($realPath === false) return;
  $target = $realPath . '/tests.js';
  file_put_contents($target, $jsContent, LOCK_EX);
}

/**
 * Rebuild sitemap.xml from tests and site settings
 */
function rebuild_sitemap(array $siteData): void {
  $testsFile = __DIR__ . '/../data/tests.json';
  $tests = load_json_file($testsFile);
  if (!is_array($tests) || empty($tests)) return;

  $freq = $siteData['sitemapFreq'] ?? 'weekly';
  $baseUrl = rtrim($siteData['canonical'] ?? '', '/');

  $urls = [
    ['loc'=>'/', 'priority'=>'1.0', 'changefreq'=>$freq],
    ['loc'=>'/code/', 'priority'=>'0.8', 'changefreq'=>'monthly'],
    ['loc'=>'/build/', 'priority'=>'0.5', 'changefreq'=>'monthly'],
    ['loc'=>'/cooperate/', 'priority'=>'0.5', 'changefreq'=>'monthly'],
    ['loc'=>'/about/', 'priority'=>'0.5', 'changefreq'=>'monthly'],
    ['loc'=>'/faq/', 'priority'=>'0.5', 'changefreq'=>'monthly'],
    ['loc'=>'/legal/terms/', 'priority'=>'0.3', 'changefreq'=>'yearly'],
    ['loc'=>'/legal/privacy/', 'priority'=>'0.3', 'changefreq'=>'yearly'],
    ['loc'=>'/sitemap-page/', 'priority'=>'0.3', 'changefreq'=>'monthly'],
  ];

  foreach ($tests as $t) {
    $id = $t['id'] ?? '';
    if ($id === '') continue;
    $urls[] = ['loc'=>'/view/' . $id . '/', 'priority'=>'0.7', 'changefreq'=>$freq];
  }

  $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
  foreach ($urls as $u) {
    $loc = $baseUrl . $u['loc'];
    $xml .= "  <url>\n";
    $xml .= "    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    $xml .= "    <changefreq>" . $u['changefreq'] . "</changefreq>\n";
    $xml .= "    <priority>" . $u['priority'] . "</priority>\n";
    $xml .= "  </url>\n";
  }
  $xml .= "</urlset>\n";

  $sitemapPath = __DIR__ . '/../sitemap.xml';
  file_put_contents($sitemapPath, $xml, LOCK_EX);
}
