<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';

// 预留接口：用于后续对接微信小程序 / 其他平台。
// 建议实现：签名校验、用户标识绑定、激活态同步、数据上报。
respond([
  'ok' => false,
  'error' => 'not_implemented',
  'message' => 'platform bridge reserved endpoint'
], 501);
