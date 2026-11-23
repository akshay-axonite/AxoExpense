<?php
/**
 * Axonite Expense Tracking System - Picture / Receipt Upload Controller
 * 
 * Handles uploading receipt photos, bill scans, and payment proof attachments.
 * Stores files securely in storage/transaction/{uid}/ and tracks metadata in MySQL.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

class PictureController {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Handles multipart file upload for receipt images.
     */
    public function uploadPicture(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        // Accept either 'picture' (standard ezbookkeeping frontend) or 'file'
        $file = $_FILES['picture'] ?? $_FILES['file'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Utils::errorResponse(204001, 'No valid file uploaded');
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf'];

        if (!in_array($ext, $allowedExts, true)) {
            Utils::errorResponse(204002, 'Unsupported image type: ' . $ext);
            return;
        }

        // Target storage directory: storage/transaction/{uid}/
        $userStorageDir = Config::TRANSACTION_STORAGE_PATH . DIRECTORY_SEPARATOR . $uid;
        if (!is_dir($userStorageDir)) {
            mkdir($userStorageDir, 0755, true);
        }

        $pictureId = Utils::generateUniqueId();
        $destinationFileName = $pictureId . '.' . $ext;
        $destinationPath = $userStorageDir . DIRECTORY_SEPARATOR . $destinationFileName;

        if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
            Utils::errorResponse(204003, 'Failed to save uploaded picture');
            return;
        }

        $now = time();
        $sql = "INSERT INTO transaction_picture_info (uid, deleted, transaction_id, picture_id, picture_extension, created_ip, created_unix_time, updated_unix_time, deleted_unix_time) 
                VALUES (:uid, 0, 0, :pic_id, :ext, :ip, :created_unix_time, :updated_unix_time, 0)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':uid' => $uid,
            ':pic_id' => $pictureId,
            ':ext' => $ext,
            ':ip' => Utils::getClientIp(),
            ':created_unix_time' => $now,
            ':updated_unix_time' => $now,
        ]);

        $url = Config::APP_URL . '/storage/transaction/' . $uid . '/' . $destinationFileName;

        Utils::successResponse([
            'pictureId' => (string)$pictureId,
            'originalUrl' => $url,
        ]);
    }

    /**
     * Removes an unused picture file if a transaction was discarded before saving.
     */
    public function removeUnusedPicture(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];

        $input = Utils::getJsonInput();
        $id = $input['id'] ?? null;

        if (!$id) {
            Utils::errorResponse(204004, 'Picture ID is required');
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM transaction_picture_info WHERE picture_id = :id AND uid = :uid AND deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $id, ':uid' => $uid]);
        $pic = $stmt->fetch();

        if ($pic) {
            $filePath = Config::TRANSACTION_STORAGE_PATH . DIRECTORY_SEPARATOR . $uid . DIRECTORY_SEPARATOR . $pic['picture_id'] . '.' . $pic['picture_extension'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $delStmt = $this->db->prepare("UPDATE transaction_picture_info SET deleted = 1, deleted_unix_time = :now WHERE picture_id = :id AND uid = :uid");
            $delStmt->execute([':now' => time(), ':id' => $id, ':uid' => $uid]);
        }

        Utils::successResponse(true);
    }
}
