USE luxe_talent;

CREATE TABLE IF NOT EXISTS messages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    from_email  VARCHAR(255) NOT NULL,
    to_email    VARCHAR(255) NOT NULL,
    subject     VARCHAR(255) NULL,
    body        TEXT NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_to (to_email, is_read),
    INDEX idx_from (from_email),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;
