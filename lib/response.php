<?php
/**
 * Standardized JSON Response Handler
 */

declare(strict_types=1);

class JSONResponse {
    private static array $headers = [
        'Content-Type: application/json; charset=utf-8',
        'X-Content-Type-Options: nosniff',
    ];
    
    public static function success(array $data, int $code = 200): void {
        http_response_code($code);
        foreach (self::$headers as $header) {
            header($header);
        }
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => time(),
            'request_id' => bin2hex(random_bytes(8)),
        ]);
        exit;
    }
    
    public static function error(string $message, int $code = 400, array $details = []): void {
        http_response_code($code);
        foreach (self::$headers as $header) {
            header($header);
        }
        $response = [
            'success' => false,
            'error' => $message,
            'timestamp' => time(),
            'request_id' => bin2hex(random_bytes(8)),
        ];
        if (!empty($details)) {
            $response['details'] = $details;
        }
        echo json_encode($response);
        exit;
    }
    
    public static function paginated(array $data, int $page, int $perPage, int $total): void {
        self::success([
            'items' => $data,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int)ceil($total / $perPage),
                'has_next' => ($page * $perPage) < $total,
                'has_prev' => $page > 1,
            ],
        ]);
    }
}