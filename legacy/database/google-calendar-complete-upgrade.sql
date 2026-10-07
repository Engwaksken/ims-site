-- IMS Events + Activities Google Calendar integration
-- Run once after backing up the database.

-- hub_events
ALTER TABLE hub_events
    ADD COLUMN IF NOT EXISTS google_event_id VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS google_html_link TEXT NULL,
    ADD COLUMN IF NOT EXISTS google_sync_status VARCHAR(50) NOT NULL DEFAULT 'Not Synced',
    ADD COLUMN IF NOT EXISTS google_sync_error TEXT NULL,
    ADD COLUMN IF NOT EXISTS google_last_synced_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS meeting_link TEXT NULL,
    ADD COLUMN IF NOT EXISTS timezone VARCHAR(100) NULL DEFAULT 'Africa/Kampala';

-- workplan activities
ALTER TABLE workplan_deliverables
    ADD COLUMN IF NOT EXISTS google_event_id VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS google_html_link TEXT NULL,
    ADD COLUMN IF NOT EXISTS google_sync_status VARCHAR(50) NOT NULL DEFAULT 'Not Synced',
    ADD COLUMN IF NOT EXISTS google_sync_error TEXT NULL,
    ADD COLUMN IF NOT EXISTS google_last_synced_at DATETIME NULL;

-- OAuth connection state defaults
INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'google_calendar_connection_status', 'Disconnected', NULL
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
