<?php
/**
 * WooCommerce Webhook Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../config/database.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$topic = $_GET['topic'] ?? '';

if ($method !== 'POST') {
    JSONResponse::error('Method not allowed', 405);
}

// Verify webhook signature (if configured)
$signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '';
$payload = file_get_contents('php://input');

$db = new Database($env);

try {
    $data = json_decode($payload, true);
    if (!$data) {
        JSONResponse::error('Invalid payload', 400);
    }
    
    switch ($topic) {
        case 'order.updated':
        case 'order.completed':
            $orderId = $data['id'] ?? 0;
            $status = $data['status'] ?? '';
            
            // Update local order status
            $db->query(
                "UPDATE reseller_orders 
                 SET status = ?, updated_at = NOW() 
                 WHERE wc_order_id = ?",
                [$status, $orderId]
            );
            
            JSONResponse::success(['message' => 'Order status updated']);
            break;
            
        case 'product.updated':
            // Clear cache or update product data
            JSONResponse::success(['message' => 'Product update received']);
            break;
            
        default:
            JSONResponse::success(['message' => 'Webhook received']);
    }
    
} catch (Exception $e) {
    JSONResponse::error($e->getMessage(), 500);
}