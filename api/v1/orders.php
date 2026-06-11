<?php
/**
 * Orders API Endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/validator.php';
require_once __DIR__ . '/../../config/woo-api.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../middleware/jwt.php';
require_once __DIR__ . '/../middleware/rate-limit.php';
require_once __DIR__ . '/../middleware/hmac.php';

$jwtMiddleware = new JWTMiddleware();
try {
    $auth = $jwtMiddleware->authenticate();
    $jwtMiddleware->requirePermission($auth, 'read:orders');
} catch (Exception $e) {
    JSONResponse::error($e->getMessage(), 401);
}

$apiLimiter = new RateLimiter($env, 'api_key');
$apiKeyId = $auth['sub'] ?? 'unknown';
if (!$apiLimiter->allow($apiKeyId)) {
    JSONResponse::error('API rate limit exceeded', 429);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$orderId = $_GET['id'] ?? null;
$action = $_GET['action'] ?? 'list';

$validator = new Validator();
$woo = new WooCommerceAPI();
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
            
            $statusFilter = '';
            $params = [$auth['sub']];
            
            if (!empty($_GET['status'])) {
                if (!$validator->orderStatus($_GET['status'])) {
                    JSONResponse::error('Invalid status filter', 400);
                }
                $statusFilter = " AND status = ?";
                $params[] = $_GET['status'];
            }
            
            $orders = $db->query(
                "SELECT * FROM reseller_orders 
                 WHERE reseller_id = ? $statusFilter 
                 ORDER BY created_at DESC 
                 LIMIT $offset, $perPage",
                $params
            )->fetchAll();
            
            $total = $db->query(
                "SELECT COUNT(*) as total FROM reseller_orders WHERE reseller_id = ? $statusFilter",
                $params
            )->fetch()['total'] ?? 0;
            
            $enriched = [];
            foreach ($orders as $order) {
                try {
                    $wcOrder = $woo->getOrder((int)$order['wc_order_id']);
                    $enriched[] = [
                        'id' => $order['id'],
                        'wc_order_id' => $order['wc_order_id'],
                        'status' => $wcOrder['status'],
                        'total' => $wcOrder['total'],
                        'customer' => [
                            'name' => ($wcOrder['billing']['first_name'] ?? '') . ' ' . ($wcOrder['billing']['last_name'] ?? ''),
                            'phone' => $wcOrder['billing']['phone'] ?? '',
                        ],
                        'commission' => $order['commission'],
                        'created_at' => $order['created_at'],
                    ];
                } catch (Exception $e) {
                    $enriched[] = [
                        'id' => $order['id'],
                        'wc_order_id' => $order['wc_order_id'],
                        'status' => $order['status'],
                        'total' => $order['total_amount'],
                        'created_at' => $order['created_at'],
                    ];
                }
            }
            
            JSONResponse::paginated($enriched, $page, $perPage, (int)$total);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                JSONResponse::error('Method not allowed', 405);
            }
            
            try {
                $hmac = new HMACMiddleware();
                $hmac->verify();
            } catch (Exception $e) {
                JSONResponse::error('HMAC verification required: ' . $e->getMessage(), 403);
            }
            
            $jwtMiddleware->requirePermission($auth, 'write:orders');
            
            $input = json_decode(file_get_contents('php://input'), true);
            $input = $validator->sanitizeArray($input ?? []);
            
            $required = ['customer', 'items', 'shipping_address'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    JSONResponse::error("Field '$field' required", 400);
                }
            }
            
            $customer = $input['customer'];
            if (empty($customer['name']) || empty($customer['phone'])) {
                JSONResponse::error('Customer name and phone required', 400);
            }
            
            if (!$validator->bdPhone($customer['phone'])) {
                JSONResponse::error('Invalid phone number', 400);
            }
            
            if (!is_array($input['items']) || empty($input['items'])) {
                JSONResponse::error('At least one item required', 400);
            }
            
            $lineItems = [];
            foreach ($input['items'] as $item) {
                $productId = $validator->id($item['product_id'] ?? 0);
                if (!$validator->passes()) {
                    JSONResponse::error('Invalid product ID in items', 400);
                }
                
                $qty = $validator->int($item['quantity'] ?? 0, 1, 100);
                if (!$validator->passes()) {
                    JSONResponse::error('Invalid quantity', 400);
                }
                
                try {
                    $product = $woo->getProduct($productId);
                    $lineItems[] = [
                        'product_id' => $productId,
                        'quantity' => $qty,
                        'price' => $product['price'],
                    ];
                } catch (Exception $e) {
                    JSONResponse::error("Product ID $productId not found", 400);
                }
            }
            
            $shipping = $input['shipping_address'];
            $wcOrderData = [
                'payment_method' => $input['payment_method'] ?? 'cod',
                'payment_method_title' => $input['payment_method_title'] ?? 'Cash on Delivery',
                'set_paid' => false,
                'billing' => [
                    'first_name' => explode(' ', $customer['name'])[0] ?? $customer['name'],
                    'last_name' => explode(' ', $customer['name'])[1] ?? '',
                    'address_1' => $shipping['address'] ?? '',
                    'city' => $shipping['city'] ?? 'Dhaka',
                    'state' => $shipping['state'] ?? 'BD',
                    'postcode' => $shipping['postcode'] ?? '',
                    'country' => 'BD',
                    'email' => $customer['email'] ?? 'customer@example.com',
                    'phone' => $customer['phone'],
                ],
                'shipping' => [
                    'first_name' => explode(' ', $customer['name'])[0] ?? $customer['name'],
                    'last_name' => explode(' ', $customer['name'])[1] ?? '',
                    'address_1' => $shipping['address'] ?? '',
                    'city' => $shipping['city'] ?? 'Dhaka',
                    'state' => $shipping['state'] ?? 'BD',
                    'postcode' => $shipping['postcode'] ?? '',
                    'country' => 'BD',
                ],
                'line_items' => array_map(function($item) {
                    return [
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                    ];
                }, $lineItems),
                'shipping_lines' => [
                    [
                        'method_id' => 'flat_rate',
                        'method_title' => $input['shipping_method'] ?? 'Standard Delivery',
                        'total' => (string)($input['shipping_cost'] ?? '60'),
                    ],
                ],
                'meta_data' => [
                    ['key' => '_reseller_id', 'value' => $auth['sub']],
                    ['key' => '_reseller_order_source', 'value' => 'api'],
                    ['key' => '_customer_phone', 'value' => $customer['phone']],
                ],
            ];
            
            $wcOrder = $woo->createOrder($wcOrderData);
            
            $subtotal = array_sum(array_map(function($item) {
                return (float)$item['price'] * (int)$item['quantity'];
            }, $lineItems));
            
            $commissionRate = [
                'basic' => 0.10, 'silver' => 0.15, 'gold' => 0.20, 'platinum' => 0.25,
            ][$auth['tier'] ?? 'basic'] ?? 0.10;
            
            $commission = round($subtotal * $commissionRate, 2);
            
            $db->query(
                "INSERT INTO reseller_orders 
                 (reseller_id, wc_order_id, customer_name, customer_phone, total_amount, commission, status, shipping_address, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $auth['sub'],
                    $wcOrder['id'],
                    $customer['name'],
                    $customer['phone'],
                    $wcOrder['total'],
                    $commission,
                    $wcOrder['status'],
                    json_encode($shipping),
                ]
            );
            
            // Discord notification
            if (!empty($env['DISCORD_WEBHOOK_URL'])) {
                sendDiscordNotification($env['DISCORD_WEBHOOK_URL'], [
                    'content' => "🛒 New Reseller Order!",
                    'embeds' => [[
                        'title' => "Order #{$wcOrder['id']}",
                        'fields' => [
                            ['name' => 'Reseller', 'value' => (string)$auth['sub'], 'inline' => true],
                            ['name' => 'Customer', 'value' => $customer['name'], 'inline' => true],
                            ['name' => 'Phone', 'value' => $customer['phone'], 'inline' => true],
                            ['name' => 'Total', 'value' => $wcOrder['total'] . ' BDT', 'inline' => true],
                            ['name' => 'Commission', 'value' => $commission . ' BDT', 'inline' => true],
                        ],
                        'color' => 0x00ff00,
                        'timestamp' => date('c'),
                    ]],
                ]);
            }
            
            JSONResponse::success([
                'order_id' => $wcOrder['id'],
                'status' => $wcOrder['status'],
                'total' => $wcOrder['total'],
                'commission' => $commission,
                'customer' => [
                    'name' => $customer['name'],
                    'phone' => $customer['phone'],
                ],
                'items_count' => count($lineItems),
                'created_at' => $wcOrder['date_created'],
            ], 201);
            break;
            
        case 'detail':
            if ($method !== 'GET' || empty($orderId)) {
                JSONResponse::error('Order ID required', 400);
            }
            
            $id = $validator->id($orderId);
            if (!$validator->passes()) {
                JSONResponse::error('Invalid order ID', 400);
            }
            
            $localOrder = $db->query(
                "SELECT * FROM reseller_orders WHERE id = ? AND reseller_id = ? LIMIT 1",
                [$id, $auth['sub']]
            )->fetch();
            
            if (!$localOrder) {
                JSONResponse::error('Order not found', 404);
            }
            
            $wcOrder = $woo->getOrder((int)$localOrder['wc_order_id']);
            
            JSONResponse::success([
                'id' => $localOrder['id'],
                'wc_order_id' => $wcOrder['id'],
                'status' => $wcOrder['status'],
                'total' => $wcOrder['total'],
                'customer' => [
                    'name' => ($wcOrder['billing']['first_name'] ?? '') . ' ' . ($wcOrder['billing']['last_name'] ?? ''),
                    'phone' => $wcOrder['billing']['phone'] ?? '',
                    'email' => $wcOrder['billing']['email'] ?? '',
                ],
                'shipping' => $wcOrder['shipping'],
                'items' => $wcOrder['line_items'],
                'payment' => [
                    'method' => $wcOrder['payment_method_title'],
                    'paid' => $wcOrder['date_paid'] !== null,
                ],
                'commission' => $localOrder['commission'],
                'created_at' => $localOrder['created_at'],
            ]);
            break;
            
        default:
            JSONResponse::error('Unknown action', 404);
    }
    
} catch (Exception $e) {
    JSONResponse::error($e->getMessage(), 500);
}

function sendDiscordNotification(string $webhook, array $data): void {
    $ch = curl_init($webhook);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}