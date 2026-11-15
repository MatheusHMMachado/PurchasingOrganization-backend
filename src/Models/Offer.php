<?php

namespace App\Models;

use App\Services\DatabaseService;
use PDO;

class Offer
{
    /**
     * Get all offers.
     */
    public static function findAll(): array
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->query("SELECT * FROM offers ORDER BY price ASC");
        return $stmt->fetchAll();
    }

    /**
     * Find an offer by ID.
     */
    public static function findById(int $id): array|false
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM offers WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Find all offers for a given item, ordered by price ASC.
     */
    public static function findByItemId(int $itemId): array
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM offers WHERE item_id = :item_id ORDER BY price ASC");
        $stmt->execute(['item_id' => $itemId]);
        return $stmt->fetchAll();
    }

    /**
     * Create a new offer.
     */
    public static function create(array $data): array|false
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO offers (item_id, ecommerce, price, link) VALUES (:item_id, :ecommerce, :price, :link) RETURNING *"
        );
        $stmt->execute([
            'item_id' => $data['item_id'],
            'ecommerce' => $data['ecommerce'],
            'price' => $data['price'],
            'link' => $data['link'],
        ]);
        return $stmt->fetch();
    }

    /**
     * Update an existing offer (only provided fields).
     */
    public static function update(int $id, array $data): array|false
    {
        $pdo = DatabaseService::getInstance()->getConnection();

        $fields = [];
        $params = ['id' => $id];

        foreach (['ecommerce', 'price', 'link'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return self::findById($id);
        }

        $fieldStr = implode(', ', $fields);
        $stmt = $pdo->prepare("UPDATE offers SET {$fieldStr} WHERE id = :id RETURNING *");
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Delete an offer by ID.
     */
    public static function delete(int $id): bool
    {
        $pdo = DatabaseService::getInstance()->getConnection();
        $stmt = $pdo->prepare("DELETE FROM offers WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
