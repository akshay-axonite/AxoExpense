<?php
/**
 * Axonite Expense Tracking System - Category Controller
 * 
 * Manages income, expense, and transfer categories and hierarchical sub-categories.
 * Fully compliant with ezbookkeeping Vue 3 category schemas.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

class CategoryController {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Lists transaction categories grouped by type (1=Income, 2=Expense, 3=Transfer).
     * Sub-categories are nested inside their respective parent category.
     */
    public function listCategories(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $type = isset($_GET['type']) ? (int)$_GET['type'] : 0;
        $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : -1;

        $sql = "SELECT * FROM transaction_category WHERE uid = :uid AND deleted = 0";
        $params = [':uid' => $uid];

        if ($type > 0) {
            $sql .= " AND type = :type";
            $params[':type'] = $type;
        }

        $sql .= " ORDER BY display_order ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $categoryMap = [];
        $parentCategories = [];
        $subCategories = [];

        foreach ($rows as $row) {
            $formatted = $this->formatCategory($row);
            $categoryMap[$formatted['id']] = $formatted;

            if ($formatted['parentId'] === '0') {
                $parentCategories[] = $formatted;
            } else {
                $subCategories[] = $formatted;
            }
        }

        // Attach sub-categories to their parent category
        foreach ($subCategories as $sub) {
            $pId = $sub['parentId'];
            foreach ($parentCategories as &$parent) {
                if ($parent['id'] === $pId) {
                    $parent['subCategories'][] = $sub;
                    break;
                }
            }
        }

        // If specific parent requested, return only its children
        if ($parentId > 0) {
            $children = [];
            foreach ($subCategories as $sub) {
                if ($sub['parentId'] === (string)$parentId) {
                    $children[] = $sub;
                }
            }
            Utils::successResponse($children);
            return;
        }

        // Otherwise return map grouped by CategoryType (1=Income, 2=Expense, 3=Transfer)
        $grouped = [
            '1' => [],
            '2' => [],
            '3' => [],
        ];

        foreach ($parentCategories as $parent) {
            $catType = (string)$parent['type'];
            if (!isset($grouped[$catType])) {
                $grouped[$catType] = [];
            }
            $grouped[$catType][] = $parent;
        }

        Utils::successResponse($grouped);
    }

    /**
     * Retrieves a single transaction category by ID.
     */
    public function getCategory(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $id = $_GET['id'] ?? null;
        if (!$id) {
            Utils::errorResponse(201001, 'Category ID is required');
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM transaction_category WHERE category_id = :id AND uid = :uid AND deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        $row = $stmt->fetch();

        if (!$row) {
            Utils::errorResponse(201002, 'Category not found', 404);
            return;
        }

        Utils::successResponse($this->formatCategory($row));
    }

    /**
     * Creates a new transaction category.
     */
    public function addCategory(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $name = trim($input['name'] ?? '');
        $type = (int)($input['type'] ?? 2); // default: 2 (Expense)
        $parentId = (int)($input['parentId'] ?? 0);
        $icon = (int)($input['icon'] ?? 1);
        $iconType = (int)($input['iconType'] ?? 0);
        $color = trim($input['color'] ?? '000000');
        $comment = trim($input['comment'] ?? '');

        if ($name === '') {
            Utils::errorResponse(201003, 'Category name cannot be empty');
            return;
        }

        // Determine next display order
        $orderStmt = $this->db->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM transaction_category WHERE uid = :uid AND parent_category_id = :parent_id");
        $orderStmt->execute([':uid' => $uid, ':parent_id' => $parentId]);
        $displayOrder = (int)$orderStmt->fetch()['next_order'];

        $now = time();
        $categoryId = Utils::generateUniqueId();

        $sql = "INSERT INTO transaction_category (category_id, uid, deleted, type, parent_category_id, name, display_order, icon, icon_type, color, hidden, comment, created_unix_time, updated_unix_time, deleted_unix_time) 
                VALUES (:category_id, :uid, 0, :type, :parent_id, :name, :display_order, :icon, :icon_type, :color, 0, :comment, :created_unix_time, :updated_unix_time, 0)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':category_id' => $categoryId,
            ':uid' => $uid,
            ':type' => $type,
            ':parent_id' => $parentId,
            ':name' => $name,
            ':display_order' => $displayOrder,
            ':icon' => $icon,
            ':icon_type' => $iconType,
            ':color' => $color,
            ':comment' => $comment,
            ':created_unix_time' => $now,
            ':updated_unix_time' => $now,
        ]);

        $created = [
            'id' => (string)$categoryId,
            'name' => $name,
            'parentId' => (string)$parentId,
            'type' => $type,
            'icon' => (string)$icon,
            'iconType' => $iconType,
            'color' => $color,
            'comment' => $comment,
            'displayOrder' => $displayOrder,
            'hidden' => false,
            'subCategories' => [],
        ];

        Utils::successResponse($created);
    }

    /**
     * Batch creation of parent categories with their children.
     */
    public function addBatchCategories(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $categories = $input['categories'] ?? [];

        if (!is_array($categories) || empty($categories)) {
            Utils::errorResponse(201004, 'Categories array cannot be empty');
            return;
        }

        $now = time();
        $this->db->beginTransaction();
        try {
            foreach ($categories as $parentCat) {
                $parentId = Utils::generateUniqueId();
                $parentName = trim($parentCat['name'] ?? '');
                $type = (int)($parentCat['type'] ?? 2);
                $icon = (int)($parentCat['icon'] ?? 1);
                $iconType = (int)($parentCat['iconType'] ?? 0);
                $color = trim($parentCat['color'] ?? '000000');
                $comment = trim($parentCat['comment'] ?? '');

                $orderStmt = $this->db->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM transaction_category WHERE uid = :uid AND parent_category_id = 0");
                $orderStmt->execute([':uid' => $uid]);
                $pOrder = (int)$orderStmt->fetch()['next_order'];

                $stmt = $this->db->prepare("INSERT INTO transaction_category (category_id, uid, deleted, type, parent_category_id, name, display_order, icon, icon_type, color, hidden, comment, created_unix_time, updated_unix_time, deleted_unix_time) 
                                            VALUES (:category_id, :uid, 0, :type, 0, :name, :display_order, :icon, :icon_type, :color, 0, :comment, :created_unix_time, :updated_unix_time, 0)");
                $stmt->execute([
                    ':category_id' => $parentId,
                    ':uid' => $uid,
                    ':type' => $type,
                    ':name' => $parentName,
                    ':display_order' => $pOrder,
                    ':icon' => $icon,
                    ':icon_type' => $iconType,
                    ':color' => $color,
                    ':comment' => $comment,
                    ':created_unix_time' => $now,
                    ':updated_unix_time' => $now,
                ]);

                if (!empty($parentCat['subCategories']) && is_array($parentCat['subCategories'])) {
                    $subOrder = 1;
                    foreach ($parentCat['subCategories'] as $subCat) {
                        $subId = Utils::generateUniqueId();
                        $subName = trim($subCat['name'] ?? '');
                        $subIcon = (int)($subCat['icon'] ?? $icon);
                        $subColor = trim($subCat['color'] ?? $color);
                        $subComment = trim($subCat['comment'] ?? '');

                        $subStmt = $this->db->prepare("INSERT INTO transaction_category (category_id, uid, deleted, type, parent_category_id, name, display_order, icon, icon_type, color, hidden, comment, created_unix_time, updated_unix_time, deleted_unix_time) 
                                                       VALUES (:category_id, :uid, 0, :type, :parent_id, :name, :display_order, :icon, :icon_type, :color, 0, :comment, :created_unix_time, :updated_unix_time, 0)");
                        $subStmt->execute([
                            ':category_id' => $subId,
                            ':uid' => $uid,
                            ':type' => $type,
                            ':parent_id' => $parentId,
                            ':name' => $subName,
                            ':display_order' => $subOrder++,
                            ':icon' => $subIcon,
                            ':icon_type' => $iconType,
                            ':color' => $subColor,
                            ':comment' => $subComment,
                            ':created_unix_time' => $now,
                            ':updated_unix_time' => $now,
                        ]);
                    }
                }
            }

            $this->db->commit();
            Utils::successResponse(true);
        } catch (Throwable $e) {
            $this->db->rollBack();
            Utils::errorResponse(201005, 'Failed to create categories: ' . $e->getMessage());
        }
    }

    /**
     * Modifies an existing transaction category.
     */
    public function modifyCategory(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;
        if (!$id) {
            Utils::errorResponse(201006, 'Category ID is required');
            return;
        }

        $name = trim($input['name'] ?? '');
        $icon = (int)($input['icon'] ?? 1);
        $iconType = (int)($input['iconType'] ?? 0);
        $color = trim($input['color'] ?? '000000');
        $comment = trim($input['comment'] ?? '');
        $hidden = !empty($input['hidden']) ? 1 : 0;
        $now = time();

        $sql = "UPDATE transaction_category SET name = :name, icon = :icon, icon_type = :icon_type, color = :color, comment = :comment, hidden = :hidden, updated_unix_time = :updated_unix_time 
                WHERE category_id = :id AND uid = :uid AND deleted = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':icon' => $icon,
            ':icon_type' => $iconType,
            ':color' => $color,
            ':comment' => $comment,
            ':hidden' => $hidden,
            ':updated_unix_time' => $now,
            ':id' => $id,
            ':uid' => $uid,
        ]);

        $_GET['id'] = $id;
        $this->getCategory();
    }

    /**
     * Toggles visibility (hide/unhide) of a category.
     */
    public function hideCategory(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;
        $hidden = !empty($input['hidden']) ? 1 : 0;

        if (!$id) {
            Utils::errorResponse(201007, 'Category ID is required');
            return;
        }

        $stmt = $this->db->prepare("UPDATE transaction_category SET hidden = :hidden, updated_unix_time = :now WHERE category_id = :id AND uid = :uid AND deleted = 0");
        $stmt->execute([
            ':hidden' => $hidden,
            ':now' => time(),
            ':id' => $id,
            ':uid' => $uid,
        ]);

        Utils::successResponse(true);
    }

    /**
     * Reorders categories based on user dragging / sequence.
     */
    public function moveCategory(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $newOrders = $input['newDisplayOrders'] ?? [];

        if (!is_array($newOrders) || empty($newOrders)) {
            Utils::errorResponse(201008, 'No display orders provided');
            return;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE transaction_category SET display_order = :order, updated_unix_time = :now WHERE category_id = :id AND uid = :uid");
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
            Utils::errorResponse(201009, 'Failed to update category display orders: ' . $e->getMessage());
        }
    }

    /**
     * Soft deletes a category and all its children.
     */
    public function deleteCategory(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;

        if (!$id) {
            Utils::errorResponse(201010, 'Category ID is required');
            return;
        }

        $now = time();
        $this->db->beginTransaction();
        try {
            // Delete category
            $stmt = $this->db->prepare("UPDATE transaction_category SET deleted = 1, deleted_unix_time = :now WHERE category_id = :id AND uid = :uid");
            $stmt->execute([':now' => $now, ':id' => $id, ':uid' => $uid]);

            // Delete sub-categories
            $subStmt = $this->db->prepare("UPDATE transaction_category SET deleted = 1, deleted_unix_time = :now WHERE parent_category_id = :id AND uid = :uid");
            $subStmt->execute([':now' => $now, ':id' => $id, ':uid' => $uid]);

            $this->db->commit();
            Utils::successResponse(true);
        } catch (Throwable $e) {
            $this->db->rollBack();
            Utils::errorResponse(201011, 'Failed to delete category: ' . $e->getMessage());
        }
    }

    /**
     * Formats category database row for frontend JSON output.
     */
    private function formatCategory(array $row): array {
        return [
            'id' => (string)$row['category_id'],
            'name' => (string)$row['name'],
            'parentId' => (string)$row['parent_category_id'],
            'type' => (int)$row['type'],
            'icon' => (string)$row['icon'],
            'iconType' => (int)($row['icon_type'] ?? 0),
            'color' => (string)$row['color'],
            'comment' => (string)($row['comment'] ?? ''),
            'displayOrder' => (int)$row['display_order'],
            'hidden' => (bool)$row['hidden'],
            'subCategories' => [],
        ];
    }
}
