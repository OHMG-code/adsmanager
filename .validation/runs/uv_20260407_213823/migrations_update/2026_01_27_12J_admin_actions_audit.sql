CREATE TABLE IF NOT EXISTS admin_actions_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(80) NULL,
    action VARCHAR(50) NOT NULL,
    scope VARCHAR(20) NOT NULL,
    filters_json TEXT NULL,
    ids_json LONGTEXT NULL,
    affected_count INT NOT NULL DEFAULT 0,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_actions_action (action),
    INDEX idx_admin_actions_created_at (created_at)
);
