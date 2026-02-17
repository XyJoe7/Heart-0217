<?php
declare(strict_types=1);

/**
 * Middleware system for request processing
 * Provides a chain of responsibility pattern for handling requests
 */
class Middleware {
    
    /**
     * Execute middleware chain
     * @param array $middlewares Array of middleware callables
     * @param callable $finalHandler Final handler to execute after all middleware
     * @return mixed Result of the final handler
     */
    public static function execute(array $middlewares, callable $finalHandler) {
        $next = $finalHandler;
        
        // Build middleware chain in reverse order
        foreach (array_reverse($middlewares) as $middleware) {
            $next = function() use ($middleware, $next) {
                return $middleware($next);
            };
        }
        
        // Execute the chain
        return $next();
    }
    
    /**
     * CORS middleware
     */
    public static function cors(): callable {
        return function(callable $next) {
            // Allow same-origin by default
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            
            // Only set CORS headers if needed
            if ($origin && parse_url($origin, PHP_URL_HOST) === $host) {
                header("Access-Control-Allow-Origin: $origin");
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, Authorization');
            }
            
            // Handle preflight
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
                http_response_code(204);
                exit;
            }
            
            return $next();
        };
    }
    
    /**
     * Security headers middleware
     */
    public static function securityHeaders(): callable {
        return function(callable $next) {
            Security::addSecurityHeaders();
            return $next();
        };
    }
    
    /**
     * Rate limiting middleware
     */
    public static function rateLimit(string $scope, int $maxAttempts = 60, int $windowSeconds = 60): callable {
        return function(callable $next) use ($scope, $maxAttempts, $windowSeconds) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $key = "ratelimit:{$scope}:{$ip}";
            
            // Load rate limit data
            $rateLimitFile = __DIR__ . '/../data/ratelimit.json';
            Security::loadRateLimitData($rateLimitFile);
            
            if (!Security::checkRateLimit($key, $maxAttempts, $windowSeconds)) {
                $resetIn = Security::getRateLimitReset($key);
                http_response_code(429);
                header('Content-Type: application/json; charset=utf-8');
                header("Retry-After: $resetIn");
                echo json_encode([
                    'ok' => false,
                    'error' => 'rate_limit_exceeded',
                    'message' => '请求过于频繁，请稍后再试',
                    'retryAfter' => $resetIn
                ], JSON_UNESCAPED_UNICODE);
                
                Security::logSecurityEvent('rate_limit_exceeded', [
                    'scope' => $scope,
                    'ip' => $ip
                ]);
                
                exit;
            }
            
            $result = $next();
            
            // Save rate limit data
            Security::saveRateLimitData($rateLimitFile);
            
            return $result;
        };
    }
    
    /**
     * JSON body parser middleware
     */
    public static function jsonBody(): callable {
        return function(callable $next) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            if (strpos($contentType, 'application/json') !== false) {
                $raw = file_get_contents('php://input');
                if ($raw) {
                    $data = json_decode($raw, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        http_response_code(400);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'ok' => false,
                            'error' => 'invalid_json',
                            'message' => 'Invalid JSON in request body'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $_REQUEST['__json_body__'] = $data;
                }
            }
            
            return $next();
        };
    }
    
    /**
     * Error handler middleware
     */
    public static function errorHandler(): callable {
        return function(callable $next) {
            try {
                set_error_handler(function($errno, $errstr, $errfile, $errline) {
                    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
                });
                
                $result = $next();
                
                restore_error_handler();
                
                return $result;
                
            } catch (Throwable $e) {
                restore_error_handler();
                
                // Log the error
                error_log("Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                Security::logSecurityEvent('error', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                
                // Return error response
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => false,
                    'error' => 'internal_error',
                    'message' => '服务器内部错误'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        };
    }
    
    /**
     * Request logging middleware
     */
    public static function requestLogger(): callable {
        return function(callable $next) {
            $startTime = microtime(true);
            $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            $result = $next();
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $status = http_response_code();
            
            $logFile = __DIR__ . '/../data/access.log';
            $logEntry = sprintf(
                "[%s] %s %s - %s - %dms - %d\n",
                date('Y-m-d H:i:s'),
                $ip,
                $method,
                $uri,
                $duration,
                $status
            );
            
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            
            return $result;
        };
    }
    
    /**
     * Input validation middleware
     */
    public static function validateInput(array $rules): callable {
        return function(callable $next) use ($rules) {
            $data = $_REQUEST['__json_body__'] ?? [];
            
            $errors = Security::validateJsonStructure($data, $rules);
            
            if (!empty($errors)) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => false,
                    'error' => 'validation_failed',
                    'message' => '输入验证失败',
                    'errors' => $errors
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            return $next();
        };
    }
}
