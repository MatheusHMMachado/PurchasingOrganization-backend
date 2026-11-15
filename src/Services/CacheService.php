<?php

namespace App\Services;

use Predis\Client as RedisClient;
use Exception;

class CacheService
{
    private static ?CacheService $instance = null;
    private ?RedisClient $client = null;
    private string $prefix;
    private bool $available = false;

    private function __construct()
    {
        $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
        $port = $_ENV['REDIS_PORT'] ?? '6379';
        $this->prefix = $_ENV['REDIS_PREFIX'] ?? 'pt:';

        $params = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => (int) $port,
            'read_write_timeout' => 2,
        ];
        // Railway's Redis requires auth; local Docker Redis has none
        if (!empty($_ENV['REDIS_PASSWORD'])) {
            $params['password'] = $_ENV['REDIS_PASSWORD'];
            if (!empty($_ENV['REDIS_USER'])) {
                $params['username'] = $_ENV['REDIS_USER'];
            }
        }

        try {
            $this->client = new RedisClient($params);
            // Test connection
            $this->client->ping();
            $this->available = true;
        } catch (Exception $e) {
            $this->client = null;
            $this->available = false;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get a cached value by key.
     */
    public function get(string $key): mixed
    {
        if (!$this->available) {
            return null;
        }

        try {
            $value = $this->client->get($this->prefix . $key);
            if ($value === null) {
                return null;
            }
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Set a cached value with optional TTL (in seconds).
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->available) {
            return false;
        }

        try {
            $serialized = is_string($value) ? $value : json_encode($value);
            if ($ttl > 0) {
                $this->client->setex($this->prefix . $key, $ttl, $serialized);
            } else {
                $this->client->set($this->prefix . $key, $serialized);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete a cached value by key.
     */
    public function delete(string $key): bool
    {
        if (!$this->available) {
            return false;
        }

        try {
            $this->client->del([$this->prefix . $key]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Delete all keys matching a pattern.
     */
    public function deleteByPattern(string $pattern): bool
    {
        if (!$this->available) {
            return false;
        }

        try {
            // SCAN walks the keyspace in small steps; KEYS blocks Redis while it reads every key
            $batch = [];
            foreach (new \Predis\Collection\Iterator\Keyspace($this->client, $this->prefix . $pattern, 200) as $key) {
                $batch[] = $key;
                if (count($batch) >= 200) {
                    $this->client->del($batch);
                    $batch = [];
                }
            }
            if ($batch) {
                $this->client->del($batch);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Atomically increments a counter, setting its TTL on creation.
     * Returns the new value, or null when Redis is unavailable.
     */
    public function increment(string $key, int $ttl): ?int
    {
        if (!$this->available) {
            return null;
        }

        try {
            $fullKey = $this->prefix . $key;
            $value = (int) $this->client->incr($fullKey);
            if ($value === 1) {
                $this->client->expire($fullKey, $ttl);
            }
            return $value;
        } catch (Exception $e) {
            return null;
        }
    }
}
