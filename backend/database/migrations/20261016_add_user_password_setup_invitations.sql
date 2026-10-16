ALTER TABLE users
    ADD COLUMN password_setup_required TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

CREATE TABLE user_setup_invitations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    token_hash      CHAR(64)        NOT NULL,
    expires_at      DATETIME        NOT NULL,
    used_at         DATETIME        NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_setup_invitations_token (token_hash),
    INDEX idx_user_setup_invitations_user (user_id, used_at),
    CONSTRAINT fk_user_setup_invitations_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
