-- Hive NSP <-> IMS integration (nsp-platform docs/integration-contract-v1.md).
-- Outbox/inbox tables and the NSP link columns on applications.
-- Idempotent: safe to rerun. The code also creates these lazily when
-- NSP_INTEGRATION_ENABLED=true, and disables the integration if it cannot.

CREATE TABLE IF NOT EXISTS nsp_outbox (
    event_id CHAR(36) NOT NULL PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    payload LONGTEXT NOT NULL,
    subject_uuid CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    dead_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    KEY idx_nsp_outbox_due (sent_at, dead_at, next_attempt_at),
    KEY idx_nsp_outbox_subject (subject_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nsp_inbox (
    event_id CHAR(36) NOT NULL PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    subject_uuid CHAR(36) NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL,
    note VARCHAR(1000) NULL,
    KEY idx_nsp_inbox_subject (subject_uuid, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @nsp_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='applications' AND COLUMN_NAME='registry_startup_uuid'),
    'SELECT 1',
    'ALTER TABLE applications ADD COLUMN registry_startup_uuid CHAR(36) NULL'
);
PREPARE nsp_stmt FROM @nsp_ddl; EXECUTE nsp_stmt; DEALLOCATE PREPARE nsp_stmt;

SET @nsp_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='applications' AND COLUMN_NAME='nsp_snapshot'),
    'SELECT 1',
    'ALTER TABLE applications ADD COLUMN nsp_snapshot LONGTEXT NULL'
);
PREPARE nsp_stmt FROM @nsp_ddl; EXECUTE nsp_stmt; DEALLOCATE PREPARE nsp_stmt;

SET @nsp_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='applications' AND COLUMN_NAME='nsp_snapshot_at'),
    'SELECT 1',
    'ALTER TABLE applications ADD COLUMN nsp_snapshot_at DATETIME NULL'
);
PREPARE nsp_stmt FROM @nsp_ddl; EXECUTE nsp_stmt; DEALLOCATE PREPARE nsp_stmt;

SET @nsp_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='applications' AND COLUMN_NAME='nsp_consent_revoked_at'),
    'SELECT 1',
    'ALTER TABLE applications ADD COLUMN nsp_consent_revoked_at DATETIME NULL'
);
PREPARE nsp_stmt FROM @nsp_ddl; EXECUTE nsp_stmt; DEALLOCATE PREPARE nsp_stmt;

SET @nsp_ddl = IF(
    EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='applications' AND INDEX_NAME='idx_applications_registry_startup_uuid'),
    'SELECT 1',
    'CREATE INDEX idx_applications_registry_startup_uuid ON applications (registry_startup_uuid)'
);
PREPARE nsp_stmt FROM @nsp_ddl; EXECUTE nsp_stmt; DEALLOCATE PREPARE nsp_stmt;
