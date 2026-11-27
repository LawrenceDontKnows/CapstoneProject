-- GradeView Online: desktop sync helper support
-- Run once on the Railway MySQL database (phpMyAdmin / Railway Query).

CREATE TABLE IF NOT EXISTS sync_devices (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id   INT NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    label        VARCHAR(120) NOT NULL DEFAULT '',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    UNIQUE KEY uniq_token (token_hash),
    KEY idx_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;