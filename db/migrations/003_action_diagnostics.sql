ALTER TABLE `vbox_commands`
  MODIFY `status` enum('pending','sent','running','done','failed','expired') NOT NULL DEFAULT 'pending';
