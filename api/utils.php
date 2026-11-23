<?php
/**
 * Axonite Expense Tracking System - Utility & Helper Functions
 * 
 * Provides:
 * - Go-compatible PBKDF2 password encryption (10,000 rounds, SHA-256)
 * - Session token creation and validation
 * - Standardized JSON response formatters ({ "success": true, "result": ... })
 * - Unique 64-bit ID generator (Snowflake-like integer)
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

class Utils {

    /**
     * Encodes a password using PBKDF2-SHA256, byte-for-byte identical to Go's EncodePassword.
     * 
     * @param string $password The raw plain-text password
     * @param string $salt     The user's unique 10-character salt
     * @return string          Base64 encoded hash with trimmed trailing padding '='
     */
    public static function encodePassword(string $password, string $salt): string {
        $raw = hash_pbkdf2('sha256', $password, $salt, 10000, 48, true);
        return rtrim(base64_encode($raw), '=');
    }

    /**
     * Verifies whether an input password matches the stored encoded password hash.
     * 
     * @param string $inputPassword  The password provided at login
     * @param string $salt           The salt from MySQL user table
     * @param string $storedPassword The password hash from MySQL user table
     * @return bool                  True if valid, false otherwise
     */
    public static function verifyPassword(string $inputPassword, string $salt, string $storedPassword): bool {
        $computed = self::encodePassword($inputPassword, $salt);
        return hash_equals($storedPassword, $computed);
    }

    /**
     * Generates a random alphanumeric salt string.
     * 
     * @param int $length Desired character length (default 10)
     * @return string
     */
    public static function generateRandomString(int $length = 10): string {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charLength - 1)];
        }
        return $randomString;
    }

    /**
     * Generates a unique 64-bit integer ID for new records (Users, Accounts, Transactions).
     * Format: Millisecond timestamp (41 bits) + random sequence (22 bits).
     * 
     * @return int 64-bit integer ID
     */
    public static function generateUniqueId(): int {
        $milli = (int)(microtime(true) * 1000);
        $random = random_int(1000, 9999);
        return (int)($milli . $random);
    }

    /**
     * Creates a signed bearer token string for client-side authentication.
     * Format: Base64URL(uid.userTokenId.secret.createdTime.signature)
     * 
     * @param int    $uid             User ID
     * @param int    $userTokenId     Unique token sequence ID
     * @param string $secret          Token secret
     * @param int    $createdUnixTime Token creation timestamp
     * @return string                 Bearer token string
     */
    public static function createTokenString(int $uid, int $userTokenId, string $secret, int $createdUnixTime): string {
        require_once __DIR__ . '/config.php';
        $secretKey = Config::$settings['security']['secret_key'];
        
        $payload = "{$uid}:{$userTokenId}:{$secret}:{$createdUnixTime}";
        $signature = hash_hmac('sha256', $payload, $secretKey);
        
        return 'ebk-' . rtrim(strtr(base64_encode("{$payload}:{$signature}"), '+/', '-_'), '=');
    }

    /**
     * Parses and verifies a bearer token string.
     * 
     * @param string $tokenString Raw token from Authorization header
     * @return array|null         Returns ['uid' => ..., 'userTokenId' => ..., 'secret' => ..., 'createdUnixTime' => ...] or null if invalid
     */
    public static function parseTokenString(string $tokenString): ?array {
        if (!str_starts_with($tokenString, 'ebk-')) {
            return null;
        }

        require_once __DIR__ . '/config.php';
        $secretKey = Config::$settings['security']['secret_key'];

        $b64 = substr($tokenString, 4);
        $data = base64_decode(strtr($b64, '-_', '+/'));
        if (!$data) {
            return null;
        }

        $parts = explode(':', $data);
        if (count($parts) !== 5) {
            return null;
        }

        [$uid, $userTokenId, $secret, $createdUnixTime, $signature] = $parts;
        $payload = "{$uid}:{$userTokenId}:{$secret}:{$createdUnixTime}";
        $expectedSignature = hash_hmac('sha256', $payload, $secretKey);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return [
            'uid'             => (int)$uid,
            'userTokenId'     => (int)$userTokenId,
            'secret'          => $secret,
            'createdUnixTime' => (int)$createdUnixTime
        ];
    }

    /**
     * Sends a successful JSON response adhering to Vue frontend schema.
     * 
     * @param mixed $result Data payload to return
     * @return void
     */
    public static function successResponse($result = null): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'result'  => $result
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Sends an error JSON response adhering to Vue frontend schema.
     * 
     * @param int    $errorCode    Application error code (e.g. 200000, 201001)
     * @param string $errorMessage User-facing error message
     * @param int    $httpStatus   HTTP status code (default 200, matching Go backend API behavior)
     * @return void
     */
    public static function errorResponse(int $errorCode, string $errorMessage, int $httpStatus = 200): void {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'      => false,
            'errorCode'    => $errorCode,
            'errorMessage' => $errorMessage
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Reads incoming JSON body from php://input.
     * 
     * @return array Parsed associative array
     */
    public static function getJsonInput(): array {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Retrieves client remote IP address.
     * 
     * @return string Client IP address
     */
    public static function getClientIp(): string {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
