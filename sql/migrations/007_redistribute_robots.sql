-- 007 Spread robots across their district again.
--
-- 006 re-homed robots with `SELECT DISTINCT ON (domain) ... ORDER BY domain,
-- RANDOM()`, which picks ONE site per district and then parks every robot of
-- that type on it. The result was five dense clusters instead of a fleet spread
-- over twenty-five sites, and nothing left in transit.
--
-- Forward-only: 006 is already applied, so this corrects rather than edits it.

-- One randomly chosen site PER ROBOT (DISTINCT ON (r.id), not ON (domain)),
-- with a small jitter so robots sit around a site rather than on its pin.
WITH pick AS (
    SELECT DISTINCT ON (r.id)
           r.id AS robot_id,
           a.latitude,
           a.longitude
    FROM robots r
    JOIN arenas a
      ON a.domain = r.type
     AND a.latitude IS NOT NULL
    ORDER BY r.id, RANDOM()
)
UPDATE robots r
SET current_location_lat = pick.latitude  + ((RANDOM() - 0.5) * 0.0024),
    current_location_lng = pick.longitude + ((RANDOM() - 0.5) * 0.0032)
FROM pick
WHERE pick.robot_id = r.id;

-- Push roughly one in six well outside any radius, so "in transit" is a state
-- the map, the ping reply and the nearest-dock dispatch all genuinely exercise.
UPDATE robots
SET current_location_lat = current_location_lat + ((RANDOM() - 0.5) * 0.011),
    current_location_lng = current_location_lng + ((RANDOM() - 0.5) * 0.015)
WHERE MOD(id, 6) = 0;

-- Keep everyone inside the mapped frame; a robot off the edge of the artwork
-- cannot be drawn.
UPDATE robots
SET current_location_lat = GREATEST(40.6938, LEAST(40.7318, current_location_lat)),
    current_location_lng = GREATEST(-74.0350, LEAST(-73.9770, current_location_lng));

-- Docked robots belong exactly at their dock.
UPDATE robots r
SET current_location_lat = a.latitude,
    current_location_lng = a.longitude
FROM arenas a
WHERE r.charging_arena_id = a.id AND a.latitude IS NOT NULL;
