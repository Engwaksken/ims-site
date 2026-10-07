-- Automatic backup and Google Drive storage defaults

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'backup_auto_enabled', '0', NULL
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings
    WHERE setting_key = 'backup_auto_enabled'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'backup_frequency', 'daily', NULL
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings
    WHERE setting_key = 'backup_frequency'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'backup_time', '02:00', NULL
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings
    WHERE setting_key = 'backup_time'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'backup_weekday', '1', NULL
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings
    WHERE setting_key = 'backup_weekday'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'backup_month_day', '1', NULL
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings
    WHERE setting_key = 'backup_month_day'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'backup_retention_count', '14', NULL
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings
    WHERE setting_key = 'backup_retention_count'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'backup_storage_destination', 'local', NULL
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings
    WHERE setting_key = 'backup_storage_destination'
);
