<?php
/**
 * Axonite Expense Tracking System - Account Controller
 * 
 * Handles bank accounts, cash wallets, credit cards, investments, and sub-accounts.
 * Compatible with ezbookkeeping Vue 3 frontend schemas and MySQL database.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

class AccountController {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Lists all accounts belonging to the authenticated user.
     * Groups sub-accounts under their parent accounts as expected by the frontend.
     */
    public function listAccounts(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $visibleOnly = isset($_GET['visible_only']) && ($_GET['visible_only'] === '1' || $_GET['visible_only'] === 'true');

        $sql = "SELECT * FROM account WHERE uid = :uid AND deleted = 0";
        if ($visibleOnly) {
            $sql .= " AND hidden = 0";
        }
        $sql .= " ORDER BY display_order ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':uid' => $uid]);
        $rows = $stmt->fetchAll();

        $accountMap = [];
        $parentAccounts = [];
        $subAccounts = [];

        foreach ($rows as $row) {
            $formatted = $this->formatAccount($row);
            $accountMap[$formatted['id']] = $formatted;

            if ($formatted['parentId'] === '0') {
                $parentAccounts[] = $formatted;
            } else {
                $subAccounts[] = $formatted;
            }
        }

        // Attach sub-accounts to their respective parent account
        foreach ($subAccounts as $sub) {
            $pId = $sub['parentId'];
            foreach ($parentAccounts as &$parent) {
                if ($parent['id'] === $pId) {
                    $parent['subAccounts'][] = $sub;
                    break;
                }
            }
        }

        Utils::successResponse($parentAccounts);
    }

    /**
     * Retrieves a single account by ID, along with any child sub-accounts.
     */
    public function getAccount(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $id = $_GET['id'] ?? null;
        if (!$id) {
            Utils::errorResponse(200001, 'Account ID is required');
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM account WHERE account_id = :id AND uid = :uid AND deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        $row = $stmt->fetch();

        if (!$row) {
            Utils::errorResponse(200002, 'Account not found', 404);
            return;
        }

        $formatted = $this->formatAccount($row);

        // Fetch sub-accounts if parent account
        if ($formatted['parentId'] === '0') {
            $subStmt = $this->db->prepare("SELECT * FROM account WHERE parent_account_id = :id AND uid = :uid AND deleted = 0 ORDER BY display_order ASC");
            $subStmt->execute([':id' => $id, ':uid' => $uid]);
            $subRows = $subStmt->fetchAll();

            foreach ($subRows as $subRow) {
                $formatted['subAccounts'][] = $this->formatAccount($subRow);
            }
        }

        Utils::successResponse($formatted);
    }

    /**
     * Creates a new account or a parent account with sub-accounts.
     */
    public function addAccount(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $name = trim($input['name'] ?? '');
        $category = (int)($input['category'] ?? 1);
        $type = (int)($input['type'] ?? 1); // 1 = Single Account, 2 = Multi Sub-accounts
        $icon = (int)($input['icon'] ?? 1);
        $iconType = (int)($input['iconType'] ?? 0);
        $color = trim($input['color'] ?? '000000');
        $currency = trim($input['currency'] ?? ($currentUser['default_currency'] ?? 'INR'));
        $balance = (int)($input['balance'] ?? 0);
        $comment = trim($input['comment'] ?? '');

        if ($name === '') {
            Utils::errorResponse(200003, 'Account name cannot be empty');
            return;
        }

        // Get max display order
        $orderStmt = $this->db->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM account WHERE uid = :uid AND parent_account_id = 0");
        $orderStmt->execute([':uid' => $uid]);
        $displayOrder = (int)$orderStmt->fetch()['next_order'];

        $now = time();
        $accountId = Utils::generateUniqueId();

        $this->db->beginTransaction();
        try {
            $insertSql = "INSERT INTO account (account_id, uid, deleted, category, type, parent_account_id, name, display_order, icon, icon_type, color, currency, balance, comment, hidden, created_unix_time, updated_unix_time, deleted_unix_time) 
                          VALUES (:account_id, :uid, 0, :category, :type, 0, :name, :display_order, :icon, :icon_type, :color, :currency, :balance, :comment, 0, :created_unix_time, :updated_unix_time, 0)";

            $stmt = $this->db->prepare($insertSql);
            $stmt->execute([
                ':account_id' => $accountId,
                ':uid' => $uid,
                ':category' => $category,
                ':type' => $type,
                ':name' => $name,
                ':display_order' => $displayOrder,
                ':icon' => $icon,
                ':icon_type' => $iconType,
                ':color' => $color,
                ':currency' => $currency,
                ':balance' => $balance,
                ':comment' => $comment,
                ':created_unix_time' => $now,
                ':updated_unix_time' => $now,
            ]);

            // Handle sub-accounts if present
            $createdSubAccounts = [];
            if (!empty($input['subAccounts']) && is_array($input['subAccounts'])) {
                $subOrder = 1;
                foreach ($input['subAccounts'] as $sub) {
                    $subId = Utils::generateUniqueId();
                    $subName = trim($sub['name'] ?? '');
                    $subBalance = (int)($sub['balance'] ?? 0);
                    $subCurrency = trim($sub['currency'] ?? $currency);
                    $subColor = trim($sub['color'] ?? $color);
                    $subIcon = (int)($sub['icon'] ?? $icon);
                    $subComment = trim($sub['comment'] ?? '');

                    $subStmt = $this->db->prepare("INSERT INTO account (account_id, uid, deleted, category, type, parent_account_id, name, display_order, icon, icon_type, color, currency, balance, comment, hidden, created_unix_time, updated_unix_time, deleted_unix_time) 
                                                   VALUES (:account_id, :uid, 0, :category, 1, :parent_id, :name, :display_order, :icon, :icon_type, :color, :currency, :balance, :comment, 0, :created_unix_time, :updated_unix_time, 0)");
                    $subStmt->execute([
                        ':account_id' => $subId,
                        ':uid' => $uid,
                        ':category' => $category,
                        ':parent_id' => $accountId,
                        ':name' => $subName,
                        ':display_order' => $subOrder++,
                        ':icon' => $subIcon,
                        ':icon_type' => $iconType,
                        ':color' => $subColor,
                        ':currency' => $subCurrency,
                        ':balance' => $subBalance,
                        ':comment' => $subComment,
                        ':created_unix_time' => $now,
                        ':updated_unix_time' => $now,
                    ]);

                    $createdSubAccounts[] = [
                        'id' => (string)$subId,
                        'name' => $subName,
                        'parentId' => (string)$accountId,
                        'category' => $category,
                        'type' => 1,
                        'icon' => (string)$subIcon,
                        'iconType' => $iconType,
                        'color' => $subColor,
                        'currency' => $subCurrency,
                        'balance' => (string)$subBalance,
                        'comment' => $subComment,
                        'displayOrder' => $subOrder - 1,
                        'isAsset' => in_array($category, [1, 2, 4, 6, 7, 8, 9]),
                        'isLiability' => in_array($category, [3, 5]),
                        'hidden' => false,
                        'subAccounts' => [],
                    ];
                }
            }

            $this->db->commit();

            $result = [
                'id' => (string)$accountId,
                'name' => $name,
                'parentId' => '0',
                'category' => $category,
                'type' => $type,
                'icon' => (string)$icon,
                'iconType' => $iconType,
                'color' => $color,
                'currency' => $currency,
                'balance' => (string)$balance,
                'comment' => $comment,
                'displayOrder' => $displayOrder,
                'isAsset' => in_array($category, [1, 2, 4, 6, 7, 8, 9]),
                'isLiability' => in_array($category, [3, 5]),
                'hidden' => false,
                'subAccounts' => $createdSubAccounts,
            ];

            Utils::successResponse($result);

        } catch (Throwable $e) {
            $this->db->rollBack();
            Utils::errorResponse(200004, 'Failed to create account: ' . $e->getMessage());
        }
    }

    /**
     * Modifies an existing account.
     */
    public function modifyAccount(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;
        if (!$id) {
            Utils::errorResponse(200005, 'Account ID is required');
            return;
        }

        $name = trim($input['name'] ?? '');
        $category = (int)($input['category'] ?? 1);
        $icon = (int)($input['icon'] ?? 1);
        $iconType = (int)($input['iconType'] ?? 0);
        $color = trim($input['color'] ?? '000000');
        $comment = trim($input['comment'] ?? '');
        $hidden = !empty($input['hidden']) ? 1 : 0;
        $now = time();

        $sql = "UPDATE account SET name = :name, category = :category, icon = :icon, icon_type = :icon_type, color = :color, comment = :comment, hidden = :hidden, updated_unix_time = :updated_unix_time 
                WHERE account_id = :id AND uid = :uid AND deleted = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':category' => $category,
            ':icon' => $icon,
            ':icon_type' => $iconType,
            ':color' => $color,
            ':comment' => $comment,
            ':hidden' => $hidden,
            ':updated_unix_time' => $now,
            ':id' => $id,
            ':uid' => $uid,
        ]);

        // Re-read modified account
        $_GET['id'] = $id;
        $this->getAccount();
    }

    /**
     * Toggles visibility (hide/unhide) of an account.
     */
    public function hideAccount(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;
        $hidden = !empty($input['hidden']) ? 1 : 0;

        if (!$id) {
            Utils::errorResponse(200006, 'Account ID is required');
            return;
        }

        $stmt = $this->db->prepare("UPDATE account SET hidden = :hidden, updated_unix_time = :now WHERE account_id = :id AND uid = :uid AND deleted = 0");
        $stmt->execute([
            ':hidden' => $hidden,
            ':now' => time(),
            ':id' => $id,
            ':uid' => $uid,
        ]);

        Utils::successResponse(true);
    }

    /**
     * Reorders accounts according to user-customized display sequence.
     */
    public function moveAccount(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $newOrders = $input['newDisplayOrders'] ?? [];

        if (!is_array($newOrders) || empty($newOrders)) {
            Utils::errorResponse(200007, 'No display orders provided');
            return;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE account SET display_order = :order, updated_unix_time = :now WHERE account_id = :id AND uid = :uid");
            $now = time();
            foreach ($newOrders as $item) {
                if (isset($item['id'], $item['displayOrder'])) {
                    $stmt->execute([
                        ':order' => (int)$item['displayOrder'],
                        ':now' => $now,
                        ':id' => $item['id'],
                        ':uid' => $uid,
                    ]);
                }
            }
            $this->db->commit();
            Utils::successResponse(true);
        } catch (Throwable $e) {
            $this->db->rollBack();
            Utils::errorResponse(200008, 'Failed to update account orders: ' . $e->getMessage());
        }
    }

    /**
     * Soft deletes an account and its sub-accounts.
     */
    public function deleteAccount(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;

        if (!$id) {
            Utils::errorResponse(200009, 'Account ID is required');
            return;
        }

        $now = time();
        $this->db->beginTransaction();
        try {
            // Delete parent account
            $stmt = $this->db->prepare("UPDATE account SET deleted = 1, deleted_unix_time = :now WHERE account_id = :id AND uid = :uid");
            $stmt->execute([':now' => $now, ':id' => $id, ':uid' => $uid]);

            // Delete sub-accounts
            $subStmt = $this->db->prepare("UPDATE account SET deleted = 1, deleted_unix_time = :now WHERE parent_account_id = :id AND uid = :uid");
            $subStmt->execute([':now' => $now, ':id' => $id, ':uid' => $uid]);

            $this->db->commit();
            Utils::successResponse(true);
        } catch (Throwable $e) {
            $this->db->rollBack();
            Utils::errorResponse(200010, 'Failed to delete account: ' . $e->getMessage());
        }
    }

    /**
     * Formats database account row into frontend JSON contract.
     */
    private function formatAccount(array $row): array {
        $category = (int)$row['category'];
        $isAsset = in_array($category, [1, 2, 4, 6, 7, 8, 9]);
        $isLiability = in_array($category, [3, 5]);

        return [
            'id' => (string)$row['account_id'],
            'name' => (string)$row['name'],
            'parentId' => (string)$row['parent_account_id'],
            'category' => $category,
            'type' => (int)$row['type'],
            'icon' => (string)$row['icon'],
            'iconType' => (int)($row['icon_type'] ?? 0),
            'color' => (string)$row['color'],
            'currency' => (string)$row['currency'],
            'balance' => (string)$row['balance'],
            'comment' => (string)($row['comment'] ?? ''),
            'displayOrder' => (int)$row['display_order'],
            'isAsset' => $isAsset,
            'isLiability' => $isLiability,
            'hidden' => (bool)$row['hidden'],
            'subAccounts' => [],
        ];
    }
}
