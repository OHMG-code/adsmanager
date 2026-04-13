CREATE TABLE IF NOT EXISTS gus_refresh_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    priority TINYINT NOT NULL DEFAULT 5,
    reason VARCHAR(50) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(50) NULL,
    last_error_code VARCHAR(50) NULL,
    last_error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_queue_status_next (status, next_run_at, priority),
    INDEX idx_queue_company (company_id)
);

-- Partial unique not portable; enforce in code.
