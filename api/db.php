<?php
/**
 * Axonite Expense Tracking System - Database Connection (PDO Singleton)
 * 
 * Provides a shared, persistent connection to the MySQL database.
 * Handles database connection exceptions and returns standardized JSON errors.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

class Database {
    private static ?PDO $instance = null;

    /**
     * Get the active PDO database connection instance.
     * 
     * @return PDO Active PDO database handle
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            require_once __DIR__ . '/config.php';
            $db = Config::$settings['db'];

            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset={$db['charset']}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Enable emulation to allow safe reuse of named placeholders without HY093 errors
                PDO::ATTR_EMULATE_PREPARES   => true,
            ];

            try {
                self::$instance = new PDO($dsn, $db['username'], $db['password'], $options);
            } catch (PDOException $e) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'errorCode' => 100000,
                    'errorMessage' => 'Database connection failed: ' . $e->getMessage()
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        return self::$instance;
    }

    /**
     * Singleton alias for getConnection()
     */
    public static function getInstance(): PDO {
        return self::getConnection();
    }
}
