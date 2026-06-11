<?php
/**
 * CORS Middleware
 */

declare(strict_types=1);

class CORSMiddleware {
    public static function apply(array $env): void {
        $allowedOrigins = array_map('trim', explode(',', $env['CORS_ALLOWED_ORIGINS'] ?? ''));
        $allowedMethods = $env['CORS_ALLOWED_METHODS'] ?? 'GET,POST,PUT,DELETE,OPTIONS';
        $allowedHeaders = $env['CORS_ALLOWED_HEADERS'] ?? 'Authorization,Content-Type,X-API-Key';
        $maxAge = (int)($env['CORS_MAX_AGE'] ?? '86400');
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
            header('Access-Control-Allow-Credentials: true');
        }
        
        header("Access-Control-Allow-Methods: $allowedMethods");
        header("Access-Control-Allow-Headers: $allowedHeaders");
        header("Access-Control-Max-Age: $maxAge");
        header("Vary: Origin");
    }
}