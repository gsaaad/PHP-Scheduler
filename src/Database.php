<?php

namespace App;

use PDO;
use PDOException;

class Database {
    private $pdo;

    public function __construct() {
        $config = require __DIR__ . '/../config/database.php';
        
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['dbname']}";
        
        try {
            $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->pdo;
    }
}
