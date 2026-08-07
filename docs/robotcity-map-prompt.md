# RobotCity — map image prompt (v2)

## What changed and why

The first render was strong: all 25 district sites present, correctly labelled,
districts properly colour-coded, the 10-bay hub dead centre. Two things missed:

1. **Only 4 of 10 charging docks appeared.** Docks drive the auto-dispatch
   feature — a robot sent to Dock India shouldn't be heading for empty tarmac.
2. **Placement drifted** from the coordinate table by up to 27 points. That is
   expected: image models follow *spatial description*, not numeric tables. v2
   describes every position relative to landmarks and quadrants instead, and
   keeps the table only as a post-check.

Aspect ratio also came out 3:2 rather than 4:3. That no longer matters — the
dashboard now reads the ratio from the file itself.

> **Realignment is cheap.** Whatever layout comes back, the database is realigned
> to it with one migration. Don't fight the generator to hit exact percentages —
> get a map that *looks* right and structurally correct, and the coordinates follow.

---

## The prompt — copy from here

> A highly detailed top-down isometric illustrated map of a fictional robotics
> campus called **RobotCity**, in the style of a premium theme-park or airport
> wayfinding map: crisp vector-like shapes, clean soft shadows, muted industrial
> palette, colour-coded districts, small white label plates with dark text naming
> every building. Even diffuse daylight, no harsh shadows, no vignette. Legible at
> a glance. **16:10 landscape.** No border, no frame, no watermark, no title card.
>
> **Overall structure.** The campus is a walled compound with a continuous
> perimeter wall running the full edge of the image. At the **exact centre** sits a
> large circular plaza containing a hexagonal **10-bay charging hub** with a glowing
> cyan lightning-bolt roof. A **ring road** circles that plaza, and **four wide
> radial avenues** run outward from the ring toward the four district quadrants,
> dividing the campus cleanly. Secondary service lanes, crosswalks, painted road
> markings, lawns, tree lines, and parking bays fill the space between buildings so
> the campus feels lived-in and functional. Small robots of varied silhouettes —
> wheeled loaders, quadrupeds, aerial drones, medical carts, tracked patrol units —
> travel the roads and open ground throughout.
>
> **NORTH quadrant — Healthcare District** (soft teal and white, clinical, glass
> walkways). Five buildings, spread left to right across the top: **Pathology
> Annex** at the far upper-left of the district; **Mercy Wing 2** to its right, the
> largest, with a red cross on its roof; **ICU Ward 3** centre-top with a rooftop
> helipad marked H; **Surgical Theatre B** to the right of it, linked by an
> enclosed glass sky-bridge; and **Ambulance Bay North** at the district's far
> right — an open canopy with four parked ambulances and painted bay numbers.
>
> **EAST quadrant — Research District** (violet, chrome, high-tech). Five
> structures: **Cryogenics Vault** at the top of the district, a domed silver
> cylinder venting white vapour; **Biocontainment C** to its lower-left, a sealed
> windowless block with biohazard trefoils and an airlock; **Chem Lab 1** to the
> right, wrapped in yellow-and-black hazard striping with external tank farms;
> **Optics Bench 4** below them, a long low hall with roof-mounted dishes and
> antenna arrays; and **Test Range East** at the far right — an open fenced yard
> with concentric target markers and instrument masts.
>
> **SOUTH quadrant — Warehouse District** (amber, concrete, industrial). Five
> buildings along the bottom: **Cold Storage 7** at the far lower-left with frost
> panels and a snowflake sign; **Palletising Floor** to its right with visible
> conveyor lines; **Main Warehouse** centre-bottom, the single largest building on
> the map, sawtooth roof, wide amber roller doors; **Loading Dock A** to its right
> with articulated lorries reversed into numbered bays; and **Rail Transfer Yard**
> at the far lower-right — container stacks, a gantry crane, and rail lines running
> off the eastern edge.
>
> **WEST quadrant — Military District** (olive drab, grey, fortified, inside its
> own inner chain-link fence). Five sites stacked top to bottom: **Signals Bunker**
> highest, with a tall lattice antenna mast and satellite dishes; **Vehicle Depot
> 2** below it, an open-sided hangar with rows of parked utility vehicles;
> **Armoury Bunker**, a low hardened concrete structure with blast doors;
> **Live Fire Range**, an open sand-bermed range with target silhouettes; and
> **Forward Post West** at the lower-left corner — a raised watchtower on stilts.
>
> **Security** (slate blue) is distributed rather than clustered:
> **Perimeter Fence North** as a labelled gatehouse midway along the very top wall;
> **Perimeter Fence South** likewise midway along the very bottom wall; **Gatehouse
> East** on the far right edge where the eastern approach road meets the wall;
> **Surveillance Hub** just west of the central plaza, a dark tower bristling with
> camera masts; and **Server Room** just east of the central plaza, a windowless
> slate block with external cooling units.
>
> **CRITICAL — exactly TEN charging stations, all clearly visible.** Each is a
> hexagonal canopy pad with a glowing cyan lightning-bolt marking and robot docking
> bays beneath, visually distinct from every other structure and never hidden
> behind a building. Place them as follows:
> 1. **Dock Golf** — the large ten-bay hub at the exact centre of the plaza.
> 2. **Dock Alpha** — north-east, between Surgical Theatre B and Ambulance Bay North.
> 3. **Dock Bravo** — east, on the road between Chem Lab 1 and Gatehouse East.
> 4. **Dock Charlie** — south-east, between Optics Bench 4 and Test Range East.
> 5. **Dock Delta** — north-west, in open ground between Pathology Annex and Signals Bunker.
> 6. **Dock Echo** — far north-east corner, above Cryogenics Vault.
> 7. **Dock Foxtrot** — south-west, between Live Fire Range and Cold Storage 7.
> 8. **Dock Hotel** — south of the plaza, between the ring road and Main Warehouse.
> 9. **Dock India** — west of centre, between Surveillance Hub and Palletising Floor.
> 10. **Dock Juliet** — north-east of centre, between Surgical Theatre B and Biocontainment C.
>
> Every one of the 25 district buildings and all 10 charging pads carries a small
> readable label plate with its exact name. Keep all buildings fully inside the
> frame — nothing clipped at any edge.

## Copy ends

---

## Post-check

After generating, confirm:

- [ ] **10 cyan hex pads** are visible and countable (this was the main miss)
- [ ] All 25 district buildings present and labelled, spelled as above
- [ ] Central plaza hub is clearly the largest dock
- [ ] Nothing clipped at the edges
- [ ] No stray text, borders, or watermarks

Then:

```bash
cp your-map.png public/images/robotcity.png
```

Open the **Map** tab. If markers sit slightly off their buildings, say so and the
coordinates get realigned by migration — no regeneration needed. For a small
uniform drift, `MAP_CALIBRATION` at the top of [public/app.js](../public/app.js)
takes `{ xOffset, yOffset, xScale, yScale }` in percent.

---

## Current coordinates (post-check reference only)

These are what the database holds now, matched to the v1 render. Positions are
percentages of image width and height. **Do not feed this table to the generator**
— it is for verifying a result, not producing one.

| Site | x | y | | Site | x | y |
|---|---|---|---|---|---|---|
| Pathology Annex | 14 | 19 | | Cold Storage 7 | 18 | 79 |
| Mercy Wing 2 | 31 | 15 | | Palletising Floor | 34 | 78 |
| ICU Ward 3 | 47 | 17 | | Main Warehouse | 49 | 80 |
| Surgical Theatre B | 63 | 18 | | Loading Dock A | 64 | 83 |
| Ambulance Bay North | 81 | 13 | | Rail Transfer Yard | 81 | 80 |
| Signals Bunker | 15 | 31 | | Perimeter Fence North | 50 | 3 |
| Vehicle Depot 2 | 22 | 39 | | Surveillance Hub | 36 | 46 |
| Armoury Bunker | 13 | 51 | | Server Room | 63 | 47 |
| Live Fire Range | 17 | 63 | | Gatehouse East | 95 | 49 |
| Forward Post West | 7 | 68 | | Perimeter Fence South | 50 | 96 |
| Cryogenics Vault | 79 | 27 | | **Dock Golf** (10 bay) | 50 | 48 |
| Biocontainment C | 73 | 41 | | Dock Alpha (6) | 67 | 21 |
| Chem Lab 1 | 87 | 44 | | Dock Bravo (6) | 86 | 48 |
| Optics Bench 4 | 74 | 61 | | Dock Charlie (8) | 82 | 66 |
| Test Range East | 91 | 63 | | Dock Delta (6) | 30 | 27 |
| | | | | Dock Echo (4) | 88 | 20 |
| | | | | Dock Foxtrot (4) | 28 | 68 |
| | | | | Dock Hotel (4) | 60 | 69 |
| | | | | Dock India (4) | 40 | 61 |
| | | | | Dock Juliet (4) | 70 | 33 |
