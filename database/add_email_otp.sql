ALTER TABLE `users`
  ADD COLUMN `email_otp` VARCHAR(10) DEFAULT NULL AFTER `email_verification_token`,
  ADD COLUMN `email_otp_expires` DATETIME DEFAULT NULL AFTER `email_otp`,
  ADD COLUMN `reset_otp` VARCHAR(10) DEFAULT NULL AFTER `reset_token_expires`,
  ADD COLUMN `reset_otp_expires` DATETIME DEFAULT NULL AFTER `reset_otp`;

ALTER TABLE `users` ADD KEY `idx_email_otp` (`email_otp`);
ALTER TABLE `users` ADD KEY `idx_reset_otp` (`reset_otp`);
