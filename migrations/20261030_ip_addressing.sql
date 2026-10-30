-- JR CONECT ACS: lightweight IPv4 address management
-- Run once against the existing ACS database before opening ip-addressing.php.

CREATE TABLE IF NOT EXISTS `ip_networks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cidr` varchar(18) NOT NULL,
  `description` varchar(160) NOT NULL,
  `network_type` varchar(60) DEFAULT NULL,
  `location` varchar(120) DEFAULT NULL,
  `gateway` varchar(15) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip_network_cidr` (`cidr`),
  KEY `idx_ip_network_status` (`status`),
  CONSTRAINT `fk_ip_network_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ip_addresses` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `network_id` int unsigned NOT NULL,
  `address` varchar(15) NOT NULL,
  `hostname` varchar(160) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL,
  `status` enum('in_use','reserved') NOT NULL DEFAULT 'in_use',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip_address_network` (`network_id`,`address`),
  KEY `idx_ip_address_address` (`address`),
  KEY `idx_ip_address_status` (`status`),
  CONSTRAINT `fk_ip_address_network` FOREIGN KEY (`network_id`) REFERENCES `ip_networks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ip_address_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ip_addressing_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `network_id` int unsigned DEFAULT NULL,
  `address` varchar(15) DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_history_network` (`network_id`,`created_at`),
  KEY `idx_ip_history_address` (`address`),
  CONSTRAINT `fk_ip_history_network` FOREIGN KEY (`network_id`) REFERENCES `ip_networks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ip_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
