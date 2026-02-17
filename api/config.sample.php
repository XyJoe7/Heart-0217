<?php
// 复制本文件为 config.php 并修改配置。
// 强烈建议：随机生成 SECRET_KEY，长度 32+，并设置强口令 ADMIN_PASSWORD。

return [
  // 用于签名 token 的密钥（务必修改）
  'SECRET_KEY' => 'REPLACE_ME_WITH_RANDOM_SECRET',

  // 后台管理口令（务必修改）
  'ADMIN_PASSWORD' => 'REPLACE_ME_WITH_STRONG_PASSWORD',

  // 激活后默认有效期（天）
  'DEFAULT_GRANT_DAYS' => 3,

  // 是否绑定 User-Agent（减少分享复用；极少数浏览器可能更换 UA 导致失效，可关）
  'BIND_UA' => true,

  // 预留：小程序/第三方平台对接参数（后续可用于服务端验签、用户绑定等）
  'MINIPROGRAM_APPID' => '',
  'MINIPROGRAM_SECRET' => '',

  // 数据文件路径（一般不需要改）
  'CODES_FILE' => __DIR__ . '/../data/codes.json',
  'SESSIONS_FILE' => __DIR__ . '/../data/sessions.json',
];
