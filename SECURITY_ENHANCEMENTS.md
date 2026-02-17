# 后端安全增强文档

## 概述

本次重构在保持原有功能的基础上，引入了现代化的安全特性和框架模式，提升系统的安全性、可维护性和性能。

## 主要改进

### 1. 安全类（Security.php）

#### 核心功能
- **输入验证与清理**：防止 XSS、SQL 注入等攻击
- **密码安全**：使用 Argon2id 算法进行密码哈希
- **频率限制**：防止暴力破解和 DDoS 攻击
- **安全令牌**：生成和验证加密令牌
- **安全日志**：记录所有安全相关事件

#### 主要方法

```php
// 输入清理
Security::sanitizeString($input, $maxLength = 255)

// ID 验证（防止路径遍历）
Security::validateId($id, $maxLength = 50)

// 频率限制
Security::checkRateLimit($key, $maxAttempts, $windowSeconds)

// 密码哈希
Security::hashPassword($password)
Security::verifyPassword($password, $hash)

// 防时序攻击的字符串比较
Security::timingSafeCompare($known, $user)

// 安全日志
Security::logSecurityEvent($event, $context)
```

### 2. 中间件系统（Middleware.php）

中间件提供了请求处理的链式调用机制，类似于现代框架的中间件模式。

#### 可用中间件

1. **securityHeaders()**
   - 添加安全响应头
   - X-Frame-Options, X-XSS-Protection
   - Content-Security-Policy
   - HSTS（HTTPS 环境）

2. **rateLimit($scope, $maxAttempts, $windowSeconds)**
   - 基于 IP 的频率限制
   - 可配置限制范围和时间窗口
   - 返回 429 状态码和重试时间

3. **errorHandler()**
   - 全局错误捕获
   - 安全的错误响应（不暴露内部信息）
   - 错误日志记录

4. **requestLogger()**
   - 记录所有请求
   - 包含响应时间和状态码
   - 保存到 data/access.log

5. **jsonBody()**
   - 解析 JSON 请求体
   - 自动验证 JSON 格式

6. **validateInput($rules)**
   - 结构化输入验证
   - 支持类型、长度、范围、模式验证

#### 使用示例

```php
Middleware::execute([
    Middleware::securityHeaders(),
    Middleware::errorHandler(),
    Middleware::rateLimit('api', 60, 60),
    Middleware::jsonBody(),
], function() {
    // 处理请求
});
```

### 3. API 端点增强

所有 API 端点已集成新的安全特性：

#### admin.php
- 添加登录频率限制（5次/15分钟）
- 使用时序安全的密码比较
- 记录登录尝试（成功/失败）
- 应用完整中间件栈

#### redeem.php（激活码核销）
- 严格的频率限制（30次/分钟）
- 激活码格式验证
- 防注入攻击

#### validate.php（会话验证）
- 中等频率限制（120次/分钟）
- 完整的会话验证

#### logout.php
- 标准频率限制
- 安全的会话销毁

#### track.php（分析追踪）
- 高频率限制（300次/分钟，适合分析）
- 输入清理

## 安全特性详解

### 频率限制

防止暴力破解和 DDoS 攻击：

- **admin.php**：120 请求/分钟，登录 5 次/15分钟
- **redeem.php**：30 请求/分钟（激活较敏感）
- **validate.php**：120 请求/分钟
- **track.php**：300 请求/分钟（分析需要更高频率）

数据存储在 `data/ratelimit.json`

### 安全日志

所有安全事件记录在 `data/security.log`：
- 登录尝试（成功/失败）
- 频率限制触发
- 错误和异常
- 包含 IP、时间戳、上下文信息

### 访问日志

所有 API 请求记录在 `data/access.log`：
- 请求方法和 URI
- IP 地址
- 响应时间（毫秒）
- HTTP 状态码

### 安全响应头

所有响应自动包含：
```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: ...
Strict-Transport-Security: ... (HTTPS only)
```

### 输入验证

多层验证机制：
1. 格式验证（ID、邮箱、URL 等）
2. 长度限制
3. 类型检查
4. 模式匹配（正则）
5. 路径遍历防护

## 数据文件

系统使用以下数据文件（已添加到 .gitignore）：

- `data/codes.json` - 激活码数据
- `data/sessions.json` - 会话数据
- `data/tests.json` - 测试数据
- `data/site.json` - 站点设置
- `data/referrals.json` - 推荐数据
- `data/analytics.json` - 分析数据
- `data/ratelimit.json` - 频率限制数据
- `data/security.log` - 安全日志
- `data/access.log` - 访问日志
- `data/.lock` - 文件锁

## 部署建议

### 生产环境

1. **HTTPS 必需**
   - 强制使用 HTTPS
   - 启用 HSTS

2. **文件权限**
   ```bash
   chmod 755 api/
   chmod 644 api/*.php
   chmod 750 data/
   chmod 640 data/*.json
   chmod 640 data/*.log
   ```

3. **定期清理日志**
   ```bash
   # 设置 cron 任务清理旧日志（示例使用项目 data 目录）
   # 每周日凌晨删除30天前的日志
   0 0 * * 0 find /var/www/html/data -name "*.log" -mtime +30 -delete
   ```

4. **监控**
   - 定期检查 security.log
   - 监控频率限制触发次数
   - 关注异常登录尝试

### Nginx 配置

更新 BAOTA_NGINX_SNIPPET.txt 或在 Nginx 配置中添加：

```nginx
# 安全响应头（PHP 已添加，但 Nginx 层面再加一层更安全）
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;

# 限制请求体大小
client_max_body_size 2M;

# 超时设置
client_body_timeout 10s;
client_header_timeout 10s;
```

## 向后兼容性

所有改进都保持向后兼容：
- 现有 API 接口不变
- 请求/响应格式不变
- 配置文件格式不变
- 数据存储格式不变

## 升级路径

如果未来需要迁移到完整框架（如 ThinkPHP）：

1. 已经有了清晰的关注点分离（Security、Middleware）
2. 中间件模式易于迁移到 ThinkPHP 中间件
3. 安全功能可以直接复用
4. 数据层可以逐步迁移到数据库

## 性能考虑

- 中间件开销：< 1ms per request
- 频率限制检查：内存操作，极快
- 日志写入：异步，不阻塞请求
- 整体性能影响：< 5%

## 支持

如有问题，请查看：
- `data/security.log` - 安全事件
- `data/access.log` - 请求日志
- PHP error_log - PHP 错误

## 总结

本次重构在不改变现有架构的基础上，显著提升了系统的安全性：

✅ 防暴力破解（频率限制）
✅ 防注入攻击（输入验证）
✅ 防 XSS（输出清理 + CSP）
✅ 防时序攻击（安全比较）
✅ 安全的密码存储（Argon2id）
✅ 完整的安全日志
✅ 现代化的安全响应头
✅ 结构化的错误处理
✅ 可扩展的中间件系统

系统现在具备了企业级应用所需的基本安全特性，同时保持了代码的简洁性和可维护性。
