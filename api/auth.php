<?php
/**
 * Axonite Expense Tracking System - Authentication Middleware
 * 
 * Intercepts incoming API requests, extracts the Bearer token from the
 * Authorization header, verifies the token against the MySQL `token_record` table,
 * and attaches the authenticated user's details to the request context.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

class AuthMiddleware {

    /**
     * Authenticates the current HTTP request.
     * 
     * @return array The authenticated user's record from `user` table
     */
    public static function authenticate(): array {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (empty($authHeader) || !preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            // Also check query parameter 'token' (used for avatar and picture previews)
            $tokenString = $_GET['token'] ?? '';
            if (empty($tokenString)) {
                Utils::errorResponse(202012, 'Token is empty', 401);
            }
        } else {
            $tokenString = $matches[1];
        }

        // Parse token structure and verify HMAC signature
        $tokenData = Utils::parseTokenString($tokenString);
        if (!$tokenData) {
            Utils::errorResponse(202002, 'Current token is invalid', 401);
        }

        $pdo = Database::getConnection();

        // Query database to ensure token session exists and is active
        $stmt = $pdo->prepare('
            SELECT * FROM token_record 
            WHERE uid = :uid AND user_token_id = :utid AND created_unix_time = :cut
            LIMIT 1
        ');
        $stmt->execute([
            ':uid'  => $tokenData['uid'],
            ':utid' => $tokenData['userTokenId'],
            ':cut'  => $tokenData['createdUnixTime']
        ]);
        $record = $stmt->fetch();

        if (!$record) {
            Utils::errorResponse(202002, 'Current token is invalid or logged out', 401);
        }

        // Verify token expiration
        $now = time();
        if ($record['expired_unix_time'] < $now) {
            Utils::errorResponse(202003, 'Current token is expired', 401);
        }

        // Fetch associated user
        $userStmt = $pdo->prepare('
            SELECT * FROM user 
            WHERE uid = :uid AND deleted = 0 AND disabled = 0 
            LIMIT 1
        ');
        $userStmt->execute([':uid' => $tokenData['uid']]);
        $user = $userStmt->fetch();

        if (!$user) {
            Utils::errorResponse(201002, 'User does not exist or has been disabled', 401);
        }

        // Update token last seen timestamp asynchronously
        try {
            $updateStmt = $pdo->prepare('UPDATE token_record SET last_seen_unix_time = :now WHERE uid = :uid AND user_token_id = :utid');
            $updateStmt->execute([':now' => $now, ':uid' => $tokenData['uid'], ':utid' => $tokenData['userTokenId']]);
        } catch (Exception $e) {
            // Ignore minor last seen update failure
        }

        return $user;
    }
}
