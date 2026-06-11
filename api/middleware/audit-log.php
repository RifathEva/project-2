<?php
/**
 * Audit Log Middleware
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/logger.php';

class AuditMiddleware {
    private SecurityLogger $logger;
    
    public function __construct(array $env) {
        $this->logger = new SecurityLogger($env);
    }
    
    public function logRequest(array $authPayload = []): void {
        $context = [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'query' => $_SERVER['QUERY_STRING'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'reseller_id' => $authPayload['sub'] ?? 'anonymous',
            'role' => $authPayload['role'] ?? 'none',
        ];
        
        $body = file_get_contents('php://input');
        if (!empty($body)) {
            $context['body_hash'] = hash('sha256', $body);
            $context['body_size'] = strlen($body);
        }
        
        $this->logger->log('API_REQUEST', $authPayload['sub'] ?? 'anonymous', $context);
    }
    
    public function logResponse(int $statusCode, string $error = ''): void {
        $context = [
            'status_code' => $statusCode,
            'response_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
        ];
        
        if (!empty($error)) {
            $context['error'] = $error;
        }
        
        $this->logger->log('API_RESPONSE', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $context);
    }
}