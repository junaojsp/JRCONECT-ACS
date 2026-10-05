CREATE TABLE IF NOT EXISTS device_action_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id VARCHAR(512) NOT NULL,
    device_key CHAR(64) NOT NULL,
    actor VARCHAR(100) NOT NULL,
    user_id INT DEFAULT NULL,
    action VARCHAR(32) NOT NULL,
    fields_changed VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(24) NOT NULL,
    task_id VARCHAR(64) DEFAULT NULL,
    http_code SMALLINT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_action_device (device_key, id),
    KEY idx_action_pending (status, task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
