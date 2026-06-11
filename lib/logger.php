<?php
/**
 * Security Audit Logger
 */

declare(strict_types=1);

class SecurityLogger {
    private string $logDir;
    private string $aesKey;
    private int $retentionDays;
    
    public function __construct(array $env) {
        $this->logDir = __DIR__ . '/../storage/logs/';
        $this->aesKey = $env['AES_KEY'] ?? '';
        $this->retentionDays = (int)($env['LOG_RETENTION_DAYS'] ?? '90');
        
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0750, true);
        }
    }
    
    public function log(string $event, string $actor, array $context = []): void {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s.u'),
            'event' => $event,
            'actor' => $actor,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'context' => $context,
        ];
        
        $json = json_encode($entry);
        $encrypted = $this->encrypt($json);
        
        $filename = $this->logDir . date('Y-m-d') . '.log.enc';
        file_put_contents($filename, $encrypted . "\n", FILE_APPEND | LOCK_EX);
    }
    
    private function encrypt(string $data): string {
        if (empty($this->aesKey) || strlen($this->aesKey) !== 32) {
            return base64_encode($data);
        }
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public function readLogs(string $date): array {
        $filename = $this->logDir . $date . '.log.enc';
        if (!file_exists($filename)) {
            return [];
        }
        
        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        $logs = [];
        foreach ($lines as $line) {
            $logs[] = $this->decrypt($line);
        }
        return $logs;
    }
    
    private function decrypt(string $data): array {
        if (empty($this->aesKey) || strlen($this->aesKey) !== 32) {
            return json_decode(base64_decode($data), true) ?? [];
        }
        $raw = base64_decode($data);
        $iv = substr($raw, 0, 16);
        $encrypted = substr($raw, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA, $iv);
        return json_decode($decrypted, true) ?? [];
    }
}