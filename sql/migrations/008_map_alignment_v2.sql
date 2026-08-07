-- 008 Re-align RobotCity to the v2 artwork.
--
-- The v2 prompt described placement relative to landmarks rather than as a
-- coordinate table, and the render tracked it closely: district buildings moved
-- only 1-4 percentage points. The charging docks moved further -- Dock Foxtrot
-- by ~19 points, Dock Hotel by 10 -- because "between Live Fire Range and Cold
-- Storage 7" admits a wide arc, and the illustrator chose the outer corner.
--
-- Same frame as 006:
--   lng = -74.0360 + x/100 * 0.06
--   lat =  40.7328 - y/100 * 0.04

WITH layout(name, x, y) AS (VALUES
    -- Healthcare, north
    ('Pathology Annex',        14.7, 15.0),
    ('Mercy Wing 2',           32.0, 13.0),
    ('ICU Ward 3',             48.0, 14.0),
    ('Surgical Theatre B',     62.0, 14.0),
    ('Ambulance Bay North',    81.0, 12.0),

    -- Military, west
    ('Signals Bunker',         12.7, 30.0),
    ('Vehicle Depot 2',        20.0, 32.0),
    ('Armoury Bunker',         12.0, 51.0),
    ('Live Fire Range',        15.0, 62.0),
    ('Forward Post West',       7.0, 70.0),

    -- Research, east
    ('Cryogenics Vault',       81.0, 27.0),
    ('Biocontainment C',       75.0, 42.0),
    ('Chem Lab 1',             89.0, 44.0),
    ('Optics Bench 4',         75.0, 57.0),
    ('Test Range East',        92.0, 65.0),

    -- Warehouse, south
    ('Cold Storage 7',         19.0, 79.0),
    ('Palletising Floor',      32.0, 78.0),
    ('Main Warehouse',         50.0, 80.0),
    ('Loading Dock A',         65.0, 80.0),
    ('Rail Transfer Yard',     82.0, 79.0),

    -- Security, perimeter and centre
    ('Perimeter Fence North',  50.0,  3.0),
    ('Surveillance Hub',       36.0, 45.0),
    ('Server Room',            63.0, 48.0),
    ('Gatehouse East',         96.0, 51.0),
    ('Perimeter Fence South',  50.0, 96.0),

    -- All ten docks are drawn on the v2 map, so every one of these is a
    -- measured position rather than an assumed spot in open ground.
    ('Dock Golf',              50.0, 44.0),
    ('Dock Alpha',             70.0, 21.0),
    ('Dock Bravo',             83.0, 49.0),
    ('Dock Charlie',           78.0, 70.0),
    ('Dock Delta',             21.0, 21.0),
    ('Dock Echo',              90.0, 11.0),
    ('Dock Foxtrot',            9.0, 87.0),
    ('Dock Hotel',             50.0, 67.0),
    ('Dock India',             32.0, 57.0),
    ('Dock Juliet',            62.0, 36.0)
)
UPDATE arenas a
SET longitude = -74.0360 + (l.x / 100.0) * 0.06,
    latitude  =  40.7328 - (l.y / 100.0) * 0.04
FROM layout l
WHERE a.name = l.name;

-- Sites shifted under the robots, so re-spread the fleet across its districts.
-- One randomly chosen site PER ROBOT -- DISTINCT ON (r.id), not ON (domain),
-- which is the mistake 006 made and 007 corrected.
WITH pick AS (
    SELECT DISTINCT ON (r.id)
           r.id AS robot_id, a.latitude, a.longitude
    FROM robots r
    JOIN arenas a ON a.domain = r.type AND a.latitude IS NOT NULL
    ORDER BY r.id, RANDOM()
)
UPDATE robots r
SET current_location_lat = pick.latitude  + ((RANDOM() - 0.5) * 0.0024),
    current_location_lng = pick.longitude + ((RANDOM() - 0.5) * 0.0032)
FROM pick
WHERE pick.robot_id = r.id;

-- Keep roughly one in six genuinely between sites.
UPDATE robots
SET current_location_lat = current_location_lat + ((RANDOM() - 0.5) * 0.011),
    current_location_lng = current_location_lng + ((RANDOM() - 0.5) * 0.015)
WHERE MOD(id, 6) = 0;

UPDATE robots
SET current_location_lat = GREATEST(40.6938, LEAST(40.7318, current_location_lat)),
    current_location_lng = GREATEST(-74.0350, LEAST(-73.9770, current_location_lng));

UPDATE robots r
SET current_location_lat = a.latitude,
    current_location_lng = a.longitude
FROM arenas a
WHERE r.charging_arena_id = a.id AND a.latitude IS NOT NULL;
