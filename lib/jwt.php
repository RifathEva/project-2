<?php
/**
 * Custom JWT Implementation - Zero External Dependencies
 */

declare(strict_types=1);

class JWT {
    private string $secret;
    private string $issuer;
    private string $audience;
    private int $accessExpiry;
    private int $refreshExpiry;
    
    public function __construct(array $config) {
        $this->secret = $config['secret'] ?? '';
        $this->issuer = $config['issuer'] ?? 'api';
        $this->audience = $config['audience'] ?? 'app';
        $this->accessExpiry = (int)($config['access_expiry'] ?? 3600);
        $this->refreshExpiry = (int)($config['refresh_expiry'] ?? 604800);
        
        if (strlen($this->secret) < 32) {
            throw new Exception('JWT secret must be at least 256 bits');
        }
    }
    
    public function generateAccessToken(array $payload): string {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256', 'kid' => 'v1']);
        $time = time();
        
        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $time,
            'nbf' => $time,
            'exp' => $time + $this->accessExpiry,
            'jti' => bin2hex(random_bytes(16)),
            'sub' => $payload['reseller_id'] ?? '',
            'role' => $payload['role'] ?? 'reseller',
            'tier' => $payload['tier'] ?? 'basic',
            'permissions' => $payload['permissions'] ?? ['read:products', 'read:orders'],
        ];
        
        return $this->encode($header, json_encode($claims));
    }
    
    public function generateRefreshToken(string $resellerId): string {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256', 'kid' => 'refresh-v1']);
        $time = time();
        
        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $time,
            'exp' => $time + $this->refreshExpiry,
            'jti' => bin2hex(random_bytes(16)),
            'sub' => $resellerId,
            'type' => 'refresh',
        ];
        
        return $this->encode($header, json_encode($claims));
    }
    
    public function validate(string $token): array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new Exception('Invalid token format');
        }
        
        [$headerB64, $payloadB64, $signatureB64] = $parts;
        
        $expectedSig = $this->base64UrlEncode(
            hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $this->secret, true)
        );
        
        if (!hash_equals($expectedSig, $signatureB64)) {
            throw new Exception('Invalid token signature');
        }
        
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!$payload) {
            throw new Exception('Invalid token payload');
        }
        
        $time = time();
        
        if (isset($payload['exp']) && $payload['exp'] < $time) {
            throw new Exception('Token expired');
        }
        
        if (isset($payload['nbf']) && $payload['nbf'] > $time) {
            throw new Exception('Token not yet valid');
        }
        
        if (isset($payload['iss']) && $payload['iss'] !== $this->issuer) {
            throw new Exception('Invalid issuer');
        }
        
        if (isset($payload['aud']) && $payload['aud'] !== $this->audience) {
            throw new Exception('Invalid audience');
        }
        
        return $payload;
    }
    
    public function isRefreshToken(array $payload): bool {
        return ($payload['type'] ?? '') === 'refresh';
    }
    
    private function encode(string $header, string $payload): string {
        $headerB64 = $this->base64UrlEncode($header);
        $payloadB64 = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $this->secret, true);
        $sigB64 = $this->base64UrlEncode($signature);
        
        return $headerB64 . '.' . $payloadB64 . '.' . $sigB64;
    }
    
    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private function base64UrlDecode(string $data): string {
        $padding = 4 - (strlen($data) % 4);
        if ($padding !== 4) {
            $data .= str_repeat('=', $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}