-- Login accounts (replaces AUTH_USERNAME / AUTH_PASSWORD_HASH from the environment).
-- All users see the same data; the table only controls who can log in.
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP
);

-- Usernames are unique ignoring case ("Admin" and "admin" are the same login)
CREATE UNIQUE INDEX idx_users_username ON users (LOWER(username));

-- Sessions now belong to a user. Sessions from the old environment login are dropped,
-- so everyone logs in once more after this deploy.
DELETE FROM sessions;
ALTER TABLE sessions ADD COLUMN user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE sessions DROP COLUMN auth_version;
CREATE INDEX idx_sessions_user ON sessions(user_id);
