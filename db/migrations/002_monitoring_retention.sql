CREATE INDEX IF NOT EXISTS `metrics_host_created_idx` ON `vbox_metrics` (`hostname`, `created_at`);
CREATE INDEX IF NOT EXISTS `alarm_status_idx` ON `vbox_alarm_events` (`status`, `opened_at`);
