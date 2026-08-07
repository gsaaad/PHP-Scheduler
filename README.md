# Robot Fleet Scheduler

A PHP 8.2 + PostgreSQL service for managing a robot fleet across multiple labs:
registering robots, defining tasks, and booking robots against those tasks with
conflict detection — under per-lab access control.

No framework — a front controller, a route table, PDO repositories, an
attribute-based access policy, and a factory mapping robot types to behaviour classes.

## Quick start

```bash
composer install
docker compose up --build -d                    # migrations run on boot
docker compose exec app php scripts/seed.php    # 150 robots, 8 labs, access rules
open http://localhost:8080
```

Sign in as any of the seeded accounts (all password `password`):

| User | Reaches |
|---|---|
| `admin` | the whole fleet (fleet administrator) |
| `marine_lead` | robots that **walk AND swim**, or that **float** |
| `bio_lead` | robots tagged to the **Biology** department |
| `chem_lead` | every robot stationed in **Chem Lab 1** |
| `tech_lead` | all **research** and **warehouse** type robots (technician: maintain, not schedule) |

Postgres is exposed on `localhost:5433` for direct inspection.

## Access control

Authentication is required for every endpoint except `GET /`, `GET /health`, and
`POST /api/auth/login`. Routes are **closed by default** — a new route is protected
unless explicitly added to the public list in [public/index.php](public/index.php).

Two credential types:

- **Session cookie** — `POST /api/auth/login`, used by the dashboard. HttpOnly,
  SameSite=Lax, Secure when the request arrives over TLS.
- **Bearer token** — `POST /api/auth/tokens`, for machine callers.
  `Authorization: Bearer ace_…`

Both are stored only as SHA-256 hashes; plaintext is shown once and never persisted,
so a database disclosure yields nothing replayable.

### How a lab's fleet is decided

Access is **attribute-based**, not a single fixed dimension:

> A robot is reachable when **any** rule matches, and a rule matches when **all**
> of its criteria hold.

Criteria come in four kinds: `arena`, `capability`, `department`, `type`. That
two-level shape expresses the real cases:

| Requirement | Modelled as |
|---|---|
| "everything in Chem Lab 1" | 1 rule, 1 `arena` criterion |
| "robots that walk **and** swim" | 1 rule, 2 `capability` criteria |
| "anything that swims **or** floats" | 2 rules, 1 `capability` criterion each |
| "biology robots only" | 1 rule, 1 `department` criterion |
| unrestricted | 1 rule with **zero** criteria, or a role with `is_admin` |

The filter is composed into the SQL rather than applied afterwards, so out-of-scope
robots are never fetched, counted, or paginated over. `GET /api/auth/me` returns the
rules behind your scope, so an empty list is explainable rather than mysterious.

Arena selection (`?arena_id=`) is a **view filter layered on top of** access — it
narrows, it can never widen.

Role flags: `is_admin` (bypasses rules), `can_schedule` (book/complete/create tasks),
`can_maintain` (status changes, maintenance, firmware).

## API

All responses are JSON. Errors use `{"error": "..."}`; validation failures (422) add
a field-keyed `errors` map.

| Method | Path | Requires |
|---|---|---|
| `GET` | `/health` | — (liveness + DB check) |
| `POST` | `/api/auth/login` | — |
| `POST` | `/api/auth/logout` | auth |
| `GET` | `/api/auth/me` | auth — your identity, scope, and rules |
| `POST` | `/api/auth/tokens` | auth — issues a bearer token (shown once) |
| `DELETE` | `/api/auth/tokens/{id}` | auth (own tokens only) |
| `GET` | `/api/robots?limit=&offset=&arena_id=&status=&type=` | auth |
| `GET` | `/api/robots/{id}` | auth + robot in scope |
| `POST` | `/api/robots` | `is_admin` |
| `PATCH` | `/api/robots/{id}/status` | `can_maintain` + scope |
| `GET` | `/api/tasks` | auth |
| `POST` | `/api/tasks` | `can_schedule` |
| `GET` | `/api/tasks/{id}/eligible-robots?start_time=` | auth |
| `GET` | `/api/tasks/{id}/eligibility/{robotId}` | auth + scope |
| `GET` | `/api/arenas` · `/api/capabilities` · `/api/summary` | auth — filter reference data, access-scoped |
| `GET` | `/api/map` | auth — RobotCity sites + live robot positions in scope |
| `POST` | `/api/robots/{id}/ping` | auth + scope — where it is and what it's doing |
| `POST` | `/api/robots/{id}/charge/complete` | `can_maintain` — ends a charge, resets duty |
| `POST` | `/api/robots/{id}/media/{image\|hover}` | `is_admin` — upload, stored outside the web root |
| `GET` | `/api/robots/{id}/media/{image\|hover}` | auth + scope |
| `GET` | `/api/schedules/window?from=&to=&view=gantt` | auth — calendar and timeline (max 92 days) |
| `GET` | `/api/schedules?robot_id=&limit=&offset=` | auth |
| `POST` | `/api/schedules` | `can_schedule` + scope |
| `POST` | `/api/schedules/{id}/complete` | `can_schedule` + scope |
| `GET` | `/api/robots/{id}/maintenance` | auth + scope |
| `POST` | `/api/robots/{id}/maintenance` | `can_maintain` + scope |
| `POST` | `/api/maintenance/{id}/close` | `can_maintain` |
| `GET` | `/api/firmware` | auth |
| `POST` | `/api/firmware` | `is_admin` |
| `POST` | `/api/robots/{id}/firmware` | `can_maintain` + scope |

Status codes: `200`/`201` success, `401` unauthenticated, `403` forbidden or
out-of-scope, `404` unknown route/record, `405` wrong method, `409` conflict,
`422` validation failure, `500` unexpected (detail logged, never returned).

### Which robots can take a task?

`GET /api/tasks/{id}/eligible-robots` applies every gate in one query — access scope,
bookable status, required capability, battery headroom, and (with `?start_time=`)
booking conflicts across the resulting window. This replaces discovering
ineligibility by attempting a booking and reading the `409`.

`GET /api/tasks/{id}/eligibility/{robotId}` explains a single robot, listing **every**
failed gate rather than the first.

## RobotCity

Twenty-five district sites — five each for healthcare, research, warehouse, military and
security — plus ten charging docks, all on real coordinates. Robots carry **coordinates,
not an arena id**: the site a robot is "at" is derived by proximity, and outside every
site's radius it is genuinely **in transit** rather than missing data.

To supply an illustrated map, follow [docs/robotcity-map-prompt.md](docs/robotcity-map-prompt.md)
and drop the result at `public/images/robotcity.png`. Without it the map tab draws a
schematic from the same coordinates, so the feature works either way.

## Duty budgets

Endurance is shared across departments, and part of it is never for sale — the robot has
to drive itself back to a dock:

```
endurance          270 min  (4.5 h)
bookable total     240 min  <- endurance minus reserve
Dept A books       180 min
return reserve      30 min  (never schedulable)
================================
Dept B may book     60 min  (1 h)
```

Platforms vary: `heavy` 4–4.75 h, `standard` 5–6 h, `light` 6.5–7 h. A single booking is
capped at **3 hours**, enforced in the app and by a `CHECK` constraint. When the bookable
remainder drops to 30 minutes or less, completing the last job **dispatches the robot to
its nearest charging dock** and logs a charge session; `POST /api/robots/{id}/charge/complete`
resets the budget and returns it to service at that dock.

### Scheduling rules

A booking is rejected when:

- the robot or task does not exist (`404`), or the robot is out of scope (`403`)
- the robot is in `maintenance` or `error` (`409`)
- the window overlaps an existing non-cancelled booking (`409`)
- the robot lacks the task's `required_capability_id` (`409`)
- the robot's battery is below the task's `min_battery_level` (`409`)

`end_time` derives from the task's `estimated_duration`. A robot flips to `busy` only
when the booked window is live, and returns to `idle` on `complete` — unless it has
another active booking or has since moved to maintenance/error/charging.

Bookings are serialised per robot with `SELECT … FOR UPDATE`, so two concurrent
requests cannot both pass the overlap check.

### Audit

Every mutation and every denial writes to `audit_logs` with actor, action, entity,
outcome (`success` / `denied` / `rejected` / `error`) and IP. Audit writes happen
outside the business transaction deliberately: a booking that rolls back must still
leave a record that it was attempted and refused.

## Migrations

Schema changes are forward-only SQL files in `sql/migrations/`, tracked in
`schema_migrations`. Each runs in its own transaction.

```bash
php scripts/migrate.php          # apply pending
php scripts/migrate.php status   # show applied/pending
```

There is **no** `docker-entrypoint-initdb.d` hook: it only ever fires on an empty
volume, and managed Postgres (RDS / Cloud SQL / Azure Database) has no equivalent —
so schema changes had no roll-forward path. The container applies migrations on boot
when `RUN_MIGRATIONS=1`.

> On Kubernetes/ECS, leave `RUN_MIGRATIONS` unset and run `php scripts/migrate.php`
> as a separate init job. With several replicas booting at once you want exactly one
> process applying schema changes.

## Configuration

| Variable | Default | Notes |
|---|---|---|
| `DB_HOST` | `localhost` | |
| `DB_PORT` | `5432` | `5433` from the host under compose |
| `DB_NAME` | `robot_scheduler` | |
| `DB_USER` | `postgres` | compose uses `user` |
| `DB_PASSWORD` | — | **required**; the app refuses to start without it |
| `RUN_MIGRATIONS` | `0` | `1` applies migrations on container boot |
| `TRUSTED_PROXY` | `0` | `1` trusts `X-Forwarded-For` for audit IPs |

Running outside Docker also needs `pdo_pgsql` enabled in `php.ini`.

## Tests

```bash
composer test:unit          # no database required
composer test:integration   # needs DB_HOST and a migrated database
composer test               # both
composer lint
```

Integration tests skip themselves when `DB_HOST` is unset. Fixtures are tracked and
deleted in `tearDown`, so runs leave no rows behind.

## Layout

```
config/database.php      env-driven DB settings
docker/                  Apache vhost + migrate-on-boot entrypoint
public/index.php         front controller: routes, auth gate, error handling
public/index.html        dashboard (login, lab-scoped fleet, eligibility)
scripts/migrate.php      migration CLI
scripts/seed.php         seeder (150 robots, 8 labs, access rules, demo users)
sql/migrations/          001 baseline, 002 access control, 003 operations
src/Audit/               AuditLogger
src/Auth/                Authenticator, AccessPolicy, AuthContext
src/Controllers/         Auth, Robot, Task, Schedule, Maintenance
src/Exceptions/          HttpException -> 401/403/404/409/422
src/Factories/           RobotFactory: type -> class
src/Http/                Router, Request, JsonResponse, Validator
src/Migrations/          Migrator
src/Models/              BaseRobot + subclasses, repositories, Schedule, enums
tests/Unit/              factory, model, validator, router
tests/Integration/       access policy, authenticator, eligibility, scheduling
```

## Before a production deployment

Implemented: authentication, per-lab authorization, audit trail, migrations,
health checks, secrets via environment, security headers, battery policy,
maintenance and firmware lifecycle.

Still outstanding, and deliberately so — these are deployment-environment concerns
rather than application code:

- **TLS** — the app sets `Secure` cookies when it sees HTTPS (directly or via
  `X-Forwarded-Proto`), but termination is your load balancer's job.
- **Secrets management** — credentials come from the environment; wire them to
  Secrets Manager / Key Vault / Secret Manager rather than compose literals.
- **Rate limiting** on `POST /api/auth/login` — currently unthrottled.
- **Backups and PITR** — a managed Postgres setting, not application code.
- **Log aggregation** — the app writes structured messages to stderr; ship them.
- **Password policy / rotation and SSO** — the seeder's `password` is a demo value.
  Token + session auth was chosen as the first step; OIDC remains an option.
