<?php

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Payload validation for batches, items and offers.
 *
 * Limits match the database columns, so bad input gets a clear 400 instead of a database
 * error. With $partial = true (PUT) only the fields that were sent are checked.
 * Each method returns a list of error messages (empty = valid).
 */
final class Validator
{
    public const BATCH_STATUSES = ['active', 'completed', 'cancelled'];
    public const ITEM_STATUSES = ['pending', 'found', 'purchased'];
    public const MAX_PRICE = 99999999.99; // NUMERIC(10,2)

    public static function batch(array $data, bool $partial = false): array
    {
        $errors = [];
        self::text($errors, $data, 'name', 255, !$partial);
        self::text($errors, $data, 'description', 2000, false, true);
        self::oneOf($errors, $data, 'status', self::BATCH_STATUSES);
        return $errors;
    }

    public static function item(array $data, bool $partial = false): array
    {
        $errors = [];
        self::text($errors, $data, 'name', 255, !$partial);
        self::text($errors, $data, 'category', 100, false, true);
        self::oneOf($errors, $data, 'status', self::ITEM_STATUSES);
        return $errors;
    }

    public static function offer(array $data): array
    {
        $errors = [];
        self::text($errors, $data, 'ecommerce', 100, true);

        $price = $data['price'] ?? null;
        if (!is_numeric($price) || (float) $price < 0 || (float) $price > self::MAX_PRICE) {
            $errors[] = 'price is required and must be between 0 and ' . number_format(self::MAX_PRICE, 2, '.', '');
        }

        $link = $data['link'] ?? null;
        if (!is_string($link) || !self::isHttpUrl($link)) {
            $errors[] = 'link is required and must be an http(s) URL';
        } elseif (strlen($link) > 2048) {
            $errors[] = 'link must be at most 2048 characters';
        }
        return $errors;
    }

    /** Login name: 3-50 letters, digits, dot, dash or underscore. */
    public static function username(mixed $value): array
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $value)) {
            return ['username must have 3 to 50 characters (letters, numbers, . _ -)'];
        }
        return [];
    }

    /** Password: 10-200 characters (bcrypt only uses the first 72 bytes). */
    public static function password(mixed $value): array
    {
        if (!is_string($value) || mb_strlen($value) < 10) {
            return ['password must have at least 10 characters'];
        }
        if (strlen($value) > 72) {
            return ['password must have at most 72 bytes'];
        }
        return [];
    }

    /**
     * Only http/https with a host. FILTER_VALIDATE_URL alone accepts javascript:// and data://,
     * which become clickable links in the app.
     */
    public static function isHttpUrl(string $url): bool
    {
        $url = trim($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    /** Positive id that fits a PostgreSQL INTEGER; null when invalid. */
    public static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,8}$/', $value)) {
            return null;
        }
        return (int) $value;
    }

    private static function text(array &$errors, array $data, string $field, int $max, bool $required, bool $nullable = false): void
    {
        if (!array_key_exists($field, $data)) {
            if ($required) {
                $errors[] = "{$field} is required";
            }
            return;
        }
        $value = $data[$field];
        if ($value === null && $nullable) {
            return;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $errors[] = "{$field} must be text";
            return;
        }
        $value = trim((string) $value);
        if ($value === '' && ($required || !$nullable)) {
            $errors[] = "{$field} is required";
        } elseif (mb_strlen($value) > $max) {
            $errors[] = "{$field} must be at most {$max} characters";
        }
    }

    private static function oneOf(array &$errors, array $data, string $field, array $allowed): void
    {
        if (array_key_exists($field, $data) && !in_array($data[$field], $allowed, true)) {
            $errors[] = "{$field} must be one of: " . implode(', ', $allowed);
        }
    }

    /** 400 response listing the validation errors. */
    public static function badRequest(Response $response, array $errors): Response
    {
        $response->getBody()->write(json_encode(['success' => false, 'error' => implode(', ', $errors)]));
        return $response->withStatus(400);
    }
}
