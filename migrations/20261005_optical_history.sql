CREATE TABLE IF NOT EXISTS onu_optical_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    serial_number VARCHAR(128) NOT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'IXC',
    sample_key CHAR(64) NOT NULL,
    sample_at DATETIME NOT NULL,
    measured_at DATETIME DEFAULT NULL,
    collected_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    rx_power DECIMAL(7,3) NOT NULL,
    tx_power DECIMAL(7,3) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_optical_sample (sample_key),
    KEY idx_optical_serial_time (serial_number, sample_at),
    KEY idx_optical_retention (sample_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
