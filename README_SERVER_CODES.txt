# 激活码（服务端核销）版本说明

## 你获得的能力（C 方案）
- 自主生成激活码（支持批量）
- 查看激活码是否被使用、剩余次数
- 设置激活码过期时间（expiresAt）
- 设置激活后授权有效期（grantDays，默认 3 天）
- 停用/启用/删除激活码（删除会同时销毁相关会话）
- 设备绑定（默认绑定 User-Agent，减少分享复用，可在 api/config.php 关闭）

## 安全增强（新增）
✅ 企业级安全特性
- 频率限制：防止暴力破解和 DDoS 攻击
- 输入验证：防止注入攻击和 XSS
- 安全日志：记录所有安全事件
- 安全响应头：CSP、HSTS、X-Frame-Options 等
- 密码保护：使用 Argon2id 算法（建议更新密码）
- 请求日志：完整的访问记录

详细文档请参阅：SECURITY_ENHANCEMENTS.md

## 部署（宝塔）
1. 站点需启用 PHP（建议 PHP 8.x）
2. 上传并解压到网站运行目录（与 index.html 同级）
3. 复制并修改：
   - api/config.sample.php -> api/config.php
   - 设置 SECRET_KEY 与 ADMIN_PASSWORD
4. 给 data 目录写权限（建议属主 www:www）
5. 在宝塔 Nginx 配置加入：BAOTA_NGINX_SNIPPET.txt 的内容
6. 访问：
   - 前台激活页：/code
   - 管理后台：/XyJoe7（建议仅内部使用，不从前台入口暴露）

7. 小程序预留接口：api/platform_bridge.sample.php（可按你服务端规范实现签名校验与激活态同步）
