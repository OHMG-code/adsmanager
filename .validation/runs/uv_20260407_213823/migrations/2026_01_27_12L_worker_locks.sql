CREATE TABLE IF NOT EXISTS worker_locks (
    name VARCHAR(50) PRIMARY KEY,
    locked_by VARCHAR(120) NULL,
    locked_at DATETIME NULL,
    heartbeat_at DATETIME NULL,
    expires_at DATETIME NULL,
    meta_json TEXT NULL
);
