-- JR CONECT ACS - Security hardening / 2FA
-- Execute uma única vez no banco de produção.

ALTER TABLE users
    ADD COLUMN two_factor_secret VARCHAR(255) NULL AFTER password,
    ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER two_factor_secret,
    ADD COLUMN two_factor_recovery TEXT NULL AFTER two_factor_enabled,
    ADD COLUMN two_factor_confirmed_at DATETIME NULL AFTER two_factor_recovery;

CREATE TABLE IF NOT EXISTS security_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(64) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_security_login_user_time (username, created_at),
    INDEX idx_security_login_ip_time (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
