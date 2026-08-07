-- 004 RobotCity: geography, charging infrastructure, duty budgets, media.

-- ------------------------------------------------------- geography

-- Arenas gain a domain (which robot discipline the site serves) and a real
-- position. `type` already described the environment (Indoor/Sterile/...), so
-- domain is a separate axis rather than an overload of it.
ALTER TABLE arenas ADD COLUMN IF NOT EXISTS domain VARCHAR(20) NOT NULL DEFAULT 'general';
ALTER TABLE arenas ADD COLUMN IF NOT EXISTS code VARCHAR(12);
ALTER TABLE arenas ADD COLUMN IF NOT EXISTS latitude  DECIMAL(10, 8);
ALTER TABLE arenas ADD COLUMN IF NOT EXISTS longitude DECIMAL(11, 8);

-- How close a robot must be for the site to be reported as its location.
-- Outside every radius, a robot is "in transit".
ALTER TABLE arenas ADD COLUMN IF NOT EXISTS radius_m INT NOT NULL DEFAULT 180;

-- Charging stations are arenas with domain='charging'; capacity is how many
-- robots can dock at once.
ALTER TABLE arenas ADD COLUMN IF NOT EXISTS capacity INT;

CREATE INDEX IF NOT EXISTS idx_arenas_domain ON arenas (domain);

-- --------------------------------------------------------- duty budget

-- A robot may only be worked for a bounded stretch before it must recharge.
-- The cap is per-robot because heavier platforms drain faster.
ALTER TABLE robots ADD COLUMN IF NOT EXISTS max_duty_minutes INT NOT NULL DEFAULT 270
    CHECK (max_duty_minutes > 0);

-- Consumed by bookings; reset when the robot completes a charge cycle.
ALTER TABLE robots ADD COLUMN IF NOT EXISTS duty_minutes_used INT NOT NULL DEFAULT 0
    CHECK (duty_minutes_used >= 0);

ALTER TABLE robots ADD COLUMN IF NOT EXISTS duty_reset_at TIMESTAMP;

-- Where the robot was sent to charge, once its budget ran out.
ALTER TABLE robots ADD COLUMN IF NOT EXISTS charging_arena_id INT
    REFERENCES arenas(id) ON DELETE SET NULL;

-- -------------------------------------------------------------- media

ALTER TABLE robots ADD COLUMN IF NOT EXISTS image_url VARCHAR(255);
-- Shown on hover; a gif or short video standing in for the robot in motion.
ALTER TABLE robots ADD COLUMN IF NOT EXISTS image_hover_url VARCHAR(255);

-- ---------------------------------------------------------- schedules

-- A single booking is capped at three hours. Enforced in the database as well
-- as the application so a direct SQL insert cannot bypass it.
ALTER TABLE schedules DROP CONSTRAINT IF EXISTS schedules_max_duration;
ALTER TABLE schedules ADD CONSTRAINT schedules_max_duration
    CHECK (end_time > start_time AND end_time - start_time <= INTERVAL '3 hours');

-- Minutes this booking drew from the robot's duty budget, so completing or
-- cancelling can return them accurately.
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS duty_minutes INT NOT NULL DEFAULT 0;

-- --------------------------------------------------------- charge log

CREATE TABLE IF NOT EXISTS charge_sessions (
    id SERIAL PRIMARY KEY,
    robot_id INT NOT NULL REFERENCES robots(id) ON DELETE CASCADE,
    arena_id INT REFERENCES arenas(id) ON DELETE SET NULL,
    reason VARCHAR(40) NOT NULL DEFAULT 'duty_exhausted',
    duty_minutes_at_start INT,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_charge_robot ON charge_sessions (robot_id, started_at DESC);
