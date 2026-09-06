-- =====================================================================
-- FamPay Payment Gateway v2.0 - PostgreSQL schema
-- Author: @lazzy_guy
-- Safe to run multiple times (IF NOT EXISTS everywhere).
-- =====================================================================

-- 1. Orders -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    order_id VARCHAR(50) UNIQUE NOT NULL,
    upi_id VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    qr_code_url TEXT,
    qr_code_base64 TEXT,
    qr_has_logo BOOLEAN DEFAULT TRUE,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'success', 'failed', 'expired')),
    utr_number VARCHAR(50),
    payer_name VARCHAR(100),
    payer_upi VARCHAR(100),
    payment_date TIMESTAMP,
    payment_details JSONB,
    api_key VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_order_id   ON orders(order_id);
CREATE INDEX IF NOT EXISTS idx_status     ON orders(status);
CREATE INDEX IF NOT EXISTS idx_created_at ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_utr        ON orders(utr_number);

-- 2. Gmail API keys ---------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id SERIAL PRIMARY KEY,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    gmail VARCHAR(100) NOT NULL,
    app_password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_used TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_api_key ON api_keys(api_key);
CREATE INDEX IF NOT EXISTS idx_gmail   ON api_keys(gmail);

-- 3. Master API keys --------------------------------------------------
CREATE TABLE IF NOT EXISTS master_keys (
    id SERIAL PRIMARY KEY,
    master_key VARCHAR(64) UNIQUE NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    created_by VARCHAR(50) DEFAULT 'admin',
    is_active BOOLEAN DEFAULT TRUE,
    usage_count INTEGER DEFAULT 0,
    last_used TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_master_key ON master_keys(master_key);

-- 4. Payment / API logs ----------------------------------------------
CREATE TABLE IF NOT EXISTS payment_logs (
    id SERIAL PRIMARY KEY,
    order_id VARCHAR(50) NOT NULL,
    api_key VARCHAR(64),
    action VARCHAR(50) NOT NULL,
    request_data TEXT,
    response_data TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_order_log ON payment_logs(order_id);
CREATE INDEX IF NOT EXISTS idx_action    ON payment_logs(action);

-- Keep orders.updated_at fresh -----------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_orders_updated_at ON orders;
CREATE TRIGGER trg_orders_updated_at
    BEFORE UPDATE ON orders
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
