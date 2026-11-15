-- Failed login attempts, used to lock out brute force guessing.
-- Kept in PostgreSQL (not Redis) so the protection still works if the cache is down.
-- client_hash is the SHA-256 of the client IP: no raw IPs are stored.
CREATE TABLE login_failures (
    id SERIAL PRIMARY KEY,
    client_hash CHAR(64) NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_login_failures_client ON login_failures(client_hash, failed_at);
