CREATE TABLE batches (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'completed', 'cancelled')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE items (
    id SERIAL PRIMARY KEY,
    batch_id INT NOT NULL REFERENCES batches(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'found', 'purchased')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE offers (
    id SERIAL PRIMARY KEY,
    item_id INT NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    ecommerce VARCHAR(100) NOT NULL,
    price NUMERIC(10,2) NOT NULL,
    link TEXT NOT NULL,
    found_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_items_batch_id ON items(batch_id);
CREATE INDEX idx_offers_item_id ON offers(item_id);
CREATE INDEX idx_offers_price ON offers(price);
