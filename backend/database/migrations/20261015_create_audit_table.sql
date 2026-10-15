CREATE TABLE IF NOT EXISTS audit (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id   INT UNSIGNED NULL,
    details     TEXT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_user_created (user_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action_created (action, created_at)
) ENGINE=InnoDB;
