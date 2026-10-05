CREATE TABLE IF NOT EXISTS device_action_verification (
    action_id BIGINT UNSIGNED NOT NULL,
    expected_json TEXT NOT NULL,
    excluded_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    read_started_ms BIGINT UNSIGNED NOT NULL,
    read_accepted TINYINT NOT NULL DEFAULT 0,
    read_task_id VARCHAR(64) DEFAULT NULL,
    matched_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    checked_at DATETIME DEFAULT NULL,
    PRIMARY KEY (action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
