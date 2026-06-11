<?php
/**
 * Database Connection (PDO)
 */

declare(strict_types=1);

class Database {
    private PDO $pdo;
    
    public function __construct(array $env) {
        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = $env['DB_PORT'] ?? '3306';
        $name = $env['DB_NAME'] ?? 'dropship_api';
        $user = $env['DB_USER'] ?? 'api_user';
        $pass = $env['DB_PASS'] ?? '';
        $charset = $env['DB_CHARSET'] ?? 'utf8mb4';
        
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset COLLATE utf8mb4_unicode_ci",
        ];
        
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new Exception('Database connection failed');
        }
    }
    
    public function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }
    
    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }
    
    public function commit(): bool {
        return $this->pdo->commit();
    }
    
    public function rollBack(): bool {
        return $this->pdo->rollBack();
    }
}