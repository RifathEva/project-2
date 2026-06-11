<?php
/**
 * WooCommerce API Configuration
 */

declare(strict_types=1);

require_once __DIR__ . '/security.php';

class WooCommerceAPI {
    private SecurityConfig $config;
    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private int $timeout;
    
    public function __construct() {
        $this->config = new SecurityConfig();
        $this->baseUrl = rtrim($this->config->get('WC_URL'), '/');
        $this->consumerKey = $this->config->get('WC_CONSUMER_KEY');
        $this->consumerSecret = $this->config->get('WC_CONSUMER_SECRET');
        $this->timeout = (int)$this->config->get('WC_TIMEOUT', '30');
        
        if (empty($this->consumerKey) || empty($this->consumerSecret)) {
            throw new Exception('WooCommerce credentials not configured');
        }
    }
    
    public function request(string $endpoint, string $method = 'GET', array $data = []): array {
        $url = $this->baseUrl . '/wp-json/' . $this->config->get('WC_VERSION', 'wc/v3') . '/' . ltrim($endpoint, '/');
        
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query([
            'consumer_key' => $this->consumerKey,
            'consumer_secret' => $this->consumerSecret,
        ]));
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Request-Source: Headless-API',
        ]);
        
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('WooCommerce API connection failed: ' . $error);
        }
        
        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            throw new Exception('WooCommerce API error: ' . ($decoded['message'] ?? 'Unknown error'));
        }
        
        return $decoded ?? [];
    }
    
    public function getProducts(array $filters = []): array {
        return $this->request('products?' . http_build_query($filters));
    }
    
    public function getProduct(int $id): array {
        return $this->request('products/' . $id);
    }
    
    public function createOrder(array $data): array {
        return $this->request('orders', 'POST', $data);
    }
    
    public function getOrder(int $id): array {
        return $this->request('orders/' . $id);
    }
    
    public function updateOrder(int $id, array $data): array {
        return $this->request('orders/' . $id, 'PUT', $data);
    }
    
    public function getCategories(): array {
        return $this->request('products/categories');
    }
    
    public function getCustomers(array $filters = []): array {
        return $this->request('customers?' . http_build_query($filters));
    }
    
    public function createCustomer(array $data): array {
        return $this->request('customers', 'POST', $data);
    }
}