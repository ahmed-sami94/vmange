ALTER TABLE `vbox_hosts`
  ADD COLUMN IF NOT EXISTS `host_uuid` char(36) DEFAULT NULL;

ALTER TABLE `vbox_commands`
  ADD COLUMN IF NOT EXISTS `lease_token_hash` char(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `lease_expires_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `attempts` int NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `progress_message` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `progress_percent` tinyint DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `error_code` varchar(64) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `host_uuid_idx` ON `vbox_hosts` (`host_uuid`);
CREATE INDEX IF NOT EXISTS `lease_idx` ON `vbox_commands` (`hostname`, `status`, `lease_expires_at`);
