<?php
/**
 * Headless Dropshipping API - Main Entry Point
 */

declare(strict_types=1);

// HEALTHCHECK ENDPOINT - Railway er jonno
if (($_SERVER['REQUEST_URI'] ?? '/') === '/' || ($_SERVER['REQUEST_URI'] ?? '') === '/health') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'service' => 'OngonWear Dropshipping API',
        'timestamp' => time(),
        'version' => '1.0'
    ]);
    exit;
}

require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/api/middleware/cors.php';
require_once __DIR__ . '/api/middleware/rate-limit.php';
require_once __DIR__ . '/api/middleware/jwt.php';
require_once __DIR__ . '/api/middleware/audit-log.php';

// Load environment
if (!file_exists(__DIR__ . '/.env')) {
    http_response_code(500);
    die(json_encode(['error' => 'Environment not configured']));
}

// Parse .env
$env = [];
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }
}

// Error handling
if (($env['APP_DEBUG'] ?? 'false') === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Security: HTTPS check
if (($env['APP_ENV'] ?? 'production') === 'production' && 
    empty($_SERVER['HTTPS']) && 
    $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' !== 'https') {
    JSONResponse::error('HTTPS required', 403);
    exit;
}

// Initialize logger
$logger = new SecurityLogger($env);

// Apply CORS
CORSMiddleware::apply($env);

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Rate limiting by IP
$ipLimiter = new RateLimiter($env, 'ip');
if (!$ipLimiter->allow($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
    $logger->log('RATE_LIMIT_IP_BLOCKED', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    JSONResponse::error('Rate limit exceeded. Try again later.', 429);
    exit;
}

// Parse request URI
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = trim($uri, '/');
$parts = explode('/', $uri);

// API Version check
if (!isset($parts[0]) || $parts[0] !== 'api' || !isset($parts[1])) {
    JSONResponse::error('Invalid API endpoint', 404);
    exit;
}

$version = $parts[1];
$endpoint = $parts[2] ?? '';

if ($version !== 'v1') {
    JSONResponse::error('API version not supported', 400);
    exit;
}

// Route to endpoint
$routes = [
    'auth' => 'api/v1/auth.php',
    'products' => 'api/v1/products.php',
    'orders' => 'api/v1/orders.php',
    'customers' => 'api/v1/customers.php',
    'categories' => 'api/v1/categories.php',
    'webhook' => 'api/v1/webhook.php',
];

if (!isset($routes[$endpoint])) {
    JSONResponse::error('Endpoint not found', 404);
    exit;
}

// Log request start
$requestId = bin2hex(random_bytes(8));
$logger->log('REQUEST_START', $requestId, [
    'endpoint' => $endpoint, 
    'method' => $_SERVER['REQUEST_METHOD']
]);

// Include endpoint
require_once __DIR__ . '/' . $routes[$endpoint];
