<?php
/**
 * HMAC Request Signature Verification
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/security.php';

class HMACMiddleware {
    private SecurityConfig $config;
    
    public function __construct() {
        $this->config = new SecurityConfig();
    }
    
    public function verify(): void {
        $signature = $_SERVER['HTTP_X_HMAC_SIGNATURE'] ?? '';
        $timestamp = $_SERVER['HTTP_X_REQUEST_TIMESTAMP'] ?? '';
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        
        if (empty($signature) || empty($timestamp) || empty($apiKey)) {
            throw new Exception('HMAC headers missing', 401);
        }
        
        $time = time();
        $reqTime = (int)$timestamp;
        if (abs($time - $reqTime) > 300) {
            throw new Exception('Request timestamp expired', 401);
        }
        
        if (!preg_match('/^[a-zA-Z0-9]{32,64}$/', $apiKey)) {
            throw new Exception('Invalid API key format', 401);
        }
        
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $body = file_get_contents('php://input');
        
        $payload = $method . '|' . $uri . '|' . $timestamp . '|' . $body;
        $expected = hash_hmac('sha256', $payload, $this->config->getHmacSecret());
        
        if (!hash_equals($expected, $signature)) {
            throw new Exception('Invalid HMAC signature', 403);
        }
    }
    
    public static function generate(string $method, string $uri, string $body, string $timestamp, string $secret): string {
        $payload = $method . '|' . $uri . '|' . $timestamp . '|' . $body;
        return hash_hmac('sha256', $payload, $secret);
    }
}