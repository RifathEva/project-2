<?php
/**
 * Authentication Endpoints
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/validator.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../middleware/jwt.php';
require_once __DIR__ . '/../middleware/rate-limit.php';
require_once __DIR__ . '/../middleware/audit-log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// Rate limit auth endpoints
$authLimiter = new RateLimiter($env, 'ip');
if (!$authLimiter->allow($_SERVER['REMOTE_ADDR'] . ':auth')) {
    JSONResponse::error('Too many authentication attempts', 429);
}

$validator = new Validator();
$jwtMiddleware = new JWTMiddleware();
$audit = new AuditMiddleware($env);

try {
    switch ($action) {
        case 'login':
            if ($method !== 'POST') {
                JSONResponse::error('Method not allowed', 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $input = $validator->sanitizeArray($input ?? []);
            
            if (empty($input['email']) || empty($input['password']) || empty($input['api_key'])) {
                JSONResponse::error('Email, password, and API key required', 400);
            }
            
            if (!$validator->email($input['email'])) {
                JSONResponse::error('Invalid email format', 400);
            }
            
            if (!$validator->apiKey($input['api_key'])) {
                JSONResponse::error('Invalid API key format', 400);
            }
            
            $db = new Database($env);
            $reseller = $db->query(
                "SELECT id, email, password_hash, role, tier, status, permissions 
                 FROM resellers 
                 WHERE email = ? AND api_key = ? AND status = 'active' 
                 LIMIT 1",
                [$input['email'], hash('sha256', $input['api_key'])]
            )->fetch();
            
            if (!$reseller || !password_verify($input['password'], $reseller['password_hash'])) {
                $audit->logRequest();
                JSONResponse::error('Invalid credentials', 401);
            }
            
            $jwt = $jwtMiddleware->getJWT();
            $accessToken = $jwt->generateAccessToken([
                'reseller_id' => $reseller['id'],
                'role' => $reseller['role'],
                'tier' => $reseller['tier'],
                'permissions' => json_decode($reseller['permissions'] ?? '[]', true),
            ]);
            
            $refreshToken = $jwt->generateRefreshToken($reseller['id']);
            
            $audit->logRequest(['sub' => $reseller['id'], 'role' => $reseller['role']]);
            
            JSONResponse::success([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'reseller' => [
                    'id' => $reseller['id'],
                    'email' => $reseller['email'],
                    'role' => $reseller['role'],
                    'tier' => $reseller['tier'],
                ],
            ]);
            break;
            
        case 'refresh':
            if ($method !== 'POST') {
                JSONResponse::error('Method not allowed', 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['refresh_token'])) {
                JSONResponse::error('Refresh token required', 400);
            }
            
            $tokens = $jwtMiddleware->refresh($input['refresh_token']);
            JSONResponse::success($tokens);
            break;
            
        case 'logout':
            if ($method !== 'POST') {
                JSONResponse::error('Method not allowed', 405);
            }
            
            $auth = $jwtMiddleware->authenticate();
            $jti = $auth['jti'] ?? '';
            if ($jti) {
                $revokedFile = __DIR__ . '/../../storage/cache/revoked_tokens.txt';
                file_put_contents($revokedFile, $jti . "\n", FILE_APPEND | LOCK_EX);
            }
            
            JSONResponse::success(['message' => 'Logged out successfully']);
            break;
            
        case 'verify':
            $auth = $jwtMiddleware->authenticate();
            JSONResponse::success([
                'valid' => true,
                'reseller_id' => $auth['sub'],
                'role' => $auth['role'],
                'expires' => $auth['exp'],
            ]);
            break;
            
        default:
            JSONResponse::error('Unknown authentication action', 404);
    }
    
} catch (Exception $e) {
    $code = (int)($e->getCode() >= 400 ? $e->getCode() : 500);
    JSONResponse::error($e->getMessage(), $code);
}