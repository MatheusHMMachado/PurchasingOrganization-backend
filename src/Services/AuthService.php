<?php

namespace App\Services;

use App\Models\User;
use PDO;

/**
 * Login with accounts from the users table, and server-side sessions.
 *
 * Sessions:
 *   web  absolute lifetime of 24h from login (never extended)
 *   pwa  365 days, renewed while the installed app is used, so it effectively never ends
 */
final class AuthService
{
    public const COOKIE = 'po_session';
    public const WEB_TTL = 86400;              // 1 day
    public const PWA_TTL = 365 * 86400;        // renewed on use
    public const PWA_COOKIE_TTL = 400 * 86400; // browsers cap cookie lifetime at 400 days
    private const PWA_RENEW_AFTER = 86400;     // renew at most once a day (limits DB writes)

    /**
     * Returns the user when username and password match, otherwise null.
     * Unknown usernames still run password_verify against a dummy hash, so the response
     * time does not reveal which usernames exist.
     */
    public static function verifyCredentials(string $username, string $password): ?array
    {
        $user = User::findByUsername($username);
        $hash = $user['password_hash'] ?? self::dummyHash();
        $valid = password_verify($password, $hash);

        if (!$user || !$valid) {
            return null;
        }
        // Keep hashes on the current algorithm/cost
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            User::update((int) $user['id'], null, password_hash($password, PASSWORD_DEFAULT));
        }
        return $user;
    }

    /** Checks the password of an already known user (re-authentication). */
    public static function verifyPassword(int $userId, string $password): bool
    {
        $user = User::findById($userId);
        return password_verify($password, $user['password_hash'] ?? self::dummyHash()) && $user !== null;
    }

    /** Creates a session and returns [token, session row with username]. */
    public static function createSession(int $userId, string $client, string $userAgent): array
    {
        $pdo = self::pdo();
        // Housekeeping: drop expired sessions
        $pdo->exec('DELETE FROM sessions WHERE expires_at < CURRENT_TIMESTAMP');

        $token = bin2hex(random_bytes(32));
        $ttl = $client === 'pwa' ? self::PWA_TTL : self::WEB_TTL;

        $stmt = $pdo->prepare(
            "INSERT INTO sessions (token_hash, user_id, client, user_agent, expires_at)
             VALUES (:hash, :user, :client, :ua, CURRENT_TIMESTAMP + make_interval(secs => :ttl))
             RETURNING id"
        );
        $stmt->execute([
            'hash' => hash('sha256', $token),
            'user' => $userId,
            'client' => $client,
            'ua' => mb_substr($userAgent, 0, 255),
            'ttl' => $ttl,
        ]);
        User::touchLogin($userId);
        return [$token, self::findSession((int) $stmt->fetchColumn())];
    }

    /**
     * Returns the active session (with username) for a cookie token, or null. PWA sessions
     * are renewed here; the second value tells the caller to re-send the cookie.
     *
     * @return array{0: ?array, 1: bool}
     */
    public static function resolve(?string $token): array
    {
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return [null, false];
        }
        $stmt = self::pdo()->prepare(
            "SELECT s.*, u.username, EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - s.last_used_at)) AS idle_seconds
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :hash AND s.expires_at > CURRENT_TIMESTAMP"
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $session = $stmt->fetch();
        if (!$session) {
            return [null, false];
        }

        if ($session['client'] === 'pwa' && (float) $session['idle_seconds'] > self::PWA_RENEW_AFTER) {
            self::pdo()->prepare(
                "UPDATE sessions
                 SET last_used_at = CURRENT_TIMESTAMP,
                     expires_at = CURRENT_TIMESTAMP + make_interval(secs => :ttl)
                 WHERE id = :id"
            )->execute(['ttl' => self::PWA_TTL, 'id' => $session['id']]);
            return [self::findSession((int) $session['id']), true];
        }
        return [$session, false];
    }

    public static function destroy(?string $token): void
    {
        if (!$token) {
            return;
        }
        self::pdo()->prepare('DELETE FROM sessions WHERE token_hash = :hash')
            ->execute(['hash' => hash('sha256', $token)]);
    }

    /** Ends every session of a user, optionally keeping one (the device making the change). */
    public static function destroyUserSessions(int $userId, ?int $exceptSessionId = null): void
    {
        self::pdo()->prepare('DELETE FROM sessions WHERE user_id = :user AND id <> :except')
            ->execute(['user' => $userId, 'except' => $exceptSessionId ?? 0]);
    }

    /** Failed logins of a client (SHA-256 of its IP) in the last $windowSeconds. */
    public static function recentFailures(string $clientHash, int $windowSeconds): int
    {
        $stmt = self::pdo()->prepare(
            "SELECT COUNT(*) FROM login_failures
             WHERE client_hash = :client AND failed_at > CURRENT_TIMESTAMP - make_interval(secs => :window)"
        );
        $stmt->execute(['client' => $clientHash, 'window' => $windowSeconds]);
        return (int) $stmt->fetchColumn();
    }

    public static function recordFailure(string $clientHash): void
    {
        $pdo = self::pdo();
        $pdo->prepare('INSERT INTO login_failures (client_hash) VALUES (:client)')->execute(['client' => $clientHash]);
        // Housekeeping: keep only the last day
        $pdo->exec("DELETE FROM login_failures WHERE failed_at < CURRENT_TIMESTAMP - interval '1 day'");
    }

    public static function clearFailures(string $clientHash): void
    {
        self::pdo()->prepare('DELETE FROM login_failures WHERE client_hash = :client')->execute(['client' => $clientHash]);
    }

    /** Set-Cookie value for a session (or for clearing it when $token is null). */
    public static function cookie(?string $token, string $client, bool $secure): string
    {
        $parts = [self::COOKIE . '=' . ($token ?? ''), 'Path=/', 'HttpOnly', 'SameSite=Strict'];
        $parts[] = $token === null
            ? 'Max-Age=0'
            : 'Max-Age=' . ($client === 'pwa' ? self::PWA_COOKIE_TTL : self::WEB_TTL);
        if ($secure) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    /** Public view of a session for the frontend. */
    public static function describe(array $session): array
    {
        return [
            'authenticated' => true,
            'user_id' => (int) $session['user_id'],
            'username' => $session['username'],
            'client' => $session['client'],
            'expires_at' => gmdate('c', strtotime($session['expires_at'] . ' UTC') ?: time()),
        ];
    }

    private static function findSession(int $id): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT s.*, u.username FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash('dummy-password-for-timing', PASSWORD_DEFAULT);
    }

    private static function pdo(): PDO
    {
        return DatabaseService::getInstance()->getConnection();
    }
}
