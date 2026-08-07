-- 006 Align RobotCity coordinates to the illustrated map.
--
-- The original coordinates were invented to generate a plausible campus. The
-- artwork was then drawn to look right rather than to those numbers, so the two
-- disagreed by up to 27 percentage points -- enough to place a robot in the
-- wrong district on screen.
--
-- The coordinates are the synthetic side of that pair, so they move. Every
-- derived behaviour (nearest-site, in-transit distance, nearest-dock dispatch)
-- now matches what an operator actually sees on the map.
--
-- Frame: x = 0..100 left->right, y = 0..100 top->bottom of the image.
--   lng = -74.0360 + x/100 * 0.06      (~5.0 km wide)
--   lat =  40.7328 - y/100 * 0.04      (~4.4 km tall)

WITH layout(name, x, y) AS (VALUES
    -- Healthcare, north
    ('Pathology Annex',        14.0, 18.5),
    ('Mercy Wing 2',           30.6, 14.6),
    ('ICU Ward 3',             47.0, 17.0),
    ('Surgical Theatre B',     62.5, 17.6),
    ('Ambulance Bay North',    80.7, 12.7),

    -- Military, west
    ('Signals Bunker',         15.0, 31.0),
    ('Vehicle Depot 2',        21.5, 39.0),
    ('Armoury Bunker',         12.7, 51.0),
    ('Live Fire Range',        16.6, 62.5),
    ('Forward Post West',       7.2, 68.0),

    -- Research, east
    ('Cryogenics Vault',       78.8, 27.0),
    ('Biocontainment C',       73.0, 41.0),
    ('Chem Lab 1',             86.5, 44.0),
    ('Optics Bench 4',         74.0, 60.5),
    ('Test Range East',        91.0, 62.5),

    -- Warehouse, south
    ('Cold Storage 7',         18.0, 79.0),
    ('Palletising Floor',      34.0, 78.0),
    ('Main Warehouse',         49.0, 80.0),
    ('Loading Dock A',         64.0, 83.0),
    ('Rail Transfer Yard',     81.0, 80.0),

    -- Security, perimeter and centre
    ('Perimeter Fence North',  50.0,  2.6),
    ('Surveillance Hub',       36.0, 46.0),
    ('Server Room',            62.5, 47.0),
    ('Gatehouse East',         95.0, 49.0),
    ('Perimeter Fence South',  50.0, 96.0),

    -- Charging docks. The first four are drawn on the map; the remaining six
    -- sit in open ground and appear as overlay pins until the artwork includes
    -- them.
    ('Dock Golf',              50.0, 48.0),   -- the illustrated 10-bay hub
    ('Dock Alpha',             67.0, 21.0),
    ('Dock Bravo',             86.0, 48.0),
    ('Dock Charlie',           82.0, 66.0),
    ('Dock Delta',             30.0, 27.0),
    ('Dock Echo',              88.0, 20.0),
    ('Dock Foxtrot',           28.0, 68.0),
    ('Dock Hotel',             60.0, 69.0),
    ('Dock India',             40.0, 61.0),
    ('Dock Juliet',            70.0, 33.0)
)
UPDATE arenas a
SET longitude = -74.0360 + (l.x / 100.0) * 0.06,
    latitude  =  40.7328 - (l.y / 100.0) * 0.04
FROM layout l
WHERE a.name = l.name;

-- Robots were placed relative to the old site positions, so anything left
-- outside every radius would read as permanently "in transit". Re-home each one
-- near a site in its own district, keeping a scattering genuinely between sites.
UPDATE robots r
SET current_location_lat = s.latitude  + ((RANDOM() - 0.5) * 0.0022),
    current_location_lng = s.longitude + ((RANDOM() - 0.5) * 0.0030)
FROM (
    SELECT DISTINCT ON (domain) domain, latitude, longitude
    FROM arenas
    WHERE domain <> 'charging' AND latitude IS NOT NULL
    ORDER BY domain, RANDOM()
) s
WHERE r.type = s.domain;

-- Docked robots belong at their dock, not near it.
UPDATE robots r
SET current_location_lat = a.latitude,
    current_location_lng = a.longitude
FROM arenas a
WHERE r.charging_arena_id = a.id AND a.latitude IS NOT NULL;
