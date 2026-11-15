<?php

namespace App\Models;

use App\Services\DatabaseService;
use PDO;

/**
 * Login accounts. password_hash never leaves this class through toPublic().
 */
class User
{
    private const PUBLIC_COLUMNS = 'id, username, created_at, updated_at, last_login_at';

    private static function db(): PDO
    {
        return DatabaseService::getInstance()->getConnection();
    }

    public static function all(): array
    {
        return self::db()
            ->query('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users ORDER BY LOWER(username)')
            ->fetchAll();
    }

    public static function count(): int
    {
        return (int) self::db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** Full row (with password_hash), for authentication only. */
    public static function findByUsername(string $username): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(:username)');
        $stmt->execute(['username' => $username]);
        return $stmt->fetch() ?: null;
    }

    /** Full row (with password_hash), for authentication only. */
    public static function findById(int $id): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function usernameTaken(string $username, ?int $exceptId = null): bool
    {
        $stmt = self::db()->prepare(
            'SELECT 1 FROM users WHERE LOWER(username) = LOWER(:username) AND id <> :except'
        );
        $stmt->execute(['username' => $username, 'except' => $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    public static function create(string $username, string $passwordHash): array
    {
        $stmt = self::db()->prepare(
            'INSERT INTO users (username, password_hash) VALUES (:username, :hash) RETURNING ' . self::PUBLIC_COLUMNS
        );
        $stmt->execute(['username' => $username, 'hash' => $passwordHash]);
        return $stmt->fetch();
    }

    /** Updates username and/or password hash (null = keep). */
    public static function update(int $id, ?string $username, ?string $passwordHash): ?array
    {
        $fields = ['updated_at = CURRENT_TIMESTAMP'];
        $params = ['id' => $id];
        if ($username !== null) {
            $fields[] = 'username = :username';
            $params['username'] = $username;
        }
        if ($passwordHash !== null) {
            $fields[] = 'password_hash = :hash';
            $params['hash'] = $passwordHash;
        }
        $stmt = self::db()->prepare(
            'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING ' . self::PUBLIC_COLUMNS
        );
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public static function delete(int $id): void
    {
        self::db()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }

    public static function touchLogin(int $id): void
    {
        self::db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $id]);
    }

    public static function toPublic(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }
}
