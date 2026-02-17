<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';
$cfg = cfg();
$path = __DIR__ . '/../data/site.json';
$data = load_json_file($path);
if (!$data) {
  $data = [
    'siteName'=>'心象研究所',
    'siteSub'=>'测评 · 性格 · 关系 · 职业',
    'icp'=>'请填写ICP备案号',
    'about'=>'/about/',
    'faq'=>'/faq/',
    'sitemap'=>'/sitemap-page/',
    'analyticsCode'=>''
  ];
}
$freePreview = intval($cfg['FREE_PREVIEW_QUESTIONS'] ?? 3);
$data['freePreviewQuestions'] = $freePreview;
respond(['ok'=>true,'settings'=>$data]);
