<?php
/**
 * Security Configuration
 */

declare(strict_types=1);

class SecurityConfig {
    private array $env;
    
    public function __construct() {
        $this->env = $this->loadEnv();
    }
    
    private function loadEnv(): array {
        $env = [];
        $envFile = dirname(__DIR__) . '/.env';
        if (!file_exists($envFile)) {
            throw new Exception('Environment file not found');
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                [$key, $value] = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
            }
        }
        return $env;
    }
    
    public function get(string $key, string $default = ''): string {
        return $this->env[$key] ?? $default;
    }
    
    public function getJwtSecret(): string {
        $secret = $this->get('JWT_SECRET');
        if (strlen($secret) < 32) {
            throw new Exception('JWT secret must be at least 32 characters');
        }
        return $secret;
    }
    
    public function getHmacSecret(): string {
        return $this->get('HMAC_SECRET');
    }
    
    public function getAesKey(): string {
        $key = $this->get('AES_KEY');
        if (strlen($key) !== 32) {
            throw new Exception('AES key must be exactly 32 bytes');
        }
        return $key;
    }
    
    public function getAdminIps(): array {
        return array_map('trim', explode(',', $this->get('ADMIN_IPS', '127.0.0.1')));
    }
    
    public function getCorsOrigins(): array {
        return array_map('trim', explode(',', $this->get('CORS_ALLOWED_ORIGINS', '')));
    }
    
    public function isDebug(): bool {
        return $this->get('APP_DEBUG', 'false') === 'true';
    }
    
    public function getRateLimit(): array {
        return [
            'ip_max' => (int)$this->get('RATE_LIMIT_IP', '60'),
            'ip_window' => (int)$this->get('RATE_LIMIT_WINDOW', '60'),
            'api_max' => (int)$this->get('RATE_LIMIT_API_KEY', '1000'),
            'api_window' => (int)$this->get('RATE_LIMIT_API_WINDOW', '3600'),
        ];
    }
}