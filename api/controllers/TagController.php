<?php
/**
 * Axonite Expense Tracking System - Tag Controller
 * 
 * Manages transaction tags and tag groups.
 * Compatible with ezbookkeeping Vue 3 tag schemas.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

class TagController {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Lists all tags for the authenticated user.
     */
    public function listTags(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $stmt = $this->db->prepare("SELECT * FROM transaction_tag WHERE uid = :uid AND deleted = 0 ORDER BY display_order ASC");
        $stmt->execute([':uid' => $uid]);
        $rows = $stmt->fetchAll();

        $tags = [];
        foreach ($rows as $row) {
            $tags[] = [
                'id' => (string)$row['tag_id'],
                'name' => (string)$row['name'],
                'groupId' => (string)($row['tag_group_id'] ?? 0),
                'displayOrder' => (int)$row['display_order'],
                'hidden' => (bool)$row['hidden'],
            ];
        }

        Utils::successResponse($tags);
    }

    /**
     * Retrieves a single tag by ID.
     */
    public function getTag(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $id = $_GET['id'] ?? null;
        if (!$id) {
            Utils::errorResponse(202001, 'Tag ID is required');
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM transaction_tag WHERE tag_id = :id AND uid = :uid AND deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        $row = $stmt->fetch();

        if (!$row) {
            Utils::errorResponse(202002, 'Tag not found', 404);
            return;
        }

        Utils::successResponse([
            'id' => (string)$row['tag_id'],
            'name' => (string)$row['name'],
            'groupId' => (string)($row['tag_group_id'] ?? 0),
            'displayOrder' => (int)$row['display_order'],
            'hidden' => (bool)$row['hidden'],
        ]);
    }

    /**
     * Creates a new transaction tag.
     */
    public function addTag(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $name = trim($input['name'] ?? '');
        $groupId = (int)($input['groupId'] ?? 0);

        if ($name === '') {
            Utils::errorResponse(202003, 'Tag name cannot be empty');
            return;
        }

        $orderStmt = $this->db->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM transaction_tag WHERE uid = :uid");
        $orderStmt->execute([':uid' => $uid]);
        $displayOrder = (int)$orderStmt->fetch()['next_order'];

        $now = time();
        $tagId = Utils::generateUniqueId();

        $stmt = $this->db->prepare("INSERT INTO transaction_tag (tag_id, uid, deleted, tag_group_id, name, display_order, hidden, created_unix_time, updated_unix_time, deleted_unix_time) 
                                    VALUES (:tag_id, :uid, 0, :group_id, :name, :display_order, 0, :created_unix_time, :updated_unix_time, 0)");
        $stmt->execute([
            ':tag_id' => $tagId,
            ':uid' => $uid,
            ':group_id' => $groupId,
            ':name' => $name,
            ':display_order' => $displayOrder,
            ':created_unix_time' => $now,
            ':updated_unix_time' => $now,
        ]);

        Utils::successResponse([
            'id' => (string)$tagId,
            'name' => $name,
            'groupId' => (string)$groupId,
            'displayOrder' => $displayOrder,
            'hidden' => false,
        ]);
    }

    /**
     * Modifies an existing tag.
     */
    public function modifyTag(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;
        $name = trim($input['name'] ?? '');
        $groupId = (int)($input['groupId'] ?? 0);

        if (!$id || $name === '') {
            Utils::errorResponse(202004, 'Tag ID and name are required');
            return;
        }

        $stmt = $this->db->prepare("UPDATE transaction_tag SET name = :name, tag_group_id = :group_id, updated_unix_time = :now 
                                    WHERE tag_id = :id AND uid = :uid AND deleted = 0");
        $stmt->execute([
            ':name' => $name,
            ':group_id' => $groupId,
            ':now' => time(),
            ':id' => $id,
            ':uid' => $uid,
        ]);

        $_GET['id'] = $id;
        $this->getTag();
    }

    /**
     * Toggles tag visibility.
     */
    public function hideTag(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;
        $hidden = !empty($input['hidden']) ? 1 : 0;

        if (!$id) {
            Utils::errorResponse(202005, 'Tag ID is required');
            return;
        }

        $stmt = $this->db->prepare("UPDATE transaction_tag SET hidden = :hidden, updated_unix_time = :now WHERE tag_id = :id AND uid = :uid AND deleted = 0");
        $stmt->execute([
            ':hidden' => $hidden,
            ':now' => time(),
            ':id' => $id,
            ':uid' => $uid,
        ]);

        Utils::successResponse(true);
    }

    /**
     * Reorders tags.
     */
    public function moveTag(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $newOrders = $input['newDisplayOrders'] ?? [];

        if (!is_array($newOrders) || empty($newOrders)) {
            Utils::errorResponse(202006, 'No display orders provided');
            return;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE transaction_tag SET display_order = :order, updated_unix_time = :now WHERE tag_id = :id AND uid = :uid");
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
            Utils::errorResponse(202007, 'Failed to update tag order: ' . $e->getMessage());
        }
    }

    /**
     * Soft deletes a tag.
     */
    public function deleteTag(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;

        if (!$id) {
            Utils::errorResponse(202008, 'Tag ID is required');
            return;
        }

        $stmt = $this->db->prepare("UPDATE transaction_tag SET deleted = 1, deleted_unix_time = :now WHERE tag_id = :id AND uid = :uid");
        $stmt->execute([':now' => time(), ':id' => $id, ':uid' => $uid]);

        Utils::successResponse(true);
    }

    /**
     * Lists tag groups.
     */
    public function listTagGroups(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $stmt = $this->db->prepare("SELECT * FROM transaction_tag_group WHERE uid = :uid AND deleted = 0 ORDER BY display_order ASC");
        $stmt->execute([':uid' => $uid]);
        $rows = $stmt->fetchAll();

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = [
                'id' => (string)$row['tag_group_id'],
                'name' => (string)$row['name'],
                'displayOrder' => (int)$row['display_order'],
            ];
        }

        Utils::successResponse($groups);
    }
}
