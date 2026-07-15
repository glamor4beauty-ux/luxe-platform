USE luxe_talent;

CREATE TABLE IF NOT EXISTS tasks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    assigned_email  VARCHAR(255) NOT NULL,
    priority        VARCHAR(20) NOT NULL DEFAULT 'daily' COMMENT 'daily=green, ongoing=yellow, urgent=red',
    task_date       DATE NOT NULL,
    complete_date   DATE NULL,
    result          VARCHAR(255) NULL COMMENT 'completed, cancelled, in_progress, pending',
    description     TEXT NOT NULL,
    created_by      VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_performer FOREIGN KEY (assigned_email) REFERENCES registration(email) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_date (task_date),
    INDEX idx_priority (priority),
    INDEX idx_assigned (assigned_email)
) ENGINE=InnoDB;
