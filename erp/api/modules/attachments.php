<?php
/**
 * ERP Module: Вложения (файлы, инвойсы, изображения)
 * 
 * attachments.list     — список файлов по entity
 * attachments.upload   — загрузить файл (multipart/form-data)
 * attachments.update   — обновить описание
 * attachments.delete   — удалить файл
 */

require_once __DIR__ . '/../s3.php';

class ERP_Attachments {

    private function getS3(): S3Client {
        $cfg = require __DIR__ . '/../config.php';
        return new S3Client($cfg['s3']);
    }

    /**
     * Список файлов
     * GET/POST entity_type=supply&entity_id=15
     */
    public function list(): array {
        $entityType = param('entity_type');
        $entityId   = (int) param('entity_id');
        if (!$entityType || !$entityId) errorResponse('entity_type and entity_id required');

        $allowedTypes = ['supply', 'transaction', 'task', 'order', 'product'];
        if (!in_array($entityType, $allowedTypes)) errorResponse('Invalid entity_type');

        $pdo = DB::get();
        $stmt = $pdo->prepare("
            SELECT * FROM erp_attachments 
            WHERE entity_type = ? AND entity_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$entityType, $entityId]);

        return ['items' => $stmt->fetchAll()];
    }

    /**
     * Загрузить файл
     * POST multipart/form-data: file, entity_type, entity_id, description
     */
    public function upload(): array {
        $entityType  = $_POST['entity_type']  ?? param('entity_type');
        $entityId    = (int)($_POST['entity_id'] ?? param('entity_id'));
        $description = $_POST['description']  ?? param('description', '');

        if (!$entityType || !$entityId) errorResponse('entity_type and entity_id required');

        $allowedTypes = ['supply', 'transaction', 'task', 'order', 'product'];
        if (!in_array($entityType, $allowedTypes)) errorResponse('Invalid entity_type');

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            errorResponse('No file uploaded or upload error');
        }

        $file = $_FILES['file'];
        $filename = basename($file['name']);
        $fileSize = (int) $file['size'];
        $fileType = $file['type'] ?: 'application/octet-stream';

        // Ограничение: 20MB
        if ($fileSize > 20 * 1024 * 1024) {
            errorResponse('File too large (max 20MB)');
        }

        // Безопасные типы
        $allowedMime = [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
            'application/vnd.ms-excel', // xls
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // docx
            'text/plain', 'text/csv',
        ];
        if (!in_array($fileType, $allowedMime)) {
            errorResponse('File type not allowed: ' . $fileType);
        }

        $body = file_get_contents($file['tmp_name']);
        $s3Key = S3Client::generateKey($entityType, $entityId, $filename);

        $s3 = $this->getS3();
        $url = $s3->upload($s3Key, $body, $fileType);

        // Сохраняем в БД
        $pdo = DB::get();
        $stmt = $pdo->prepare("
            INSERT INTO erp_attachments (entity_type, entity_id, description, filename, s3_key, url, file_type, file_size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$entityType, $entityId, $description ?: null, $filename, $s3Key, $url, $fileType, $fileSize]);

        return [
            'ok'  => true,
            'id'  => (int) $pdo->lastInsertId(),
            'url' => $url,
        ];
    }

    /**
     * Обновить описание
     * POST {id, description}
     */
    public function update(): array {
        $input = jsonInput();
        $id = (int)($input['id'] ?? param('id'));
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $pdo->prepare("UPDATE erp_attachments SET description = ? WHERE id = ?")
            ->execute([$input['description'] ?? '', $id]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Удалить файл
     * POST {id} или GET ?id=N
     */
    public function delete(): array {
        $id = (int)(param('id') ?: (jsonInput()['id'] ?? 0));
        if (!$id) errorResponse('id required');

        $pdo = DB::get();
        $stmt = $pdo->prepare("SELECT s3_key FROM erp_attachments WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) errorResponse('Not found', 404);

        // Удаляем из S3
        try {
            $s3 = $this->getS3();
            $s3->delete($row['s3_key']);
        } catch (Exception $e) {
            // Логируем, но не блокируем удаление из БД
        }

        $pdo->prepare("DELETE FROM erp_attachments WHERE id = ?")->execute([$id]);

        return ['ok' => true];
    }
}
