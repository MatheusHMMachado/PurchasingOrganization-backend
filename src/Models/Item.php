<?php

namespace App\Models;

use App\Services\DatabaseService;
use PDO;

class Item
{
    /**
     * Get all items.
     */
    public static function findAll(): array
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->query("SELECT * FROM items ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    /**
     * Find an item by ID.
     */
    public static function findById(int $id): array|false
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Find all items for a given batch.
     */
    public static function findByBatchId(int $batchId): array
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        // Includes offer aggregates so the list can show best price without extra requests
        $stmt = $pdo->prepare(
            "SELECT i.*,
                    COUNT(o.id)::int AS offer_count,
                    MIN(o.price) AS best_price
               FROM items i
          LEFT JOIN offers o ON o.item_id = i.id
              WHERE i.batch_id = :batch_id
           GROUP BY i.id
           ORDER BY i.created_at DESC"
        );
        $stmt->execute(['batch_id' => $batchId]);
        return $stmt->fetchAll();
    }

    /**
     * Create a new item.
     */
    public static function create(array $data): array|false
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO items (batch_id, name, category) VALUES (:batch_id, :name, :category) RETURNING *"
        );
        $stmt->execute([
            'batch_id' => $data['batch_id'],
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
        ]);
        return $stmt->fetch();
    }

    /**
     * Update an existing item.
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
        if (array_key_exists('category', $data)) {
            $fields[] = "category = :category";
            $params['category'] = $data['category'];
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

        $stmt = $pdo->prepare("UPDATE items SET {$fieldStr} WHERE id = :id RETURNING *");
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Delete an item by ID.
     */
    public static function delete(int $id): bool
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare("DELETE FROM items WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
