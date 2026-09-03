-- Password reset tokens
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pr_member (member_id),
    KEY idx_pr_token (token_hash),
    KEY idx_pr_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
