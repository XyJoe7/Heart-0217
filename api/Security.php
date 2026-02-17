<?php
declare(strict_types=1);

/**
 * Security utilities for the Heart backend
 * Provides enhanced security features including input validation, rate limiting, and CSRF protection
 */
class Security {
    
    /**
     * Rate limiting storage
     */
    private static array $rateLimitStore = [];
    
    /**
     * Validate and sanitize input string
     */
    public static function sanitizeString(string $input, int $maxLength = 255): string {
        $input = trim($input);
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }
        return $input;
    }
    
    /**
     * Validate email format
     */
    public static function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate URL format
     */
    public static function validateUrl(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Generate secure random token
     */
    public static function generateSecureToken(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Hash password using secure algorithm
     */
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    /**
     * Verify password against hash
     */
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
    
    /**
     * Check if password needs rehashing
     */
    public static function needsRehash(string $hash): bool {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    /**
     * Rate limiting - check if action is allowed
     * @param string $key Unique key for the action (e.g., 'login:' . $ip)
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return bool True if allowed, false if rate limit exceeded
     */
    public static function checkRateLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool {
        $now = time();
        
        // Initialize if not exists
        if (!isset(self::$rateLimitStore[$key])) {
            self::$rateLimitStore[$key] = ['count' => 0, 'reset' => $now + $windowSeconds];
        }
        
        $store = &self::$rateLimitStore[$key];
        
        // Reset if window expired
        if ($now >= $store['reset']) {
            $store['count'] = 0;
            $store['reset'] = $now + $windowSeconds;
        }
        
        // Check limit
        if ($store['count'] >= $maxAttempts) {
            return false;
        }
        
        // Increment counter
        $store['count']++;
        return true;
    }
    
    /**
     * Get remaining time until rate limit reset
     */
    public static function getRateLimitReset(string $key): int {
        if (!isset(self::$rateLimitStore[$key])) {
            return 0;
        }
        return max(0, self::$rateLimitStore[$key]['reset'] - time());
    }
    
    /**
     * Load rate limit data from file
     */
    public static function loadRateLimitData(string $path): void {
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data)) {
                self::$rateLimitStore = $data;
            }
        }
    }
    
    /**
     * Save rate limit data to file
     */
    public static function saveRateLimitData(string $path): void {
        // Clean up expired entries
        $now = time();
        foreach (self::$rateLimitStore as $key => $data) {
            if ($now >= $data['reset']) {
                unset(self::$rateLimitStore[$key]);
            }
        }
        
        $result = file_put_contents($path, json_encode(self::$rateLimitStore), LOCK_EX);
        if ($result === false) {
            error_log("Failed to save rate limit data to {$path}");
        }
    }
    
    /**
     * Validate ID format (alphanumeric, dash, underscore only)
     */
    public static function validateId(string $id, int $maxLength = 50): bool {
        if (empty($id) || strlen($id) > $maxLength) {
            return false;
        }
        
        // Must start with letter or number (length check ensures id[0] is safe)
        if (!preg_match('/^[a-zA-Z0-9]/', $id)) {
            return false;
        }
        
        // Only alphanumeric, dash, and underscore
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            return false;
        }
        
        // No path traversal patterns (length check ensures safe array access)
        if (strpos($id, '..') !== false || $id[0] === '.' || $id[0] === '/') {
            return false;
        }
        
        return true;
    }
    
    /**
     * Add security headers to response
     */
    public static function addSecurityHeaders(): void {
        // Prevent clickjacking
        header('X-Frame-Options: DENY');
        
        // XSS protection
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content Security Policy
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;");
        
        // HSTS (HTTP Strict Transport Security)
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        
        // Permissions policy
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }
    
    /**
     * Validate JSON input structure
     */
    public static function validateJsonStructure(array $data, array $requiredFields): array {
        $errors = [];
        
        foreach ($requiredFields as $field => $rules) {
            // Check if field exists
            if (!isset($data[$field])) {
                if ($rules['required'] ?? false) {
                    $errors[] = "Missing required field: {$field}";
                }
                continue;
            }
            
            $value = $data[$field];
            
            // Type validation
            if (isset($rules['type'])) {
                $type = $rules['type'];
                $valid = match($type) {
                    'string' => is_string($value),
                    'int' => is_int($value),
                    'bool' => is_bool($value),
                    'array' => is_array($value),
                    default => false
                };
                
                if (!$valid) {
                    $errors[] = "Field {$field} must be of type {$type}";
                    continue;
                }
            }
            
            // String length validation
            if (isset($rules['minLength']) && is_string($value)) {
                if (strlen($value) < $rules['minLength']) {
                    $errors[] = "Field {$field} must be at least {$rules['minLength']} characters";
                }
            }
            
            if (isset($rules['maxLength']) && is_string($value)) {
                if (strlen($value) > $rules['maxLength']) {
                    $errors[] = "Field {$field} must be at most {$rules['maxLength']} characters";
                }
            }
            
            // Numeric range validation
            if (isset($rules['min']) && is_numeric($value)) {
                if ($value < $rules['min']) {
                    $errors[] = "Field {$field} must be at least {$rules['min']}";
                }
            }
            
            if (isset($rules['max']) && is_numeric($value)) {
                if ($value > $rules['max']) {
                    $errors[] = "Field {$field} must be at most {$rules['max']}";
                }
            }
            
            // Pattern validation
            if (isset($rules['pattern']) && is_string($value)) {
                if (!preg_match($rules['pattern'], $value)) {
                    $errors[] = "Field {$field} format is invalid";
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Prevent timing attacks in string comparison
     */
    public static function timingSafeCompare(string $known, string $user): bool {
        if (!is_string($known) || !is_string($user)) {
            return false;
        }
        
        return hash_equals($known, $user);
    }
    
    /**
     * Log security event
     */
    public static function logSecurityEvent(string $event, array $context = []): void {
        $logFile = __DIR__ . '/../data/security.log';
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'context' => $context
        ];
        
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n";
        $result = file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            // Fallback: log to PHP error log if file write fails
            error_log("Security event (failed to write to file): {$event} - " . json_encode($context));
        }
    }
}
