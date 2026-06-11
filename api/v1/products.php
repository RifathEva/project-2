<?php
/**
 * Products API Endpoint
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
    JSONResponse::error('API rate limit exceeded', 429);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$productId = $_GET['id'] ?? null;
$action = $_GET['action'] ?? 'list';

$validator = new Validator();
$woo = new WooCommerceAPI();

try {
    switch ($action) {
        case 'list':
            if ($method !== 'GET') {
                JSONResponse::error('Method not allowed', 405);
            }
            
            $filters = [
                'per_page' => min((int)($_GET['per_page'] ?? 20), 100),
                'page' => max((int)($_GET['page'] ?? 1), 1),
                'status' => 'publish',
            ];
            
            if (!empty($_GET['category'])) {
                $filters['category'] = (int)$_GET['category'];
            }
            
            if (!empty($_GET['search'])) {
                $filters['search'] = $validator->string($_GET['search'], 100);
            }
            
            $products = $woo->getProducts($filters);
            
            $resellerTier = $auth['tier'] ?? 'basic';
            $marginPercent = [
                'basic' => 10,
                'silver' => 15,
                'gold' => 20,
                'platinum' => 25,
            ][$resellerTier] ?? 10;
            
            $enriched = array_map(function($product) use ($marginPercent, $auth) {
                $basePrice = (float)($product['price'] ?? 0);
                $resellerPrice = $basePrice * (1 + ($marginPercent / 100));
                
                return [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'slug' => $product['slug'],
                    'sku' => $product['sku'],
                    'description' => $product['short_description'] ?? '',
                    'price' => [
                        'base' => $basePrice,
                        'reseller' => round($resellerPrice, 2),
                        'currency' => 'BDT',
                        'margin_percent' => $marginPercent,
                    ],
                    'stock' => [
                        'status' => $product['stock_status'] ?? 'instock',
                        'quantity' => $product['stock_quantity'] ?? null,
                    ],
                    'images' => array_map(function($img) {
                        return [
                            'src' => $img['src'],
                            'alt' => $img['alt'] ?? '',
                        ];
                    }, $product['images'] ?? []),
                    'categories' => array_map(function($cat) {
                        return ['id' => $cat['id'], 'name' => $cat['name']];
                    }, $product['categories'] ?? []),
                ];
            }, $products);
            
            $apiLimiter->addHeaders($apiKeyId);
            JSONResponse::success(['products' => $enriched]);
            break;
            
        case 'detail':
            if ($method !== 'GET' || empty($productId)) {
                JSONResponse::error('Product ID required', 400);
            }
            
            $id = $validator->id($productId);
            if (!$validator->passes()) {
                JSONResponse::error('Invalid product ID', 400);
            }
            
            $product = $woo->getProduct($id);
            
            $resellerTier = $auth['tier'] ?? 'basic';
            $marginPercent = [
                'basic' => 10, 'silver' => 15, 'gold' => 20, 'platinum' => 25,
            ][$resellerTier] ?? 10;
            
            $basePrice = (float)($product['price'] ?? 0);
            
            JSONResponse::success([
                'id' => $product['id'],
                'name' => $product['name'],
                'description' => $product['description'] ?? '',
                'sku' => $product['sku'],
                'price' => [
                    'base' => $basePrice,
                    'reseller' => round($basePrice * (1 + ($marginPercent / 100)), 2),
                    'currency' => 'BDT',
                ],
                'stock' => [
                    'status' => $product['stock_status'],
                    'quantity' => $product['stock_quantity'],
                ],
                'images' => $product['images'] ?? [],
                'attributes' => $product['attributes'] ?? [],
                'variations' => $product['variations'] ?? [],
            ]);
            break;
            
        default:
            JSONResponse::error('Unknown action', 404);
    }
    
} catch (Exception $e) {
    JSONResponse::error($e->getMessage(), 500);
}