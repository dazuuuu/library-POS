-- 045_attendance_settings.sql
-- Owner-configured attendance times, late clock-in flagging, and automatic
-- clock-out at the configured end time.

CREATE TABLE IF NOT EXISTS staff_attendance_settings (
    tenant_id INT NOT NULL,
    clock_in_time TIME NOT NULL DEFAULT '08:00:00',
    clock_out_time TIME NOT NULL DEFAULT '18:00:00',
    late_grace_minutes INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE staff_time_logs
    ADD COLUMN late_clock_in TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_closed;
