-- Login sessions (one shared login for the whole site).
-- Only the SHA-256 of the session token is stored: a database leak does not expose
-- usable session cookies.
CREATE TABLE sessions (
    id SERIAL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    -- 'web': browser tab, absolute 24h lifetime
    -- 'pwa': installed app, renewed on use (does not expire while the app is used)
    client VARCHAR(10) NOT NULL CHECK (client IN ('web', 'pwa')),
    -- Fingerprint of the configured credentials: changing the password ends every session
    auth_version CHAR(16) NOT NULL,
    user_agent VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL
);

CREATE INDEX idx_sessions_expires_at ON sessions(expires_at);
