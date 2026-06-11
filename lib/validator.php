<?php
/**
 * Input Validation & Sanitization
 */

declare(strict_types=1);

class Validator {
    private array $errors = [];
    
    public function email(string $email): bool {
        $clean = filter_var($email, FILTER_SANITIZE_EMAIL);
        if (!filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = 'Invalid email format';
            return false;
        }
        return true;
    }
    
    public function int($value, int $min = null, int $max = null): bool {
        if (!is_numeric($value)) {
            $this->errors[] = 'Value must be numeric';
            return false;
        }
        $int = (int)$value;
        if ($min !== null && $int < $min) {
            $this->errors[] = "Value must be >= $min";
            return false;
        }
        if ($max !== null && $int > $max) {
            $this->errors[] = "Value must be <= $max";
            return false;
        }
        return true;
    }
    
    public function string(string $value, int $maxLength = 255, bool $allowHtml = false): string {
        if (strlen($value) > $maxLength) {
            $this->errors[] = "String exceeds maximum length of $maxLength";
            return '';
        }
        
        if (!$allowHtml) {
            $value = strip_tags($value);
            $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $allowed = '<p><br><strong><em><ul><ol><li>';
            $value = strip_tags($value, $allowed);
        }
        
        return $value;
    }
    
    public function bdPhone(string $phone): bool {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (!preg_match('/^(8801|01)[3-9][0-9]{8}$/', $clean)) {
            $this->errors[] = 'Invalid Bangladesh phone number';
            return false;
        }
        return true;
    }
    
    public function apiKey(string $key): bool {
        if (!preg_match('/^[a-zA-Z0-9]{32,64}$/', $key)) {
            $this->errors[] = 'Invalid API key format';
            return false;
        }
        return true;
    }
    
    public function json(string $json): ?array {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = 'Invalid JSON payload';
            return null;
        }
        return $decoded;
    }
    
    public function orderStatus(string $status): bool {
        $allowed = ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'];
        if (!in_array(strtolower($status), $allowed, true)) {
            $this->errors[] = 'Invalid order status';
            return false;
        }
        return true;
    }
    
    public function sanitizeArray(array $data): array {
        $clean = [];
        foreach ($data as $key => $value) {
            $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            
            if (is_array($value)) {
                $clean[$safeKey] = $this->sanitizeArray($value);
            } elseif (is_string($value)) {
                $clean[$safeKey] = $this->string($value, 1000);
            } elseif (is_numeric($value)) {
                $clean[$safeKey] = $value;
            } elseif (is_bool($value)) {
                $clean[$safeKey] = $value;
            } else {
                $clean[$safeKey] = null;
            }
        }
        return $clean;
    }
    
    public function getErrors(): array {
        return $this->errors;
    }
    
    public function passes(): bool {
        return empty($this->errors);
    }
    
    public function id($id): int {
        if (!is_numeric($id) || (int)$id != $id || (int)$id <= 0) {
            $this->errors[] = 'Invalid ID format';
            return 0;
        }
        return (int)$id;
    }
}