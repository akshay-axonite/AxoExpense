<?php
/**
 * Axonite Expense Tracking System - Statistics & Analytics Controller
 * 
 * Computes dashboard aggregates, income/expense sums, asset trends,
 * category breakdowns, and system data counts.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

class StatsController {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Aggregates total income, expense, and transfers by category and account for the given time range.
     */
    public function transactionStatistics(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $startTime = isset($_GET['start_time']) ? (int)$_GET['start_time'] : 0;
        $endTime = isset($_GET['end_time']) ? (int)$_GET['end_time'] : 0;

        $where = ["uid = :uid", "deleted = 0", "type IN (2, 3, 4, 5)"];
        $params = [':uid' => $uid];

        if ($startTime > 0) {
            $where[] = "transaction_time >= :start_time";
            $params[':start_time'] = $startTime * 1000;
        }
        if ($endTime > 0) {
            $where[] = "transaction_time <= :end_time";
            $params[':end_time'] = $endTime * 1000 + 999;
        }

        if (!empty($_GET['keyword'])) {
            $where[] = "(comment LIKE :kw OR payee_name LIKE :kw)";
            $params[':kw'] = '%' . trim($_GET['keyword']) . '%';
        }

        if (isset($_GET['tag_filter'])) {
            $tagFilter = trim($_GET['tag_filter']);
            if ($tagFilter === 'none') {
                $where[] = "transaction_id NOT IN (SELECT transaction_id FROM transaction_tag_index WHERE uid = :uid_tf)";
                $params[':uid_tf'] = $uid;
            } elseif (!empty($tagFilter)) {
                $tagIds = array_filter(array_map('trim', explode(',', $tagFilter)));
                if (!empty($tagIds)) {
                    $ph = [];
                    foreach ($tagIds as $idx => $tId) {
                        $p = ":tf_$idx";
                        $ph[] = $p;
                        $params[$p] = $tId;
                    }
                    $where[] = "transaction_id IN (SELECT transaction_id FROM transaction_tag_index WHERE uid = :uid_tf2 AND tag_id IN (" . implode(',', $ph) . "))";
                    $params[':uid_tf2'] = $uid;
                }
            }
        }

        $sql = "SELECT category_id, account_id, related_account_id, type, SUM(amount) AS total_amount 
                FROM transaction 
                WHERE " . implode(' AND ', $where) . " 
                GROUP BY category_id, account_id, related_account_id, type";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $type = (int)$row['type'];
            $item = [
                'categoryId' => (string)$row['category_id'],
                'accountId' => (string)$row['account_id'],
                'amount' => (string)$row['total_amount'],
            ];
            if ($type === 4 || $type === 5) {
                $item['relatedAccountId'] = (string)($row['related_account_id'] ?? '0');
                $item['relatedAccountType'] = ($type === 4) ? 2 : 1; // 2 = Transfer To, 1 = Transfer From
            }
            $items[] = $item;
        }

        Utils::successResponse([
            'startTime' => $startTime,
            'endTime' => $endTime,
            'items' => $items,
        ]);
    }

    /**
     * Aggregates monthly income, expense, and transfers for trend analysis.
     */
    public function transactionStatisticsTrends(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $startRaw = $_GET['start_year_month'] ?? $_GET['startYearMonth'] ?? '';
        $endRaw = $_GET['end_year_month'] ?? $_GET['endYearMonth'] ?? '';
        $startYM = preg_replace('/\D/', '', $startRaw);
        $endYM = preg_replace('/\D/', '', $endRaw);

        $startTxTime = 0;
        $endTxTime = 0;
        $startYearMonthNum = 0;
        $endYearMonthNum = 0;

        if (strlen($startYM) >= 6) {
            $sYear = (int)substr($startYM, 0, 4);
            $sMonth = (int)substr($startYM, 4, 2);
            $startYearMonthNum = $sYear * 100 + $sMonth;
            $startTxTime = mktime(0, 0, 0, $sMonth, 1, $sYear) * 1000;
        }

        if (strlen($endYM) >= 6) {
            $eYear = (int)substr($endYM, 0, 4);
            $eMonth = (int)substr($endYM, 4, 2);
            $endYearMonthNum = $eYear * 100 + $eMonth;
            $daysInMonth = (int)date('t', mktime(0, 0, 0, $eMonth, 1, $eYear));
            $endTxTime = mktime(23, 59, 59, $eMonth, $daysInMonth, $eYear) * 1000 + 999;
        }

        $where = ["uid = :uid", "deleted = 0", "type IN (2, 3, 4, 5)"];
        $params = [':uid' => $uid];

        if ($startTxTime > 0) {
            $where[] = "transaction_time >= :start_time";
            $params[':start_time'] = $startTxTime;
        }
        if ($endTxTime > 0) {
            $where[] = "transaction_time <= :end_time";
            $params[':end_time'] = $endTxTime;
        }

        if (!empty($_GET['keyword'])) {
            $where[] = "(comment LIKE :kw OR payee_name LIKE :kw)";
            $params[':kw'] = '%' . trim($_GET['keyword']) . '%';
        }

        if (isset($_GET['tag_filter'])) {
            $tagFilter = trim($_GET['tag_filter']);
            if ($tagFilter === 'none') {
                $where[] = "transaction_id NOT IN (SELECT transaction_id FROM transaction_tag_index WHERE uid = :uid_tf)";
                $params[':uid_tf'] = $uid;
            } elseif (!empty($tagFilter)) {
                $tagIds = array_filter(array_map('trim', explode(',', $tagFilter)));
                if (!empty($tagIds)) {
                    $ph = [];
                    foreach ($tagIds as $idx => $tId) {
                        $p = ":tf_$idx";
                        $ph[] = $p;
                        $params[$p] = $tId;
                    }
                    $where[] = "transaction_id IN (SELECT transaction_id FROM transaction_tag_index WHERE uid = :uid_tf2 AND tag_id IN (" . implode(',', $ph) . "))";
                    $params[':uid_tf2'] = $uid;
                }
            }
        }

        $sql = "SELECT category_id, account_id, related_account_id, type, amount, transaction_time, timezone_utc_offset 
                FROM transaction 
                WHERE " . implode(' AND ', $where) . " 
                ORDER BY transaction_time ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $useTxTimezone = isset($_GET['use_transaction_timezone']) && $_GET['use_transaction_timezone'] === 'true';
        $clientOffsetMinutes = isset($_SERVER['HTTP_X_TIMEZONE_OFFSET']) ? (int)$_SERVER['HTTP_X_TIMEZONE_OFFSET'] : 0;

        $grouped = [];
        foreach ($rows as $row) {
            $txTime = (int)$row['transaction_time'];
            $offsetMin = $useTxTimezone ? (int)($row['timezone_utc_offset'] ?? 0) : ($clientOffsetMinutes !== 0 ? $clientOffsetMinutes : (int)($row['timezone_utc_offset'] ?? 0));
            $adjustedSec = (int)(($txTime + ($offsetMin * 60 * 1000)) / 1000);

            $year = (int)gmdate('Y', $adjustedSec);
            $month = (int)gmdate('n', $adjustedSec);
            $ym = $year * 100 + $month;

            if ($startYearMonthNum > 0 && $ym < $startYearMonthNum) {
                continue;
            }
            if ($endYearMonthNum > 0 && $ym > $endYearMonthNum) {
                continue;
            }

            $type = (int)$row['type'];
            $catId = (string)$row['category_id'];
            $accId = (string)$row['account_id'];
            $relAccId = (string)($row['related_account_id'] ?? '0');

            if ($type === 4 || $type === 5) {
                $groupKey = sprintf('%d_%s_%s_%s_%d', $ym, $catId, $accId, $relAccId, $type);
            } else {
                $groupKey = sprintf('%d_%s_%s', $ym, $catId, $accId);
            }

            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'year' => $year,
                    'month' => $month,
                    'ym' => $ym,
                    'categoryId' => $catId,
                    'accountId' => $accId,
                    'relatedAccountId' => $relAccId,
                    'type' => $type,
                    'amount' => 0,
                ];
            }
            $grouped[$groupKey]['amount'] += (int)$row['amount'];
        }

        $monthlyMap = [];
        foreach ($grouped as $g) {
            $ym = $g['ym'];
            if (!isset($monthlyMap[$ym])) {
                $monthlyMap[$ym] = [
                    'year' => $g['year'],
                    'month' => $g['month'],
                    'items' => [],
                ];
            }
            $item = [
                'categoryId' => $g['categoryId'],
                'accountId' => $g['accountId'],
                'amount' => (string)$g['amount'],
            ];
            if ($g['type'] === 4 || $g['type'] === 5) {
                $item['relatedAccountId'] = $g['relatedAccountId'];
                $item['relatedAccountType'] = ($g['type'] === 4) ? 2 : 1;
            }
            $monthlyMap[$ym]['items'][] = $item;
        }

        ksort($monthlyMap);
        Utils::successResponse(array_values($monthlyMap));
    }

    /**
     * Calculates daily opening and closing balances for all accounts for asset trends.
     */
    public function transactionStatisticsAssetTrends(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $startTime = isset($_GET['start_time']) ? (int)$_GET['start_time'] : 0;
        $endTime = isset($_GET['end_time']) ? (int)$_GET['end_time'] : 0;

        $maxTxTime = ($endTime > 0) ? ($endTime * 1000 + 999) : (time() * 1000 + 999);
        $minTxTime = ($startTime > 0) ? ($startTime * 1000) : 0;

        $clientOffsetMinutes = isset($_SERVER['HTTP_X_TIMEZONE_OFFSET']) ? (int)$_SERVER['HTTP_X_TIMEZONE_OFFSET'] : 0;

        $sql = "SELECT transaction_id, account_id, type, amount, related_account_amount, transaction_time, timezone_utc_offset 
                FROM transaction 
                WHERE uid = :uid AND deleted = 0 AND transaction_time <= :max_time 
                ORDER BY transaction_time ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':uid' => $uid, ':max_time' => $maxTxTime]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            Utils::successResponse([]);
            return;
        }

        $accumulatedBalances = [];
        $accumulatedBeforeStart = [];
        $accountDailyLastBalances = [];

        foreach ($rows as $row) {
            $accId = (string)$row['account_id'];
            $openBalance = $accumulatedBalances[$accId] ?? 0;
            $type = (int)$row['type'];
            $amt = (int)$row['amount'];
            $relAmt = (int)($row['related_account_amount'] ?? 0);

            if ($type === 1) { // modify balance
                $delta = $relAmt;
            } elseif ($type === 2 || $type === 5) { // income or transfer in
                $delta = $amt;
            } elseif ($type === 3 || $type === 4) { // expense or transfer out
                $delta = -$amt;
            } else {
                $delta = 0;
            }

            $closeBalance = $openBalance + $delta;
            $accumulatedBalances[$accId] = $closeBalance;

            $txTime = (int)$row['transaction_time'];
            if ($minTxTime > 0 && $txTime < $minTxTime) {
                $accumulatedBeforeStart[$accId] = $closeBalance;
                continue;
            }

            $offsetMin = ($clientOffsetMinutes !== 0) ? $clientOffsetMinutes : (int)($row['timezone_utc_offset'] ?? 0);
            $adjustedSec = (int)(($txTime + ($offsetMin * 60 * 1000)) / 1000);

            $year = (int)gmdate('Y', $adjustedSec);
            $month = (int)gmdate('n', $adjustedSec);
            $day = (int)gmdate('j', $adjustedSec);
            $ymd = (int)sprintf('%04d%02d%02d', $year, $month, $day);
            $groupKey = $ymd . '_' . $accId;

            if (!isset($accountDailyLastBalances[$groupKey])) {
                $accountDailyLastBalances[$groupKey] = [
                    'accountId' => $accId,
                    'accountOpeningBalance' => (string)$openBalance,
                    'accountClosingBalance' => (string)$closeBalance,
                    'year' => $year,
                    'month' => $month,
                    'day' => $day,
                    'ymd' => $ymd,
                ];
            } else {
                $accountDailyLastBalances[$groupKey]['accountClosingBalance'] = (string)$closeBalance;
            }
        }

        // For accounts that had balance before $minTxTime but no transactions on the first day in range
        $firstTxTime = ($minTxTime > 0) ? $minTxTime : (int)$rows[0]['transaction_time'];
        $firstOffsetMin = ($clientOffsetMinutes !== 0) ? $clientOffsetMinutes : (int)($rows[0]['timezone_utc_offset'] ?? 0);
        $firstSec = (int)(($firstTxTime + ($firstOffsetMin * 60 * 1000)) / 1000);
        $firstYear = (int)gmdate('Y', $firstSec);
        $firstMonth = (int)gmdate('n', $firstSec);
        $firstDay = (int)gmdate('j', $firstSec);
        $firstYmd = (int)sprintf('%04d%02d%02d', $firstYear, $firstMonth, $firstDay);

        foreach ($accumulatedBeforeStart as $accId => $bal) {
            $groupKey = $firstYmd . '_' . $accId;
            if (!isset($accountDailyLastBalances[$groupKey])) {
                $accountDailyLastBalances[$groupKey] = [
                    'accountId' => (string)$accId,
                    'accountOpeningBalance' => (string)$bal,
                    'accountClosingBalance' => (string)$bal,
                    'year' => $firstYear,
                    'month' => $firstMonth,
                    'day' => $firstDay,
                    'ymd' => $firstYmd,
                ];
            }
        }

        $dailyMap = [];
        foreach ($accountDailyLastBalances as $entry) {
            $ymd = $entry['ymd'];
            if (!isset($dailyMap[$ymd])) {
                $dailyMap[$ymd] = [
                    'year' => $entry['year'],
                    'month' => $entry['month'],
                    'day' => $entry['day'],
                    'items' => [],
                ];
            }
            $dailyMap[$ymd]['items'][] = [
                'accountId' => (string)$entry['accountId'],
                'accountOpeningBalance' => (string)$entry['accountOpeningBalance'],
                'accountClosingBalance' => (string)$entry['accountClosingBalance'],
            ];
        }

        ksort($dailyMap);
        Utils::successResponse(array_values($dailyMap));
    }

    /**
     * Returns total income and expense amounts grouped by currency for the requested time segments.
     */
    /**
     * Returns total income and expense amounts grouped by currency for the requested time segments.
     */
    public function transactionAmounts(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];
        $defaultCurrency = $currentUser['default_currency'] ?? 'INR';

        $query = $_GET['query'] ?? '';
        $excludeAccounts = !empty($_GET['exclude_account_ids']) ? explode(',', $_GET['exclude_account_ids']) : [];
        $excludeCategories = !empty($_GET['exclude_category_ids']) ? explode(',', $_GET['exclude_category_ids']) : [];
        $tagFilter = isset($_GET['tag_filter']) ? trim($_GET['tag_filter']) : '';

        $baseWhere = ["t.uid = :uid", "t.deleted = 0", "t.type IN (2, 3)"];
        $baseParams = [':uid' => $uid];

        if (!empty($excludeAccounts)) {
            $accPlaceholders = [];
            foreach ($excludeAccounts as $idx => $accId) {
                $p = ":ex_acc_$idx";
                $accPlaceholders[] = $p;
                $baseParams[$p] = trim($accId);
            }
            $baseWhere[] = "t.account_id NOT IN (" . implode(',', $accPlaceholders) . ")";
        }

        if (!empty($excludeCategories)) {
            $catPlaceholders = [];
            foreach ($excludeCategories as $idx => $catId) {
                $p = ":ex_cat_$idx";
                $catPlaceholders[] = $p;
                $baseParams[$p] = trim($catId);
            }
            $baseWhere[] = "t.category_id NOT IN (" . implode(',', $catPlaceholders) . ")";
        }

        if ($tagFilter === 'none') {
            $baseWhere[] = "t.transaction_id NOT IN (SELECT transaction_id FROM transaction_tag_index WHERE uid = :uid_tf)";
            $baseParams[':uid_tf'] = $uid;
        } elseif (!empty($tagFilter)) {
            $tagIds = array_filter(array_map('trim', explode(',', $tagFilter)));
            if (!empty($tagIds)) {
                $tfPlaceholders = [];
                foreach ($tagIds as $idx => $tId) {
                    $p = ":tf_$idx";
                    $tfPlaceholders[] = $p;
                    $baseParams[$p] = $tId;
                }
                $baseWhere[] = "t.transaction_id IN (SELECT transaction_id FROM transaction_tag_index WHERE uid = :uid_tf2 AND tag_id IN (" . implode(',', $tfPlaceholders) . "))";
                $baseParams[':uid_tf2'] = $uid;
            }
        }

        $result = [];
        if (!empty($query)) {
            $parts = explode('|', $query);
            foreach ($parts as $part) {
                // Support both underscore (_) from Vue frontend and colon (:) if called that way
                $sub = str_contains($part, '_') ? explode('_', $part) : explode(':', $part);
                $name = $sub[0] ?? 'total';
                $sTime = isset($sub[1]) ? (int)$sub[1] : 0;
                $eTime = isset($sub[2]) ? (int)$sub[2] : 0;

                $qWhere = $baseWhere;
                $qParams = $baseParams;

                if ($sTime > 0) {
                    $qWhere[] = "t.transaction_time >= :stime_$name";
                    $qParams[":stime_$name"] = $sTime * 1000;
                }
                if ($eTime > 0) {
                    $qWhere[] = "t.transaction_time <= :etime_$name";
                    $qParams[":etime_$name"] = $eTime * 1000 + 999;
                }

                $sql = "SELECT COALESCE(t.currency, a.currency, :def_curr) AS curr,
                               COALESCE(SUM(CASE WHEN t.type = 2 THEN t.amount ELSE 0 END), 0) AS inc_amt,
                               COALESCE(SUM(CASE WHEN t.type = 3 THEN t.amount ELSE 0 END), 0) AS exp_amt
                        FROM transaction t
                        LEFT JOIN account a ON t.account_id = a.account_id AND a.deleted = 0
                        WHERE " . implode(' AND ', $qWhere) . "
                        GROUP BY curr";

                $qParams[':def_curr'] = $defaultCurrency;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($qParams);
                $rows = $stmt->fetchAll();

                $amounts = [];
                foreach ($rows as $r) {
                    $amounts[] = [
                        'currency' => (string)$r['curr'],
                        'incomeAmount' => (string)$r['inc_amt'],
                        'expenseAmount' => (string)$r['exp_amt'],
                    ];
                }

                if (empty($amounts)) {
                    $amounts[] = [
                        'currency' => $defaultCurrency,
                        'incomeAmount' => '0',
                        'expenseAmount' => '0',
                    ];
                }

                $result[$name] = [
                    'startTime' => $sTime,
                    'endTime' => $eTime,
                    'amounts' => $amounts,
                ];
            }
        } else {
            // Overall total for user
            $sql = "SELECT COALESCE(t.currency, a.currency, :def_curr) AS curr,
                           COALESCE(SUM(CASE WHEN t.type = 2 THEN t.amount ELSE 0 END), 0) AS inc_amt,
                           COALESCE(SUM(CASE WHEN t.type = 3 THEN t.amount ELSE 0 END), 0) AS exp_amt
                    FROM transaction t
                    LEFT JOIN account a ON t.account_id = a.account_id AND a.deleted = 0
                    WHERE " . implode(' AND ', $baseWhere) . "
                    GROUP BY curr";

            $baseParams[':def_curr'] = $defaultCurrency;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($baseParams);
            $rows = $stmt->fetchAll();

            $amounts = [];
            foreach ($rows as $r) {
                $amounts[] = [
                    'currency' => (string)$r['curr'],
                    'incomeAmount' => (string)$r['inc_amt'],
                    'expenseAmount' => (string)$r['exp_amt'],
                ];
            }

            if (empty($amounts)) {
                $amounts[] = [
                    'currency' => $defaultCurrency,
                    'incomeAmount' => '0',
                    'expenseAmount' => '0',
                ];
            }

            $result['total'] = [
                'startTime' => 0,
                'endTime' => 0,
                'amounts' => $amounts,
            ];
        }

        Utils::successResponse($result);
    }

    /**
     * Returns daily transaction amounts (income & expense grouped by currency) for dashboard charts.
     */
    public function transactionDailyAmounts(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];
        $defaultCurrency = $currentUser['default_currency'] ?? 'INR';

        $startTime = isset($_GET['start_time']) ? (int)$_GET['start_time'] : 0;
        $endTime = isset($_GET['end_time']) ? (int)$_GET['end_time'] : 0;
        $excludeAccounts = !empty($_GET['exclude_account_ids']) ? explode(',', $_GET['exclude_account_ids']) : [];
        $excludeCategories = !empty($_GET['exclude_category_ids']) ? explode(',', $_GET['exclude_category_ids']) : [];

        $where = ["t.uid = :uid", "t.deleted = 0", "t.type IN (2, 3)"];
        $params = [':uid' => $uid];

        if ($startTime > 0) {
            $where[] = "t.transaction_time >= :start_time";
            $params[':start_time'] = $startTime * 1000;
        }
        if ($endTime > 0) {
            $where[] = "t.transaction_time <= :end_time";
            $params[':end_time'] = $endTime * 1000 + 999;
        }

        if (!empty($excludeAccounts)) {
            $excAccPlaceholders = [];
            foreach ($excludeAccounts as $idx => $accId) {
                $p = ":exc_acc_$idx";
                $excAccPlaceholders[] = $p;
                $params[$p] = $accId;
            }
            $where[] = "t.account_id NOT IN (" . implode(',', $excAccPlaceholders) . ")";
        }

        if (!empty($excludeCategories)) {
            $excCatPlaceholders = [];
            foreach ($excludeCategories as $idx => $catId) {
                $p = ":exc_cat_$idx";
                $excCatPlaceholders[] = $p;
                $params[$p] = $catId;
            }
            $where[] = "t.category_id NOT IN (" . implode(',', $excCatPlaceholders) . ")";
        }

        $sql = "SELECT t.transaction_time, t.timezone_utc_offset, t.type, t.amount, t.currency,
                       a.currency AS account_currency
                FROM transaction t
                LEFT JOIN account a ON t.account_id = a.account_id AND a.deleted = 0
                WHERE " . implode(' AND ', $where) . "
                ORDER BY t.transaction_time ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $daily = [];
        foreach ($rows as $row) {
            $txTime = (int)$row['transaction_time'];
            $utcOffsetMinutes = (int)($row['timezone_utc_offset'] ?? 0);
            // Calculate date in the transaction's timezone
            $date = gmdate('Y-m-d', (int)(($txTime + ($utcOffsetMinutes * 60 * 1000)) / 1000));
            $curr = !empty($row['currency']) ? $row['currency'] : (!empty($row['account_currency']) ? $row['account_currency'] : $defaultCurrency);

            if (!isset($daily[$date])) {
                $daily[$date] = [];
            }
            if (!isset($daily[$date][$curr])) {
                $daily[$date][$curr] = [
                    'incomeAmount' => 0,
                    'expenseAmount' => 0,
                ];
            }

            $type = (int)$row['type'];
            $amt = (int)$row['amount'];
            if ($type === 2) {
                $daily[$date][$curr]['incomeAmount'] += $amt;
            } elseif ($type === 3) {
                $daily[$date][$curr]['expenseAmount'] += $amt;
            }
        }

        $result = [];
        ksort($daily);
        foreach ($daily as $date => $currencies) {
            $amounts = [];
            foreach ($currencies as $curr => $val) {
                $amounts[] = [
                    'currency' => (string)$curr,
                    'incomeAmount' => (string)$val['incomeAmount'],
                    'expenseAmount' => (string)$val['expenseAmount'],
                ];
            }
            $result[] = [
                'date' => $date,
                'amounts' => $amounts,
            ];
        }

        Utils::successResponse($result);
    }

    /**
     * Returns system overview statistics for settings / data overview page.
     */
    public function dataStatistics(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $accStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM account WHERE uid = :uid AND deleted = 0");
        $accStmt->execute([':uid' => $uid]);
        $totalAccountCount = (int)$accStmt->fetch()['c'];

        $txStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM transaction WHERE uid = :uid AND deleted = 0");
        $txStmt->execute([':uid' => $uid]);
        $totalTransactionCount = (int)$txStmt->fetch()['c'];

        $catStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM transaction_category WHERE uid = :uid AND deleted = 0");
        $catStmt->execute([':uid' => $uid]);
        $totalCategoryCount = (int)$catStmt->fetch()['c'];

        $tagStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM transaction_tag WHERE uid = :uid AND deleted = 0");
        $tagStmt->execute([':uid' => $uid]);
        $totalTagCount = (int)$tagStmt->fetch()['c'];

        Utils::successResponse([
            'totalAccountCount' => $totalAccountCount,
            'totalTransactionCount' => $totalTransactionCount,
            'totalCategoryCount' => $totalCategoryCount,
            'totalTagCount' => $totalTagCount,
            'totalPictureInfoCount' => 0,
        ]);
    }

    /**
     * Returns backend system version info.
     */
    public function systemVersion(): void {
        Utils::successResponse([
            'version' => 'v1.5.0-php',
            'commitHash' => 'php-native-shared-hosting',
            'buildTime' => '2026-11-20',
        ]);
    }
}
