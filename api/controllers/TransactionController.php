<?php
/**
 * Axonite Expense Tracking System - Transaction Controller
 * 
 * Handles all financial ledger entries: Income, Expense, Transfer, and Balance Adjustments.
 * Includes complete support for Custom Payee Names, Multi-Currency (INR/USD),
 * Tag bindings, and Receipt Picture linkages.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

class TransactionController {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Lists transactions with filtering by date range, account, category, type, and keyword.
     */
    public function listTransactions(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $type = isset($_GET['type']) ? (int)$_GET['type'] : 0;
        $categoryIds = !empty($_GET['category_ids']) ? explode(',', $_GET['category_ids']) : [];
        $accountIds = !empty($_GET['account_ids']) ? explode(',', $_GET['account_ids']) : [];
        $keyword = trim($_GET['keyword'] ?? '');
        $maxTime = isset($_GET['max_time']) ? (int)$_GET['max_time'] : 0;
        $minTime = isset($_GET['min_time']) ? (int)$_GET['min_time'] : 0;
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $count = isset($_GET['count']) ? min(100, max(1, (int)$_GET['count'])) : 20;
        $offset = ($page - 1) * $count;

        $where = ["t.uid = :uid", "t.deleted = 0"];
        $params = [':uid' => $uid];

        // Type filter: In database: 1=ModifyBalance, 2=Income, 3=Expense, 4=TransferOut, 5=TransferIn
        if ($type > 0) {
            if ($type === 4) {
                // Transfer: Show transfer_out records to avoid duplicate line display
                $where[] = "t.type = 4";
            } else {
                $where[] = "t.type = :type";
                $params[':type'] = $type;
            }
        }

        // Account filter
        if (!empty($accountIds)) {
            $srcPlaceholders = [];
            $relPlaceholders = [];
            foreach ($accountIds as $idx => $accId) {
                $p1 = ":acc_src_$idx";
                $p2 = ":acc_rel_$idx";
                $srcPlaceholders[] = $p1;
                $relPlaceholders[] = $p2;
                $params[$p1] = $accId;
                $params[$p2] = $accId;
            }
            $where[] = "(t.account_id IN (" . implode(',', $srcPlaceholders) . ") OR t.related_account_id IN (" . implode(',', $relPlaceholders) . "))";
        }

        // Category filter
        if (!empty($categoryIds)) {
            $catPlaceholders = [];
            foreach ($categoryIds as $idx => $catId) {
                $p = ":cat_$idx";
                $catPlaceholders[] = $p;
                $params[$p] = $catId;
            }
            $where[] = "t.category_id IN (" . implode(',', $catPlaceholders) . ")";
        }

        // Keyword filter
        if ($keyword !== '') {
            $where[] = "(t.comment LIKE :kw OR t.payee_name LIKE :kw)";
            $params[':kw'] = '%' . $keyword . '%';
        }

        // Time range filter (transaction_time is milliseconds)
        if ($maxTime > 0) {
            $where[] = "t.transaction_time <= :max_time";
            $params[':max_time'] = $maxTime;
        }
        if ($minTime > 0) {
            $where[] = "t.transaction_time >= :min_time";
            $params[':min_time'] = $minTime;
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) AS total FROM transaction t WHERE $whereClause");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetch()['total'];

        // Get items with account and category joins
        $sql = "SELECT t.*, 
                       c.name AS category_name, c.icon AS category_icon, c.color AS category_color, c.type AS category_type,
                       a.name AS account_name, a.currency AS account_currency, a.color AS account_color, a.icon AS account_icon, a.category AS account_category,
                       ra.name AS related_account_name, ra.currency AS related_account_currency
                FROM transaction t
                LEFT JOIN transaction_category c ON t.category_id = c.category_id AND c.deleted = 0
                LEFT JOIN account a ON t.account_id = a.account_id AND a.deleted = 0
                LEFT JOIN account ra ON t.related_account_id = ra.account_id AND ra.deleted = 0
                WHERE $whereClause
                ORDER BY t.transaction_time DESC, t.transaction_id DESC
                LIMIT $count OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->formatTransactionRow($row, $uid);
        }

        Utils::successResponse([
            'items' => $items,
            'totalCount' => $totalCount,
        ]);
    }

    /**
     * Counts transactions matching query criteria.
     */
    public function countTransactions(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $type = isset($_GET['type']) ? (int)$_GET['type'] : 0;
        $where = ["uid = :uid", "deleted = 0"];
        $params = [':uid' => $uid];

        if ($type > 0) {
            if ($type === 4) {
                $where[] = "type = 4";
            } else {
                $where[] = "type = :type";
                $params[':type'] = $type;
            }
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM transaction WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        $totalCount = (int)$stmt->fetch()['total'];

        Utils::successResponse(['totalCount' => $totalCount]);
    }

    /**
     * Retrieves a single transaction by ID.
     */
    public function getTransaction(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $id = $_GET['id'] ?? null;
        if (!$id) {
            Utils::errorResponse(203001, 'Transaction ID is required');
            return;
        }

        $sql = "SELECT t.*, 
                       c.name AS category_name, c.icon AS category_icon, c.color AS category_color, c.type AS category_type,
                       a.name AS account_name, a.currency AS account_currency, a.color AS account_color, a.icon AS account_icon, a.category AS account_category,
                       ra.name AS related_account_name, ra.currency AS related_account_currency
                FROM transaction t
                LEFT JOIN transaction_category c ON t.category_id = c.category_id AND c.deleted = 0
                LEFT JOIN account a ON t.account_id = a.account_id AND a.deleted = 0
                LEFT JOIN account ra ON t.related_account_id = ra.account_id AND ra.deleted = 0
                WHERE t.transaction_id = :id AND t.uid = :uid AND t.deleted = 0
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        $row = $stmt->fetch();

        if (!$row) {
            Utils::errorResponse(203002, 'Transaction not found', 404);
            return;
        }

        Utils::successResponse($this->formatTransactionRow($row, $uid));
    }

    /**
     * Creates a new transaction with ledger balance updates, tags, and receipt linkages.
     */
    public function addTransaction(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $type = (int)($input['type'] ?? 3); // 2=Income, 3=Expense, 4=Transfer, 1=ModifyBalance
        $categoryId = (int)($input['categoryId'] ?? 0);
        $sourceAccountId = (int)($input['sourceAccountId'] ?? 0);
        $destinationAccountId = (int)($input['destinationAccountId'] ?? 0);
        $sourceAmount = (int)($input['sourceAmount'] ?? 0);
        $destinationAmount = (int)($input['destinationAmount'] ?? $sourceAmount);
        $time = (int)($input['time'] ?? time());
        $utcOffset = (int)($input['utcOffset'] ?? 330);
        $comment = trim($input['comment'] ?? '');
        $payeeName = trim($input['payeeName'] ?? '');
        $currency = trim($input['currency'] ?? ($currentUser['default_currency'] ?? 'INR'));
        $hideAmount = !empty($input['hideAmount']) ? 1 : 0;
        $tagIds = !empty($input['tagIds']) && is_array($input['tagIds']) ? $input['tagIds'] : [];
        $pictureIds = !empty($input['pictureIds']) && is_array($input['pictureIds']) ? $input['pictureIds'] : [];

        if ($sourceAccountId <= 0) {
            Utils::errorResponse(203003, 'Source account is required');
            return;
        }

        // Database transaction time is unix_seconds * 1000
        $dbTransactionTime = $time * 1000;
        $now = time();
        $transactionId = Utils::generateUniqueId();
        $relatedId = 0;

        // Map input type to DB type
        $dbType = $type;
        if ($type === 4) {
            $dbType = 4; // Transfer Out
            $relatedId = Utils::generateUniqueId();
        }

        $this->db->beginTransaction();
        try {
            // 1. Insert main transaction record
            $sql = "INSERT INTO transaction (transaction_id, uid, deleted, type, category_id, account_id, transaction_time, timezone_utc_offset, amount, related_id, related_account_id, related_account_amount, hide_amount, comment, payee_name, currency, geo_longitude, geo_latitude, created_ip, created_unix_time, updated_unix_time, deleted_unix_time)
                    VALUES (:tid, :uid, 0, :type, :cat_id, :acc_id, :tx_time, :tz_offset, :amount, :rel_id, :rel_acc_id, :rel_acc_amount, :hide_amt, :comment, :payee_name, :currency, 0, 0, :ip, :created_unix_time, :updated_unix_time, 0)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':tid' => $transactionId,
                ':uid' => $uid,
                ':type' => $dbType,
                ':cat_id' => $categoryId,
                ':acc_id' => $sourceAccountId,
                ':tx_time' => $dbTransactionTime,
                ':tz_offset' => $utcOffset,
                ':amount' => $sourceAmount,
                ':rel_id' => $relatedId,
                ':rel_acc_id' => $destinationAccountId,
                ':rel_acc_amount' => $destinationAmount,
                ':hide_amt' => $hideAmount,
                ':comment' => $comment,
                ':payee_name' => $payeeName,
                ':currency' => $currency,
                ':ip' => Utils::getClientIp(),
                ':created_unix_time' => $now,
                ':updated_unix_time' => $now,
            ]);

            // 2. If transfer, also create corresponding Transfer In record
            if ($type === 4 && $destinationAccountId > 0) {
                $inStmt = $this->db->prepare($sql);
                $inStmt->execute([
                    ':tid' => $relatedId,
                    ':uid' => $uid,
                    ':type' => 5, // Transfer In
                    ':cat_id' => $categoryId,
                    ':acc_id' => $destinationAccountId,
                    ':tx_time' => $dbTransactionTime + 1,
                    ':tz_offset' => $utcOffset,
                    ':amount' => $destinationAmount,
                    ':rel_id' => $transactionId,
                    ':rel_acc_id' => $sourceAccountId,
                    ':rel_acc_amount' => $sourceAmount,
                    ':hide_amt' => $hideAmount,
                    ':comment' => $comment,
                    ':payee_name' => $payeeName,
                    ':currency' => $currency,
                    ':ip' => Utils::getClientIp(),
                    ':created_unix_time' => $now,
                    ':updated_unix_time' => $now,
                ]);

                // Update balances for both accounts
                $this->db->prepare("UPDATE account SET balance = balance - :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $sourceAmount, ':now' => $now, ':aid' => $sourceAccountId, ':uid' => $uid]);
                $this->db->prepare("UPDATE account SET balance = balance + :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $destinationAmount, ':now' => $now, ':aid' => $destinationAccountId, ':uid' => $uid]);

            } elseif ($type === 2) {
                // Income: increase account balance
                $this->db->prepare("UPDATE account SET balance = balance + :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $sourceAmount, ':now' => $now, ':aid' => $sourceAccountId, ':uid' => $uid]);

            } elseif ($type === 3) {
                // Expense: decrease account balance
                $this->db->prepare("UPDATE account SET balance = balance - :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $sourceAmount, ':now' => $now, ':aid' => $sourceAccountId, ':uid' => $uid]);

            } elseif ($type === 1) {
                // Modify Balance: directly set new balance
                $this->db->prepare("UPDATE account SET balance = :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $sourceAmount, ':now' => $now, ':aid' => $sourceAccountId, ':uid' => $uid]);
            }

            // 3. Attach tags
            if (!empty($tagIds)) {
                $tagStmt = $this->db->prepare("INSERT INTO transaction_tag_index (tag_index_id, uid, deleted, transaction_time, tag_id, transaction_id, created_unix_time, updated_unix_time, deleted_unix_time) 
                                               VALUES (:tiid, :uid, 0, :tx_time, :tid, :txid, :created_unix_time, :updated_unix_time, 0)");
                foreach ($tagIds as $tId) {
                    $tagStmt->execute([
                        ':tiid' => Utils::generateUniqueId(),
                        ':uid' => $uid,
                        ':tx_time' => $dbTransactionTime,
                        ':tid' => (int)$tId,
                        ':txid' => $transactionId,
                        ':created_unix_time' => $now,
                        ':updated_unix_time' => $now,
                    ]);
                }
            }

            // 4. Attach uploaded pictures / receipt proofs
            if (!empty($pictureIds)) {
                $picStmt = $this->db->prepare("UPDATE transaction_picture_info SET transaction_id = :txid, updated_unix_time = :now WHERE picture_id = :pid AND uid = :uid");
                foreach ($pictureIds as $pId) {
                    $picStmt->execute([
                        ':txid' => $transactionId,
                        ':now' => $now,
                        ':pid' => (int)$pId,
                        ':uid' => $uid,
                    ]);
                }
            }

            $this->db->commit();

            // Return full formatted transaction
            $_GET['id'] = $transactionId;
            $this->getTransaction();

        } catch (Throwable $e) {
            $this->db->rollBack();
            Utils::errorResponse(203004, 'Failed to create transaction: ' . $e->getMessage());
        }
    }

    /**
     * Modifies an existing financial transaction, adjusting account balances and tag/picture bindings.
     */
    public function modifyTransaction(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;

        if (!$id) {
            Utils::errorResponse(203005, 'Transaction ID is required');
            return;
        }

        // Fetch existing record
        $stmt = $this->db->prepare("SELECT * FROM transaction WHERE transaction_id = :id AND uid = :uid AND deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        $oldTx = $stmt->fetch();

        if (!$oldTx) {
            Utils::errorResponse(203006, 'Transaction not found', 404);
            return;
        }

        $type = (int)($input['type'] ?? $oldTx['type']);
        $categoryId = (int)($input['categoryId'] ?? $oldTx['category_id']);
        $sourceAccountId = (int)($input['sourceAccountId'] ?? $oldTx['account_id']);
        $destinationAccountId = (int)($input['destinationAccountId'] ?? ($oldTx['related_account_id'] ?? 0));
        $sourceAmount = (int)($input['sourceAmount'] ?? $oldTx['amount']);
        $destinationAmount = (int)($input['destinationAmount'] ?? ($destinationAccountId > 0 ? $sourceAmount : 0));
        $time = (int)($input['time'] ?? floor(((int)$oldTx['transaction_time']) / 1000));
        $utcOffset = (int)($input['utcOffset'] ?? $oldTx['timezone_utc_offset']);
        $comment = isset($input['comment']) ? trim($input['comment']) : (string)$oldTx['comment'];
        $payeeName = isset($input['payeeName']) ? trim($input['payeeName']) : (string)($oldTx['payee_name'] ?? '');
        $currency = isset($input['currency']) ? trim($input['currency']) : (string)($oldTx['currency'] ?? 'INR');
        $hideAmount = !empty($input['hideAmount']) ? 1 : 0;
        $tagIds = isset($input['tagIds']) && is_array($input['tagIds']) ? $input['tagIds'] : null;
        $pictureIds = isset($input['pictureIds']) && is_array($input['pictureIds']) ? $input['pictureIds'] : null;

        if ($sourceAccountId <= 0) {
            Utils::errorResponse(203003, 'Source account is required');
            return;
        }

        $dbTransactionTime = $time * 1000;
        $now = time();

        // Map frontend type to DB type
        $dbType = $type;
        $relatedId = (int)($oldTx['related_id'] ?? 0);
        if ($type === 4) {
            $dbType = 4; // Transfer Out
            if ($relatedId <= 0) {
                $relatedId = Utils::generateUniqueId();
            }
        }

        $this->db->beginTransaction();
        try {
            // 1. Revert old account balance effects
            $oldType = (int)$oldTx['type'];
            $oldAmount = (int)$oldTx['amount'];
            $oldAccId = (int)$oldTx['account_id'];

            if ($oldType === 2) {
                // Was income: subtract old income
                $this->db->prepare("UPDATE account SET balance = balance - :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $oldAmount, ':now' => $now, ':aid' => $oldAccId, ':uid' => $uid]);
            } elseif ($oldType === 3) {
                // Was expense: refund old expense
                $this->db->prepare("UPDATE account SET balance = balance + :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $oldAmount, ':now' => $now, ':aid' => $oldAccId, ':uid' => $uid]);
            } elseif ($oldType === 4 && (int)$oldTx['related_id'] > 0) {
                // Was transfer: refund source, subtract from destination
                $this->db->prepare("UPDATE account SET balance = balance + :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $oldAmount, ':now' => $now, ':aid' => $oldAccId, ':uid' => $uid]);
                $oldDestAccId = (int)$oldTx['related_account_id'];
                $oldDestAmount = (int)$oldTx['related_account_amount'];
                $this->db->prepare("UPDATE account SET balance = balance - :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $oldDestAmount, ':now' => $now, ':aid' => $oldDestAccId, ':uid' => $uid]);
            }

            // 2. Update main transaction record
            $updateSql = "UPDATE transaction SET 
                            type = :type, 
                            category_id = :cat_id, 
                            account_id = :acc_id, 
                            transaction_time = :tx_time, 
                            timezone_utc_offset = :tz_offset, 
                            amount = :amount, 
                            related_id = :rel_id, 
                            related_account_id = :rel_acc_id, 
                            related_account_amount = :rel_acc_amount, 
                            hide_amount = :hide_amt, 
                            comment = :comment, 
                            payee_name = :payee_name, 
                            currency = :currency, 
                            updated_unix_time = :now 
                          WHERE transaction_id = :id AND uid = :uid";

            $stmt = $this->db->prepare($updateSql);
            $stmt->execute([
                ':id' => $id,
                ':uid' => $uid,
                ':type' => $dbType,
                ':cat_id' => $categoryId,
                ':acc_id' => $sourceAccountId,
                ':tx_time' => $dbTransactionTime,
                ':tz_offset' => $utcOffset,
                ':amount' => $sourceAmount,
                ':rel_id' => ($dbType === 4 ? $relatedId : 0),
                ':rel_acc_id' => ($dbType === 4 ? $destinationAccountId : 0),
                ':rel_acc_amount' => ($dbType === 4 ? $destinationAmount : 0),
                ':hide_amt' => $hideAmount,
                ':comment' => $comment,
                ':payee_name' => $payeeName,
                ':currency' => $currency,
                ':now' => $now,
            ]);

            // 3. Handle Transfer In counterpart
            if ($type === 4 && $destinationAccountId > 0) {
                // Check if old Transfer In record existed
                $inCheck = $this->db->prepare("SELECT transaction_id FROM transaction WHERE transaction_id = :rid AND uid = :uid LIMIT 1");
                $inCheck->execute([':rid' => $relatedId, ':uid' => $uid]);
                if ($inCheck->fetch()) {
                    $inUpdate = $this->db->prepare("UPDATE transaction SET 
                                                    type = 5, 
                                                    category_id = :cat_id, 
                                                    account_id = :acc_id, 
                                                    transaction_time = :tx_time, 
                                                    timezone_utc_offset = :tz_offset, 
                                                    amount = :amount, 
                                                    related_id = :rel_id, 
                                                    related_account_id = :rel_acc_id, 
                                                    related_account_amount = :rel_acc_amount, 
                                                    hide_amount = :hide_amt, 
                                                    comment = :comment, 
                                                    payee_name = :payee_name, 
                                                    currency = :currency, 
                                                    deleted = 0, 
                                                    updated_unix_time = :now 
                                                    WHERE transaction_id = :rid AND uid = :uid");
                    $inUpdate->execute([
                        ':rid' => $relatedId,
                        ':uid' => $uid,
                        ':cat_id' => $categoryId,
                        ':acc_id' => $destinationAccountId,
                        ':tx_time' => $dbTransactionTime + 1,
                        ':tz_offset' => $utcOffset,
                        ':amount' => $destinationAmount,
                        ':rel_id' => $id,
                        ':rel_acc_id' => $sourceAccountId,
                        ':rel_acc_amount' => $sourceAmount,
                        ':hide_amt' => $hideAmount,
                        ':comment' => $comment,
                        ':payee_name' => $payeeName,
                        ':currency' => $currency,
                        ':now' => $now,
                    ]);
                } else {
                    $inInsert = "INSERT INTO transaction (transaction_id, uid, deleted, type, category_id, account_id, transaction_time, timezone_utc_offset, amount, related_id, related_account_id, related_account_amount, hide_amount, comment, payee_name, currency, geo_longitude, geo_latitude, created_ip, created_unix_time, updated_unix_time, deleted_unix_time)
                                VALUES (:tid, :uid, 0, 5, :cat_id, :acc_id, :tx_time, :tz_offset, :amount, :rel_id, :rel_acc_id, :rel_acc_amount, :hide_amt, :comment, :payee_name, :currency, 0, 0, :ip, :now, :now, 0)";
                    $this->db->prepare($inInsert)->execute([
                        ':tid' => $relatedId,
                        ':uid' => $uid,
                        ':cat_id' => $categoryId,
                        ':acc_id' => $destinationAccountId,
                        ':tx_time' => $dbTransactionTime + 1,
                        ':tz_offset' => $utcOffset,
                        ':amount' => $destinationAmount,
                        ':rel_id' => $id,
                        ':rel_acc_id' => $sourceAccountId,
                        ':rel_acc_amount' => $sourceAmount,
                        ':hide_amt' => $hideAmount,
                        ':comment' => $comment,
                        ':payee_name' => $payeeName,
                        ':currency' => $currency,
                        ':ip' => Utils::getClientIp(),
                        ':now' => $now,
                    ]);
                }

                // Apply new balance for transfer
                $this->db->prepare("UPDATE account SET balance = balance - :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $sourceAmount, ':now' => $now, ':aid' => $sourceAccountId, ':uid' => $uid]);
                $this->db->prepare("UPDATE account SET balance = balance + :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $destinationAmount, ':now' => $now, ':aid' => $destinationAccountId, ':uid' => $uid]);

            } else {
                // If it was previously a transfer and now changed to non-transfer, delete old Transfer In
                if ($oldType === 4 && (int)$oldTx['related_id'] > 0) {
                    $this->db->prepare("UPDATE transaction SET deleted = 1, deleted_unix_time = :now WHERE transaction_id = :rid AND uid = :uid")
                             ->execute([':now' => $now, ':rid' => (int)$oldTx['related_id'], ':uid' => $uid]);
                }

                // Apply new balances
                if ($type === 2) {
                    // Income
                    $this->db->prepare("UPDATE account SET balance = balance + :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                             ->execute([':amt' => $sourceAmount, ':now' => $now, ':aid' => $sourceAccountId, ':uid' => $uid]);
                } elseif ($type === 3) {
                    // Expense
                    $this->db->prepare("UPDATE account SET balance = balance - :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                             ->execute([':amt' => $sourceAmount, ':now' => $now, ':aid' => $sourceAccountId, ':uid' => $uid]);
                } elseif ($type === 1) {
                    // Modify Balance
                    $this->db->prepare("UPDATE account SET balance = :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                             ->execute([':amt' => $sourceAmount, ':now' => $now, ':aid' => $sourceAccountId, ':uid' => $uid]);
                }
            }

            // 4. Update tags if provided
            if ($tagIds !== null) {
                // Unlink old tags
                $this->db->prepare("UPDATE transaction_tag_index SET deleted = 1, deleted_unix_time = :now WHERE transaction_id = :txid AND uid = :uid AND deleted = 0")
                         ->execute([':now' => $now, ':txid' => $id, ':uid' => $uid]);

                if (!empty($tagIds)) {
                    $tagStmt = $this->db->prepare("INSERT INTO transaction_tag_index (tag_index_id, uid, deleted, transaction_time, tag_id, transaction_id, created_unix_time, updated_unix_time, deleted_unix_time) 
                                                   VALUES (:tiid, :uid, 0, :tx_time, :tid, :txid, :created_unix_time, :updated_unix_time, 0)");
                    foreach ($tagIds as $tId) {
                        $tagStmt->execute([
                            ':tiid' => Utils::generateUniqueId(),
                            ':uid' => $uid,
                            ':tx_time' => $dbTransactionTime,
                            ':tid' => (int)$tId,
                            ':txid' => $id,
                            ':created_unix_time' => $now,
                            ':updated_unix_time' => $now,
                        ]);
                    }
                }
            }

            // 5. Update attached receipt pictures if provided
            if ($pictureIds !== null) {
                // Unlink any pictures previously tied to this transaction
                $this->db->prepare("UPDATE transaction_picture_info SET transaction_id = 0, updated_unix_time = :now WHERE transaction_id = :txid AND uid = :uid")
                         ->execute([':now' => $now, ':txid' => $id, ':uid' => $uid]);

                if (!empty($pictureIds)) {
                    $picStmt = $this->db->prepare("UPDATE transaction_picture_info SET transaction_id = :txid, deleted = 0, updated_unix_time = :now WHERE picture_id = :pid AND uid = :uid");
                    foreach ($pictureIds as $pId) {
                        $picStmt->execute([
                            ':txid' => $id,
                            ':now' => $now,
                            ':pid' => (int)$pId,
                            ':uid' => $uid,
                        ]);
                    }
                }
            }

            $this->db->commit();

            // Return full formatted transaction
            $_GET['id'] = $id;
            $this->getTransaction();

        } catch (Throwable $e) {
            $this->db->rollBack();
            Utils::errorResponse(203008, 'Failed to modify transaction: ' . $e->getMessage());
        }
    }

    /**
     * Soft deletes a transaction and rolls back its account balance effect.
     */
    public function deleteTransaction(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;

        if (!$id) {
            Utils::errorResponse(203005, 'Transaction ID is required');
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM transaction WHERE transaction_id = :id AND uid = :uid AND deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        $tx = $stmt->fetch();

        if (!$tx) {
            Utils::errorResponse(203006, 'Transaction not found', 404);
            return;
        }

        $now = time();
        $this->db->beginTransaction();
        try {
            // 1. Soft delete transaction
            $delStmt = $this->db->prepare("UPDATE transaction SET deleted = 1, deleted_unix_time = :now WHERE transaction_id = :id AND uid = :uid");
            $delStmt->execute([':now' => $now, ':id' => $id, ':uid' => $uid]);

            // 2. Revert account balance effect
            $dbType = (int)$tx['type'];
            $amount = (int)$tx['amount'];
            $accId = (int)$tx['account_id'];

            if ($dbType === 2) {
                // Income deleted: subtract from account
                $this->db->prepare("UPDATE account SET balance = balance - :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $amount, ':now' => $now, ':aid' => $accId, ':uid' => $uid]);
            } elseif ($dbType === 3) {
                // Expense deleted: refund to account
                $this->db->prepare("UPDATE account SET balance = balance + :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $amount, ':now' => $now, ':aid' => $accId, ':uid' => $uid]);
            } elseif ($dbType === 4 && (int)$tx['related_id'] > 0) {
                // Transfer out deleted: revert source, and delete matching Transfer In record
                $this->db->prepare("UPDATE account SET balance = balance + :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $amount, ':now' => $now, ':aid' => $accId, ':uid' => $uid]);

                $relId = (int)$tx['related_id'];
                $this->db->prepare("UPDATE transaction SET deleted = 1, deleted_unix_time = :now WHERE transaction_id = :rid AND uid = :uid")
                         ->execute([':now' => $now, ':rid' => $relId, ':uid' => $uid]);

                $destAccId = (int)$tx['related_account_id'];
                $destAmt = (int)$tx['related_account_amount'];
                $this->db->prepare("UPDATE account SET balance = balance - :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $destAmt, ':now' => $now, ':aid' => $destAccId, ':uid' => $uid]);
            } elseif ($dbType === 5 && (int)$tx['related_id'] > 0) {
                // Transfer in deleted: revert destination, and delete matching Transfer Out record
                $this->db->prepare("UPDATE account SET balance = balance - :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $amount, ':now' => $now, ':aid' => $accId, ':uid' => $uid]);

                $relId = (int)$tx['related_id'];
                $this->db->prepare("UPDATE transaction SET deleted = 1, deleted_unix_time = :now WHERE transaction_id = :rid AND uid = :uid")
                         ->execute([':now' => $now, ':rid' => $relId, ':uid' => $uid]);

                $srcAccId = (int)$tx['related_account_id'];
                $srcAmt = (int)$tx['related_account_amount'];
                $this->db->prepare("UPDATE account SET balance = balance + :amt, updated_unix_time = :now WHERE account_id = :aid AND uid = :uid")
                         ->execute([':amt' => $srcAmt, ':now' => $now, ':aid' => $srcAccId, ':uid' => $uid]);
            }

            // 3. Soft-delete tag associations
            $this->db->prepare("UPDATE transaction_tag_index SET deleted = 1, deleted_unix_time = :now WHERE transaction_id = :id AND uid = :uid AND deleted = 0")
                     ->execute([':now' => $now, ':id' => $id, ':uid' => $uid]);

            // 4. Soft-delete attached pictures
            $this->db->prepare("UPDATE transaction_picture_info SET deleted = 1, deleted_unix_time = :now WHERE transaction_id = :id AND uid = :uid AND deleted = 0")
                     ->execute([':now' => $now, ':id' => $id, ':uid' => $uid]);

            $this->db->commit();
            Utils::successResponse(true);

        } catch (Throwable $e) {
            $this->db->rollBack();
            Utils::errorResponse(203007, 'Failed to delete transaction: ' . $e->getMessage());
        }
    }

    /**
     * Lists transactions for a specific month.
     */
    public function transactionMonthList(): void {
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = (int)($_GET['month'] ?? date('n'));

        $startMin = strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $endMax = strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $month) . ' +1 month -1 second');

        $_GET['min_time'] = $startMin * 1000;
        $_GET['max_time'] = $endMax * 1000 + 999;
        $_GET['count'] = 1000;

        $this->listTransactions();
    }

    /**
     * Helper to format a database row into the exact JSON schema required by Vue 3 frontend.
     */
    private function formatTransactionRow(array $row, int $uid): array {
        $txId = (string)$row['transaction_id'];
        $dbType = (int)$row['type'];

        // Map DB type (1,2,3,4,5) to frontend type (1,2,3,4)
        $frontendType = $dbType;
        if ($dbType === 4 || $dbType === 5) {
            $frontendType = 4; // Transfer
        }

        // Fetch attached tags
        $tagStmt = $this->db->prepare("SELECT t.tag_id, t.name, t.tag_group_id, t.display_order, t.hidden 
                                       FROM transaction_tag_index ti 
                                       JOIN transaction_tag t ON ti.tag_id = t.tag_id 
                                       WHERE ti.transaction_id = :txid AND ti.deleted = 0 AND t.deleted = 0");
        $tagStmt->execute([':txid' => $row['transaction_id']]);
        $tagRows = $tagStmt->fetchAll();

        $tagIds = [];
        $tags = [];
        foreach ($tagRows as $tr) {
            $tagIds[] = (string)$tr['tag_id'];
            $tags[] = [
                'id' => (string)$tr['tag_id'],
                'name' => (string)$tr['name'],
                'groupId' => (string)$tr['tag_group_id'],
                'displayOrder' => (int)$tr['display_order'],
                'hidden' => (bool)$tr['hidden'],
            ];
        }

        // Fetch attached receipt pictures
        $picStmt = $this->db->prepare("SELECT picture_id, picture_extension FROM transaction_picture_info WHERE transaction_id = :txid AND deleted = 0");
        $picStmt->execute([':txid' => $row['transaction_id']]);
        $picRows = $picStmt->fetchAll();

        $pictures = [];
        foreach ($picRows as $pr) {
            $pictures[] = [
                'pictureId' => (string)$pr['picture_id'],
                'originalUrl' => Config::APP_URL . '/storage/transaction/' . $uid . '/' . $pr['picture_id'] . '.' . $pr['picture_extension'],
            ];
        }

        $res = [
            'id' => $txId,
            'timeSequenceId' => (string)$row['transaction_time'],
            'type' => $frontendType,
            'categoryId' => (string)$row['category_id'],
            'time' => (int)floor(((int)$row['transaction_time']) / 1000),
            'utcOffset' => (int)$row['timezone_utc_offset'],
            'sourceAccountId' => (string)$row['account_id'],
            'destinationAccountId' => (string)($row['related_account_id'] ?? 0),
            'sourceAmount' => (int)$row['amount'],
            'destinationAmount' => (int)($row['related_account_amount'] ?? 0),
            'hideAmount' => (bool)$row['hide_amount'],
            'tagIds' => $tagIds,
            'tags' => $tags,
            'pictures' => $pictures,
            'comment' => (string)$row['comment'],
            'payeeName' => (string)($row['payee_name'] ?? ''),
            'currency' => (string)($row['currency'] ?? ($row['account_currency'] ?? 'INR')),
            'editable' => true,
        ];

        if (!empty($row['category_name'])) {
            $res['category'] = [
                'id' => (string)$row['category_id'],
                'name' => (string)$row['category_name'],
                'parentId' => '0',
                'type' => (int)($row['category_type'] ?? 2),
                'icon' => (string)$row['category_icon'],
                'iconType' => 0,
                'color' => (string)$row['category_color'],
                'comment' => '',
                'displayOrder' => 1,
                'hidden' => false,
            ];
        }

        if (!empty($row['account_name'])) {
            $res['sourceAccount'] = [
                'id' => (string)$row['account_id'],
                'name' => (string)$row['account_name'],
                'parentId' => '0',
                'category' => (int)($row['account_category'] ?? 1),
                'type' => 1,
                'icon' => (string)$row['account_icon'],
                'iconType' => 0,
                'color' => (string)$row['account_color'],
                'currency' => (string)$row['account_currency'],
                'balance' => '0',
                'comment' => '',
                'displayOrder' => 1,
                'hidden' => false,
            ];
        }

        return $res;
    }
}
