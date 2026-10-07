-- Google Calendar OAuth runtime state
-- Run once.

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'google_calendar_connection_status', 'Not Connected', NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM system_settings
    WHERE setting_key = 'google_calendar_connection_status'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'google_access_token', '', NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM system_settings
    WHERE setting_key = 'google_access_token'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'google_refresh_token', '', NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM system_settings
    WHERE setting_key = 'google_refresh_token'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'google_access_token_expires_at', '0', NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM system_settings
    WHERE setting_key = 'google_access_token_expires_at'
);

INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'google_calendar_connected_at', '', NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM system_settings
    WHERE setting_key = 'google_calendar_connected_at'
);
