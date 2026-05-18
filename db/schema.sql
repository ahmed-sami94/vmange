SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `vbox_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','operator','viewer') NOT NULL DEFAULT 'admin',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_hosts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hostname` varchar(100) NOT NULL,
  `all_vms` text DEFAULT NULL,
  `running_vms` text DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL,
  `vm_specs` longtext DEFAULT NULL,
  `vm_inventory_json` longtext DEFAULT NULL,
  `runtime_status_json` longtext DEFAULT NULL,
  `metrics_json` longtext DEFAULT NULL,
  `containers_json` longtext DEFAULT NULL,
  `compose_json` longtext DEFAULT NULL,
  `images_json` longtext DEFAULT NULL,
  `capabilities_json` longtext DEFAULT NULL,
  `collector_errors_json` longtext DEFAULT NULL,
  `wol_mac` varchar(32) DEFAULT NULL,
  `wol_broadcast` varchar(64) DEFAULT NULL,
  `wol_port` int(11) NOT NULL DEFAULT 9,
  `wol_relay_host` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `hostname` (`hostname`),
  KEY `last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hostname` varchar(100) NOT NULL,
  `action` varchar(64) NOT NULL,
  `vmname` varchar(255) NOT NULL,
  `payload` longtext DEFAULT NULL,
  `status` enum('pending','sent','running','done','failed','expired') NOT NULL DEFAULT 'pending',
  `requested_by` varchar(100) DEFAULT NULL,
  `result` text DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `exit_code` int DEFAULT NULL,
  `stdout` mediumtext DEFAULT NULL,
  `stderr` mediumtext DEFAULT NULL,
  `diagnostics_json` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `host_status` (`hostname`,`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_metrics` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `hostname` varchar(100) NOT NULL,
  `cpu_percent` decimal(5,2) NOT NULL DEFAULT 0,
  `load1` decimal(8,2) NOT NULL DEFAULT 0,
  `ram_used_mb` int(11) NOT NULL DEFAULT 0,
  `ram_total_mb` int(11) NOT NULL DEFAULT 0,
  `swap_used_mb` int(11) NOT NULL DEFAULT 0,
  `swap_total_mb` int(11) NOT NULL DEFAULT 0,
  `disk_used_mb` int(11) NOT NULL DEFAULT 0,
  `disk_total_mb` int(11) NOT NULL DEFAULT 0,
  `rx_bytes` bigint(20) NOT NULL DEFAULT 0,
  `tx_bytes` bigint(20) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `host_time` (`hostname`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_host_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hostname` varchar(100) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `rotated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `host_active` (`hostname`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_host_blocks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hostname` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `hostname` (`hostname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_audit_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target` varchar(255) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_rate_limits` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `rate_key` char(64) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `rate_key` (`rate_key`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_compose_stacks` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `hostname` varchar(100) NOT NULL,
  `project` varchar(100) NOT NULL,
  `compose_yaml` longtext NOT NULL,
  `status` varchar(64) NOT NULL DEFAULT 'saved',
  `last_action` varchar(64) DEFAULT NULL,
  `last_result` text DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `host_project` (`hostname`,`project`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_scripts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `body` longtext NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_script_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `script_id` bigint(20) NOT NULL,
  `hostname` varchar(100) NOT NULL,
  `command_id` bigint(20) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `result` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `script_host` (`script_id`,`hostname`),
  KEY `command_id` (`command_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_settings` (
  `setting_key` varchar(120) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_alarm_rules` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `metric` varchar(64) NOT NULL,
  `operator` varchar(8) NOT NULL DEFAULT '>=',
  `threshold` decimal(10,2) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notify_email` varchar(255) DEFAULT NULL,
  `cooldown_minutes` int(11) NOT NULL DEFAULT 15,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_alarm_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `rule_id` bigint(20) NOT NULL,
  `hostname` varchar(100) NOT NULL,
  `metric_value` decimal(12,2) NOT NULL DEFAULT 0,
  `status` enum('active','resolved','acknowledged') NOT NULL DEFAULT 'active',
  `message` text DEFAULT NULL,
  `opened_at` datetime DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `last_notified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rule_host_status` (`rule_id`,`hostname`,`status`),
  KEY `opened_at` (`opened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vbox_notification_deliveries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `alarm_event_id` bigint(20) DEFAULT NULL,
  `channel` varchar(32) NOT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `result` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `alarm_event_id` (`alarm_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `vbox_users`
  ADD COLUMN IF NOT EXISTS `role` enum('admin','operator','viewer') NOT NULL DEFAULT 'admin';

ALTER TABLE `vbox_hosts`
  ADD COLUMN IF NOT EXISTS `metrics_json` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `vm_inventory_json` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `runtime_status_json` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `containers_json` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `compose_json` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `images_json` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `capabilities_json` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `collector_errors_json` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `wol_mac` varchar(32) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `wol_broadcast` varchar(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `wol_port` int(11) NOT NULL DEFAULT 9,
  ADD COLUMN IF NOT EXISTS `wol_relay_host` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `created_at` datetime DEFAULT current_timestamp();

ALTER TABLE `vbox_commands`
  MODIFY `action` varchar(64) NOT NULL,
  MODIFY `status` enum('pending','sent','running','done','failed','expired') NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS `payload` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `requested_by` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `result` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `started_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `finished_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `exit_code` int DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `stdout` mediumtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `stderr` mediumtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `diagnostics_json` longtext DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` datetime DEFAULT NULL;

ALTER TABLE `vbox_commands`
  MODIFY `status` enum('pending','sent','running','done','failed','expired') NOT NULL DEFAULT 'pending';

COMMIT;
