ALTER TABLE `users`
  ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `email`,
  ADD COLUMN `email_verification_token` VARCHAR(255) DEFAULT NULL AFTER `email_verified`,
  ADD COLUMN `reset_token` VARCHAR(255) DEFAULT NULL AFTER `email_verification_token`,
  ADD COLUMN `reset_token_expires` DATETIME DEFAULT NULL AFTER `reset_token`;

ALTER TABLE `users` ADD KEY `idx_email_verification_token` (`email_verification_token`);
ALTER TABLE `users` ADD KEY `idx_reset_token` (`reset_token`);
  