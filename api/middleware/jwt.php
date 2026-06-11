<?php
/**
 * JWT Authentication Middleware
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/jwt.php';
require_once __DIR__ . '/../../config/security.php';

class JWTMiddleware {
    private JWT $jwt;
    private SecurityConfig $config;
    
    public function __construct() {
        $this->config = new SecurityConfig();
        $this->jwt = new JWT([
            'secret' => $this->config->getJwtSecret(),
            'issuer' => $this->config->get('JWT_ISSUER'),
            'audience' => $this->config->get('JWT_AUDIENCE'),
            'access_expiry' => (int)$this->config->get('JWT_EXPIRY', '3600'),
            'refresh_expiry' => (int)$this->config->get('JWT_REFRESH_EXPIRY', '604800'),
        ]);
    }
    
    public function authenticate(): array {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (!preg_match('/Bearer\s+(\S+)/', $header, $matches)) {
            throw new Exception('Authorization header missing or invalid', 401);
        }
        
        $token = $matches[1];
        
        try {
            $payload = $this->jwt->validate($token);
            
            if ($this->jwt->isRefreshToken($payload)) {
                throw new Exception('Refresh token cannot be used for API access', 403);
            }
            
            if ($this->isRevoked($payload['jti'] ?? '')) {
                throw new Exception('Token has been revoked', 401);
            }
            
            return $payload;
            
        } catch (Exception $e) {
            throw new Exception('Authentication failed: ' . $e->getMessage(), 401);
        }
    }
    
    public function requirePermission(array $payload, string $permission): void {
        $permissions = $payload['permissions'] ?? [];
        if (!in_array($permission, $permissions, true) && !in_array('admin:*', $permissions, true)) {
            throw new Exception('Insufficient permissions', 403);
        }
    }
    
    public function refresh(string $refreshToken): array {
        try {
            $payload = $this->jwt->validate($refreshToken);
            
            if (!$this->jwt->isRefreshToken($payload)) {
                throw new Exception('Invalid refresh token');
            }
            
            $newAccess = $this->jwt->generateAccessToken([
                'reseller_id' => $payload['sub'],
                'role' => $payload['role'] ?? 'reseller',
            ]);
            
            $newRefresh = $this->jwt->generateRefreshToken($payload['sub']);
            $this->revoke($payload['jti'] ?? '');
            
            return [
                'access_token' => $newAccess,
                'refresh_token' => $newRefresh,
                'expires_in' => 3600,
            ];
            
        } catch (Exception $e) {
            throw new Exception('Refresh failed: ' . $e->getMessage(), 401);
        }
    }
    
    private function isRevoked(string $jti): bool {
        if (empty($jti)) return false;
        $revokedFile = __DIR__ . '/../../storage/cache/revoked_tokens.txt';
        if (!file_exists($revokedFile)) return false;
        $revoked = file($revokedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return in_array($jti, $revoked, true);
    }
    
    private function revoke(string $jti): void {
        if (empty($jti)) return;
        $revokedFile = __DIR__ . '/../../storage/cache/revoked_tokens.txt';
        file_put_contents($revokedFile, $jti . "\n", FILE_APPEND | LOCK_EX);
    }
    
    public function getJWT(): JWT {
        return $this->jwt;
    }
}