-- 003 operations: battery policy, maintenance and firmware lifecycle.

-- ---------------------------------------------------------- battery

-- battery_level was stored from the start and never consulted when booking.
-- min_battery_level is per-task: a 3-hour patrol needs more headroom than a
-- 15-minute data sync.
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS min_battery_level INT NOT NULL DEFAULT 20
    CHECK (min_battery_level >= 0 AND min_battery_level <= 100);

-- ------------------------------------------------------ maintenance

-- The baseline maintenance_logs table could not record who did the work, what
-- kind it was, or whether it is still open.
ALTER TABLE maintenance_logs ADD COLUMN IF NOT EXISTS performed_by INT REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE maintenance_logs ADD COLUMN IF NOT EXISTS kind VARCHAR(30) NOT NULL DEFAULT 'repair';
ALTER TABLE maintenance_logs ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'completed';
ALTER TABLE maintenance_logs ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP;

CREATE INDEX IF NOT EXISTS idx_maintenance_robot ON maintenance_logs (robot_id, performed_at DESC);

-- -------------------------------------------------------- firmware

-- Which robots actually received a given firmware release.
CREATE TABLE IF NOT EXISTS robot_firmware_updates (
    id SERIAL PRIMARY KEY,
    robot_id INT NOT NULL REFERENCES robots(id) ON DELETE CASCADE,
    firmware_update_id INT NOT NULL REFERENCES firmware_updates(id) ON DELETE CASCADE,
    previous_version VARCHAR(20),
    applied_by INT REFERENCES users(id) ON DELETE SET NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (robot_id, firmware_update_id)
);

CREATE INDEX IF NOT EXISTS idx_robot_firmware_robot ON robot_firmware_updates (robot_id);

-- ------------------------------------------------- supporting indexes

CREATE INDEX IF NOT EXISTS idx_robots_status ON robots (status);
CREATE INDEX IF NOT EXISTS idx_robots_type ON robots (type);
CREATE INDEX IF NOT EXISTS idx_robot_capabilities_cap ON robot_capabilities (capability_id);
CREATE INDEX IF NOT EXISTS idx_robot_arenas_arena ON robot_arenas (arena_id);
CREATE INDEX IF NOT EXISTS idx_robot_departments_dept ON robot_departments (department_id);
CREATE INDEX IF NOT EXISTS idx_schedules_status ON schedules (status);
