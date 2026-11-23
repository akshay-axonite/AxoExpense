<?php
/**
 * Axonite Expense Tracking System - API Configuration
 * 
 * Defines database connection parameters, security secret keys, and storage paths.
 * Adjust database credentials here when deploying to production shared hosting.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

if (!class_exists('Config')) {
    class Config {
        // Base application URL (leave empty for relative URLs like /storage/...)
        public const APP_URL = '';

        // Uploaded receipts, bills, and payment proof photos directory
        public const STORAGE_PATH = __DIR__ . '/../storage';
        public const TRANSACTION_STORAGE_PATH = __DIR__ . '/../storage/transaction';

        // Storage directory
        public static array $settings = [
            // Database connection details (PDO MySQL)
            // Live Server (StackCP Hosting)
            'db' => [
                'host'     => '127.0.0.1',
                'port'     => 3306,
                'dbname'   => 'ezbookkeeping',
                'username' => 'root',
                'password' => '',
                'charset'  => 'utf8mb4'
            ],

            // Security & token settings
            'security' => [
                // Secret key used for token signing and password encryption
                'secret_key'     => 'AxonitExpenseSecretKey2026SecureSaltTokens!',
                // Lifetime of a user login session token in seconds (30 days = 2,592,000s)
                'token_lifetime' => 2592000
            ],

            // Storage directory
            'storage' => [
                'path' => __DIR__ . '/../storage'
            ]
        ];

        /**
         * Auto-detect environment:
         * When running on Local Development Server (127.0.0.1:8000), use local MySQL.
         * When uploaded to Live Hosting (StackCP), use live sdb-52.hosting.stackcp.net.
         */
        public static function init(): void {
            $isLocal = (php_sapi_name() === 'cli-server')
                    || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'])
                    || in_array(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0], ['localhost', '127.0.0.1']);

            if ($isLocal) {
                self::$settings['db'] = [
                    'host'     => '127.0.0.1',
                    'port'     => 3306,
                    'dbname'   => 'ezbookkeeping',
                    'username' => 'root',
                    'password' => '',
                    'charset'  => 'utf8mb4'
                ];
            }
        }

        public static function get(?string $key = null) {
            if ($key === null) {
                return self::$settings;
            }
            return self::$settings[$key] ?? null;
        }
    }

    Config::init();
}

return Config::$settings;
