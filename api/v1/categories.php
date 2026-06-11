<?php
/**
 * Categories API Endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/validator.php';
require_once __DIR__ . '/../../config/woo-api.php';
require_once __DIR__ . '/../middleware/jwt.php';
require_once __DIR__ . '/../middleware/rate-limit.php';

$jwtMiddleware = new JWTMiddleware();
try {
    $auth = $jwtMiddleware->authenticate();
    $jwtMiddleware->requirePermission($auth, 'read:products');
} catch (Exception $e) {
    JSONResponse::error($e->getMessage(), 401);
}

$apiLimiter = new RateLimiter($env, 'api_key');
$apiKeyId = $auth['sub'] ?? 'unknown';
if (!$apiLimiter->allow($apiKeyId)) {
    JSONResponse::error('Rate limit exceeded', 429);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$categoryId = $_GET['