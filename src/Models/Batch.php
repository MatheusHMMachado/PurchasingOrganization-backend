<?php

namespace App\Models;

use App\Services\DatabaseService;
use PDO;

class Batch
{
    /**
     * Get all batches ordered by created_at DESC.
     */
    public static function findAll(): array
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->query("SELECT * FROM batches ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    /**
     * Find a batch by ID.
     */
    public static function findById(int $id): array|false
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM batches WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Create a new batch.
     */
    public static function create(array $data): array|false
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO batches (name, description) VALUES (:name, :description) RETURNING *"
        );
        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
        return $stmt->fetch();
    }

    /**
     * Update an existing batch.
     */
    public static function update(int $id, array $data): array|false
    {
        $pdo = DatabaseService::getInstance()->getConnection();

        $fields = [];
        $params = ['id' => $id];

        if (isset($data['name'])) {
            $fields[] = "name = :name";
            $params['name'] = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $fields[] = "description = :description";
            $params['description'] = $data['description'];
        }
        if (isset($data['status'])) {
            $fields[] = "status = :status";
            $params['status'] = $data['status'];
        }

        if (empty($fields)) {
            return self::findById($id);
        }

        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $fieldStr = implode(', ', $fields);

        $stmt = $pdo->prepare("UPDATE batches SET {$fieldStr} WHERE id = :id RETURNING *");
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Delete a batch by ID.
     */
    public static function delete(int $id): bool
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare("DELETE FROM batches WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get item count for a batch.
     */
    public static function getItemCount(int $id): int
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM items WHERE batch_id = :id");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return (int) $result['count'];
    }
}
