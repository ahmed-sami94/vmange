ALTER TABLE `vbox_notification_deliveries`
  ADD COLUMN IF NOT EXISTS `subject` varchar(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `body` text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `attempts` int NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `next_attempt_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` datetime DEFAULT NULL;
