-- 005 Duty classes and the return-to-dock reserve.
--
-- A robot's endurance is not entirely schedulable: it has to get itself back to
-- a charging dock under its own power, and that trip costs duty time. Holding
-- it back explicitly is what makes the arithmetic match reality --
--
--     4h 30m endurance - 3h booked - 30m return = 1h left for the next department
--
-- rather than promising 1h 30m and stranding the robot in a corridor.

ALTER TABLE robots ADD COLUMN IF NOT EXISTS return_reserve_minutes INT NOT NULL DEFAULT 30
    CHECK (return_reserve_minutes >= 0);

-- Platform weight class. Heavy units drain fast and lug themselves home slowly;
-- lightweight units can run most of a working day.
ALTER TABLE robots ADD COLUMN IF NOT EXISTS duty_class VARCHAR(12) NOT NULL DEFAULT 'standard'
    CHECK (duty_class IN ('heavy', 'standard', 'light'));

CREATE INDEX IF NOT EXISTS idx_robots_duty_class ON robots (duty_class);

-- The reserve must fit inside the endurance, or nothing is ever bookable.
ALTER TABLE robots DROP CONSTRAINT IF EXISTS robots_reserve_fits;
ALTER TABLE robots ADD CONSTRAINT robots_reserve_fits
    CHECK (return_reserve_minutes < max_duty_minutes);

-- ------------------------------------------------------------- media
--
-- Uploaded robot media is stored outside the web root and served through a
-- controller, so an uploaded file can never be executed by Apache. These
-- columns hold the stored filename and the verified type, not a public path.

ALTER TABLE robots ADD COLUMN IF NOT EXISTS image_file VARCHAR(120);
ALTER TABLE robots ADD COLUMN IF NOT EXISTS image_mime VARCHAR(60);
ALTER TABLE robots ADD COLUMN IF NOT EXISTS hover_file VARCHAR(120);
ALTER TABLE robots ADD COLUMN IF NOT EXISTS hover_mime VARCHAR(60);
ALTER TABLE robots ADD COLUMN IF NOT EXISTS media_updated_at TIMESTAMP;
