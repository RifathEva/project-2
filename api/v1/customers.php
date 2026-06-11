<?php
/**
 * Customers API Endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/validator.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../middleware/jwt.php';
require_once __DIR__ . '/../middleware/rate-limit.php';

$jwtMiddleware = new JWTMiddleware();
try {
    $auth = $jwtMiddleware->authenticate();
    $jwtMiddleware->requirePermission($auth, 'read:customers');
} catch (Exception $e) {
    JSONResponse::error($e->getMessage(), 401);
}

$apiLimiter = new RateLimiter($env, 'api_key');
$apiKeyId = $auth['sub'] ?? 'unknown';
if (!$apiLimiter->allow($apiKeyId)) {
    JSONResponse::error('Rate limit exceeded', 429);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$customerId = $_GET['id'] ?? null;
$action = $_GET['action'] ?? 'list';

$validator = new Validator();
$db = new Database($env);

try {
    switch ($action) {
        case 'list':
            if ($method !== 'GET') {
                JSONResponse::error('Method not allowed', 405);
            }
            
            $page = max((int)($_GET['page'] ?? 1), 1);
            $perPage = min((int)($_GET['per_page'] ?? 20), 100);
            $offset = ($page - 1) * $perPage;
            
            $search = '';
            $params = [$auth['sub']];
            
            if (!empty($_GET['search'])) {
                $searchTerm = $validator->string($_GET['search'], 100);
                $search = " AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)";
                $params[] = "%$searchTerm%";
                $params[] = "%$searchTerm%";
                $params[] = "%$searchTerm%";
            }
            
            $customers = $db->query(
                "SELECT id, name, phone, email, address, city, order_count, total_spent, created_at 
                 FROM reseller_customers 
                 WHERE reseller_id = ? $search 
                 ORDER BY created_at DESC 
                 LIMIT $offset, $perPage",
                $params
            )->fetchAll();
            
            $total = $db->query(
                "SELECT COUNT(*) as total FROM reseller_customers WHERE reseller_id = ? $search",
                $params
            )->fetch()['total'] ?? 0;
            
            JSONResponse::paginated($customers, $page, $perPage, (int)$total);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                JSONResponse::error('Method not allowed', 405);
            }
            
            $jwtMiddleware->requirePermission($auth, 'write:customers');
            
            $input = json_decode(file_get_contents('php://input'), true);
            $input = $validator->sanitizeArray($input ?? []);
            
            if (empty($input['name']) || empty($input['phone'])) {
                JSONResponse::error('Name and phone required', 400);
            }
            
            if (!$validator->bdPhone($input['phone'])) {
                JSONResponse::error('Invalid phone number', 400);
            }
            
            $existing = $db->query(
                "SELECT id FROM reseller_customers WHERE phone = ? AND reseller_id = ? LIMIT 1",
                [$input['phone'], $auth['sub']]
            )->fetch();
            
            if ($existing) {
                JSONResponse::error('Customer with this phone already exists', 409);
            }
            
            $db->query(
                "INSERT INTO reseller_customers 
                 (reseller_id, name, phone, email, address, city, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [
                    $auth['sub'],
                    $input['name'],
                    $input['phone'],
                    $input['email'] ?? null,
                    $input['address'] ?? null,
                    $input['city'] ?? 'Dhaka',
                ]
            );
            
            JSONResponse::success([
                'id' => $db->lastInsertId(),
                'name' => $input['name'],
                'phone' => $input['phone'],
                'message' => 'Customer created successfully',
            ], 201);
            break;
            
        case 'detail':
            if ($method !== 'GET' || empty($customerId)) {
                JSONResponse::error('Customer ID required', 400);
            }
            
            $id = $validator->id($customerId);
            if (!$validator->passes()) {
                JSONResponse::error('Invalid customer ID', 400);
            }
            
            $customer = $db->query(
                "SELECT * FROM reseller_customers WHERE id = ? AND reseller_id = ? LIMIT 1",
                [$id, $auth['sub']]
            )->fetch();
            
            if (!$customer) {
                JSONResponse::error('Customer not found', 404);
            }
            
            $orders = $db->query(
                "SELECT wc_order_id, total_amount, status, created_at 
                 FROM reseller_orders 
                 WHERE reseller_id = ? AND customer_phone = ? 
                 ORDER BY created_at DESC",
                [$auth['sub'], $customer['phone']]
            )->fetchAll();
            
            JSONResponse::success([
                'customer' => $customer,
                'order_history' => $orders,
                'total_orders' => count($orders),
            ]);
            break;
            
        default:
            JSONResponse::error('Unknown action', 404);
    }
    
} catch (Exception $e) {
    JSONResponse::error($e->getMessage(), 500);
}