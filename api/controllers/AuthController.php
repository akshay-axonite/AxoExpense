<?php
/**
 * Axonite Expense Tracking System - Authentication Controller
 * 
 * Handles all user identity operations:
 * - User login (username/email + PBKDF2 password verification)
 * - User registration (creates new account with salt and default settings)
 * - Profile retrieval and update
 * - Session token refresh and logout revocation
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../auth.php';

class AuthController {

    /**
     * User Login API: POST /api/v1/users/login.json
     * 
     * Verifies user credentials against MySQL user table, creates a token session,
     * and returns the signed bearer token and user profile to the Vue frontend.
     */
    public function login(): void {
        $input = Utils::getJsonInput();
        $loginName = trim($input['loginName'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($loginName) || empty($password)) {
            Utils::errorResponse(200000, 'Username and password are required');
        }

        $pdo = Database::getConnection();

        // Find user by either username or email
        $stmt = $pdo->prepare('
            SELECT * FROM user 
            WHERE (username = :login OR email = :login) 
              AND deleted = 0 
            LIMIT 1
        ');
        $stmt->execute([':login' => $loginName]);
        $user = $stmt->fetch();

        if (!$user) {
            Utils::errorResponse(201001, 'Username or password is not correct');
        }

        if (!empty($user['disabled'])) {
            Utils::errorResponse(201003, 'Your account has been disabled');
        }

        // Verify PBKDF2-SHA256 password hash (100% compatible with existing Go database)
        if (!Utils::verifyPassword($password, $user['salt'], $user['password'])) {
            Utils::errorResponse(201001, 'Username or password is not correct');
        }

        // Session creation: create a new record in `token_record`
        require_once __DIR__ . '/../config.php';
        $now = time();
        $expiredTime = $now + (Config::$settings['security']['token_lifetime'] ?? 2592000);
        $userTokenId = Utils::generateUniqueId();
        $tokenSecret = Utils::generateRandomString(10);
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $tokenStmt = $pdo->prepare('
            INSERT INTO token_record 
            (uid, user_token_id, token_type, secret, user_agent, context, created_unix_time, expired_unix_time, last_seen_unix_time)
            VALUES 
            (:uid, :utid, 1, :secret, :ua, "", :cut, :eut, :lsut)
        ');
        $tokenStmt->execute([
            ':uid'    => $user['uid'],
            ':utid'   => $userTokenId,
            ':secret' => $tokenSecret,
            ':ua'     => $userAgent,
            ':cut'    => $now,
            ':eut'    => $expiredTime,
            ':lsut'   => $now
        ]);

        // Generate signed Bearer token string
        $tokenString = Utils::createTokenString($user['uid'], $userTokenId, $tokenSecret, $now);

        // Update user last login timestamp
        $updateStmt = $pdo->prepare('UPDATE user SET last_login_unix_time = :now WHERE uid = :uid');
        $updateStmt->execute([':now' => $now, ':uid' => $user['uid']]);

        // Return Vue-compatible login response
        Utils::successResponse([
            'token' => $tokenString,
            'user'  => self::formatUserProfile($user)
        ]);
    }

    /**
     * User Registration API: POST /api/v1/users/register.json
     * 
     * Registers a new user with secure salt, hashes password with PBKDF2,
     * provisions default account, and returns authenticated session token.
     */
    public function register(): void {
        $input = Utils::getJsonInput();
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $nickname = trim($input['nickname'] ?? '') ?: $username;
        $password = $input['password'] ?? '';
        $defaultCurrency = trim($input['defaultCurrency'] ?? '') ?: 'INR';

        if (empty($username) || empty($email) || empty($password)) {
            Utils::errorResponse(200000, 'Username, email, and password are required');
        }

        $pdo = Database::getConnection();

        // Check for existing username or email
        $checkStmt = $pdo->prepare('SELECT uid FROM user WHERE username = :u OR email = :e LIMIT 1');
        $checkStmt->execute([':u' => $username, ':e' => $email]);
        if ($checkStmt->fetch()) {
            Utils::errorResponse(201004, 'Username or email already exists');
        }

        // Generate new 64-bit UID and 10-char salt
        $uid = Utils::generateUniqueId();
        $salt = Utils::generateRandomString(10);
        $encodedPassword = Utils::encodePassword($password, $salt);
        $now = time();

        $insertStmt = $pdo->prepare('
            INSERT INTO user 
            (uid, username, email, nickname, password, salt, default_account_id, default_currency, language, first_day_of_week, transaction_edit_scope, email_verified, created_unix_time, updated_unix_time, deleted)
            VALUES 
            (:uid, :username, :email, :nickname, :password, :salt, 0, :currency, "en", 0, 1, 1, :created_unix_time, :updated_unix_time, 0)
        ');
        $insertStmt->execute([
            ':uid'      => $uid,
            ':username' => $username,
            ':email'    => $email,
            ':nickname' => $nickname,
            ':password' => $encodedPassword,
            ':salt'     => $salt,
            ':currency' => $defaultCurrency,
            ':created_unix_time' => $now,
            ':updated_unix_time' => $now
        ]);

        // Provision a default Cash holding account
        $defaultAccountId = Utils::generateUniqueId();
        $accStmt = $pdo->prepare('
            INSERT INTO account 
            (uid, account_id, name, category, type, currency, balance, created_unix_time, updated_unix_time, deleted)
            VALUES 
            (:uid, :acc_id, "Cash Account", 1, 1, :currency, 0, :created_unix_time, :updated_unix_time, 0)
        ');
        $accStmt->execute([
            ':uid'      => $uid,
            ':acc_id'   => $defaultAccountId,
            ':currency' => $defaultCurrency,
            ':created_unix_time' => $now,
            ':updated_unix_time' => $now
        ]);

        // Link default account to user
        $pdo->prepare('UPDATE user SET default_account_id = :acc_id WHERE uid = :uid')
            ->execute([':acc_id' => $defaultAccountId, ':uid' => $uid]);

        // Auto-login after registration
        $userTokenId = Utils::generateUniqueId();
        $tokenSecret = Utils::generateRandomString(10);
        require_once __DIR__ . '/../config.php';
        $expiredTime = $now + (Config::$settings['security']['token_lifetime'] ?? 2592000);

        $pdo->prepare('
            INSERT INTO token_record 
            (uid, user_token_id, token_type, secret, user_agent, context, created_unix_time, expired_unix_time, last_seen_unix_time)
            VALUES 
            (:uid, :utid, 1, :secret, "", "", :now, :eut, :now)
        ')->execute([
            ':uid'    => $uid,
            ':utid'   => $userTokenId,
            ':secret' => $tokenSecret,
            ':now'    => $now,
            ':eut'    => $expiredTime
        ]);

        $tokenString = Utils::createTokenString($uid, $userTokenId, $tokenSecret, $now);

        // Fetch inserted user
        $fetchStmt = $pdo->prepare('SELECT * FROM user WHERE uid = :uid LIMIT 1');
        $fetchStmt->execute([':uid' => $uid]);
        $newUser = $fetchStmt->fetch();

        Utils::successResponse([
            'token' => $tokenString,
            'user'  => self::formatUserProfile($newUser)
        ]);
    }

    /**
     * User Profile API: GET /api/v1/users/profile/get.json
     * 
     * Returns full user basic details for the authenticated user.
     */
    public function getProfile(): void {
        $user = AuthMiddleware::authenticate();
        Utils::successResponse(self::formatUserProfile($user));
    }

    /**
     * User Profile Update API: POST /api/v1/users/profile/update.json
     */
    public function updateProfile(): void {
        $user = AuthMiddleware::authenticate();
        $input = Utils::getJsonInput();

        $email = trim($input['email'] ?? $user['email']);
        $nickname = trim($input['nickname'] ?? $user['nickname']);
        $defaultCurrency = trim($input['defaultCurrency'] ?? $user['default_currency']);
        $firstDayOfWeek = isset($input['firstDayOfWeek']) ? (int)$input['firstDayOfWeek'] : (int)$user['first_day_of_week'];
        $now = time();

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE user SET email = :email, nickname = :nickname, default_currency = :curr, first_day_of_week = :fdw, updated_unix_time = :now WHERE uid = :uid");
        $stmt->execute([
            ':email' => $email,
            ':nickname' => $nickname,
            ':curr' => $defaultCurrency,
            ':fdw' => $firstDayOfWeek,
            ':now' => $now,
            ':uid' => $user['uid'],
        ]);

        $stmt = $pdo->prepare("SELECT * FROM user WHERE uid = :uid LIMIT 1");
        $stmt->execute([':uid' => $user['uid']]);
        $updatedUser = $stmt->fetch();

        Utils::successResponse(self::formatUserProfile($updatedUser));
    }

    /**
     * Lists active session tokens: GET /api/v1/tokens/list.json
     */
    public function listTokens(): void {
        $user = AuthMiddleware::authenticate();
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT user_token_id, user_agent, created_unix_time, last_seen_unix_time FROM token_record WHERE uid = :uid ORDER BY last_seen_unix_time DESC");
        $stmt->execute([':uid' => $user['uid']]);
        $rows = $stmt->fetchAll();

        $tokens = [];
        foreach ($rows as $r) {
            $tokens[] = [
                'tokenId' => (string)$r['user_token_id'],
                'userAgent' => (string)($r['user_agent'] ?? ''),
                'createdTime' => (int)$r['created_unix_time'],
                'lastSeenTime' => (int)$r['last_seen_unix_time'],
            ];
        }

        Utils::successResponse($tokens);
    }

    /**
     * Token Refresh API: POST /api/v1/tokens/refresh.json
     */
    public function refreshToken(): void {
        $user = AuthMiddleware::authenticate();
        require_once __DIR__ . '/../config.php';
        $now = time();
        $newTokenId = Utils::generateUniqueId();
        $tokenSecret = Utils::generateRandomString(10);
        $expiredTime = $now + (Config::$settings['security']['token_lifetime'] ?? 2592000);

        $pdo = Database::getConnection();
        $pdo->prepare('
            INSERT INTO token_record 
            (uid, user_token_id, token_type, secret, user_agent, context, created_unix_time, expired_unix_time, last_seen_unix_time)
            VALUES 
            (:uid, :utid, 1, :secret, "", "", :now, :eut, :now)
        ')->execute([
            ':uid'    => $user['uid'],
            ':utid'   => $newTokenId,
            ':secret' => $tokenSecret,
            ':now'    => $now,
            ':eut'    => $expiredTime
        ]);

        $newTokenString = Utils::createTokenString($user['uid'], $newTokenId, $tokenSecret, $now);
        Utils::successResponse([
            'newToken' => $newTokenString,
            'oldTokenId' => ''
        ]);
    }

    /**
     * Token Revoke API (Logout): POST /api/v1/tokens/revoke.json
     */
    public function revokeToken(): void {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $tokenData = Utils::parseTokenString($matches[1]);
            if ($tokenData) {
                $pdo = Database::getConnection();
                $pdo->prepare('DELETE FROM token_record WHERE uid = :uid AND user_token_id = :utid')
                    ->execute([':uid' => $tokenData['uid'], ':utid' => $tokenData['userTokenId']]);
            }
        }
        Utils::successResponse(true);
    }

    /**
     * Formats a MySQL user record into the exact JSON schema expected by Vue frontend.
     * 
     * @param array $user MySQL user row
     * @return array      Standardized user profile dictionary
     */
    public static function formatUserProfile(array $user): array {
        return [
            'username'              => (string)($user['username'] ?? ''),
            'email'                 => (string)($user['email'] ?? ''),
            'nickname'              => (string)($user['nickname'] ?: $user['username'] ?? ''),
            'avatar'                => !empty($user['custom_avatar_type']) ? ('/api/v1/users/avatar?uid=' . $user['uid']) : '',
            'defaultAccountId'      => (string)($user['default_account_id'] ?? 0),
            'useLastReconciledTime' => (bool)($user['use_last_reconciled_time'] ?? false),
            'transactionEditScope'  => (int)($user['transaction_edit_scope'] ?? 1),
            'language'              => (string)($user['language'] ?: 'en'),
            'defaultCurrency'       => (string)($user['default_currency'] ?: 'INR'),
            'firstDayOfWeek'        => (int)($user['first_day_of_week'] ?? 0),
            'fiscalYearStart'       => (int)($user['fiscal_year_start'] ?? 0),
            'calendarDisplayType'   => (int)($user['calendar_display_type'] ?? 0),
            'dateDisplayType'       => (int)($user['date_display_type'] ?? 0),
            'longDateFormat'        => (int)($user['long_date_format'] ?? 0),
            'shortDateFormat'       => (int)($user['short_date_format'] ?? 0),
            'longTimeFormat'        => (int)($user['long_time_format'] ?? 0),
            'shortTimeFormat'       => (int)($user['short_time_format'] ?? 0),
            'fiscalYearFormat'      => (int)($user['fiscal_year_format'] ?? 0),
            'currencyDisplayType'   => (int)($user['currency_display_type'] ?? 0),
            'numeralSystem'         => (int)($user['numeral_system'] ?? 0),
            'decimalSeparator'      => (int)($user['decimal_separator'] ?? 0),
            'digitGroupingSymbol'   => (int)($user['digit_grouping_symbol'] ?? 0),
            'digitGrouping'         => (int)($user['digit_grouping'] ?? 0),
            'coordinateDisplayType' => (int)($user['coordinate_display_type'] ?? 0),
            'expenseAmountColor'    => (int)($user['expense_amount_color'] ?? 0),
            'incomeAmountColor'     => (int)($user['income_amount_color'] ?? 0),
            'emailVerified'         => (bool)($user['email_verified'] ?? true)
        ];
    }
}
