<?php
/**
 * Rate Limiting Middleware (Redis + File Fallback)
 */

declare(strict_types=1);

class RateLimiter {
    private array $config;
    private string $type;
    private $redis;
    private bool $useRedis;
    private string $cacheDir;
    
    public function __construct(array $env, string $type = 'ip') {
        $this->type = $type;
        $this->config = [
            'ip_max' => (int)($env['RATE_LIMIT_IP'] ?? 60),
            'ip_window' => (int)($env['RATE_LIMIT_WINDOW'] ?? 60),
            'api_max' => (int)($env['RATE_LIMIT_API_KEY'] ?? 1000),
            'api_window' => (int)($env['RATE_LIMIT_API_WINDOW'] ?? 3600),
        ];
        $this->cacheDir = __DIR__ . '/../../storage/cache/ratelimit/';
        $this->useRedis = false;
        
        // Try Redis first
        if (extension_loaded('redis')) {
            try {
                $this->redis = new Redis();
                $connected = $this->redis->connect(
                    $env['REDIS_HOST'] ?? '127.0.0.1',
                    (int)($env['REDIS_PORT'] ?? 6379),
                    2
                );
                if ($connected && !empty($env['REDIS_PASSWORD'])) {
                    $this->redis->auth($env['REDIS_PASSWORD']);
                }
                $this->useRedis = $connected;
            } catch (Exception $e) {
                $this->useRedis = false;
            }
        }
        
        if (!$this->useRedis && !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0750, true);
        }
    }
    
    public function allow(string $identifier): bool {
        $key = "rate_limit:{$this->type}:" . md5($identifier);
        $max = $this->type === 'ip' ? $this->config['ip_max'] : $this->config['api_max'];
        $window = $this->type === 'ip' ? $this->config['ip_window'] : $this->config['api_window'];
        
        if ($this->useRedis) {
            return $this->redisLimit($key, $max, $window);
        }
        return $this->fileLimit($key, $max, $window);
    }
    
    private function redisLimit(string $key, int $max, int $window): bool {
        try {
            $current = $this->redis->get($key);
            if (!$current) {
                $this->redis->setex($key, $window, 1);
                return true;
            }
            if ((int)$current >= $max) {
                return false;
            }
            $this->redis->incr($key);
            return true;
        } catch (Exception $e) {
            return $this->fileLimit($key, $max, $window);
        }
    }
    
    private function fileLimit(string $key, int $max, int $window): bool {
        $file = $this->cacheDir . md5($key) . '.json';
        $now = time();
        
        $data = ['count' => 0, 'reset' => $now + $window];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $data = json_decode($content, true) ?? $data;
        }
        
        if ($data['reset'] < $now) {
            $data = ['count' => 0, 'reset' => $now + $window];
        }
        
        $data['count']++;
        file_put_contents($file, json_encode($data), LOCK_EX);
        
        return $data['count'] <= $max;
    }
    
    public function getRemaining(string $identifier): int {
        $key = "rate_limit:{$this->type}:" . md5($identifier);
        $max = $this->type === 'ip' ? $this->config['ip_max'] : $this->config['api_max'];
        
        if ($this->useRedis) {
            try {
                $current = (int)$this->redis->get($key);
                return max(0, $max - $current);
            } catch (Exception $e) {
                // Fallback
            }
        }
        return $max;
    }
    
    public function addHeaders(string $identifier): void {
        $remaining = $this->getRemaining($identifier);
        $max = $this->type === 'ip' ? $this->config['ip_max'] : $this->config['api_max'];
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Limit: ' . $max);
    }
}