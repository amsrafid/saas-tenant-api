# SaaS Subscription & Tenant Management API

A multi-tenant REST API for companies (tenants), their staff users and roles, customers, subscription plans with feature limits, and dashboard analytics.

Laravel 13 · PHP 8.4 · PostgreSQL 16 · Redis 7 · Sanctum · Pest · Docker Compose

## Setup

Docker is the only thing you need — PHP, Composer and Postgres all run in containers. Ports **8000** and **5432** must be free; to move the API, put `APP_PORT=8080` in a `.env` file next to `docker-compose.yml`.

```bash
git clone https://github.com/amsrafid/saas-tenant-api.git
cd saas-tenant-api
docker compose up -d
```

That is the whole setup. The first run builds the image and installs Composer packages, so it takes a few minutes; later runs take seconds.

| Service | Role |
|---|---|
| `db`, `redis` | Postgres and Redis; nothing else starts until their healthchecks pass |
| `setup` | Runs once (`docker/php/setup.sh`): creates `src/.env`, `composer install`, generates `APP_KEY`, `migrate --force --seed`, prints the URLs and demo logins, exits |
| `app` | PHP-FPM; starts only after `setup` succeeds |
| `nginx` | Serves the API on port 8000 |
| `worker` | `queue:work` |
| `scheduler` | `schedule:work`: the hourly expiry sweep and the daily token prune |

`up` can be run again any time. The seeders are idempotent, so nothing is duplicated and your changes are not overwritten. Tests use a separate `saas_testing` database.

```bash
docker compose ps
docker compose logs setup     # the URLs and demo logins it printed
```

## URLs

| What | URL |
|---|---|
| API base | http://localhost:8000/api/v1 |
| Swagger UI | http://localhost:8000/api/documentation |
| OpenAPI 3.1 spec | http://localhost:8000/docs/api.json |

## Demo accounts

The password for every account is **`password`**.

| Email | Role | Tenant | Plan and usage |
|---|---|---|---|
| `admin@platform.test` | `platform_admin` | none | `/admin/*` only; 403 on tenant routes |
| `owner@acme.test` | `owner` | Acme Corporation | Pro: 4 of 10 users, 8 of 1,000 customers |
| `admin@acme.test` | `admin` | Acme Corporation | |
| `member1@acme.test` | `member` | Acme Corporation | |
| `member2@acme.test` | `member` | Acme Corporation | |
| `owner@globex.test` | `owner` | Globex Corporation | Free: 3 of 3 users (at the limit), 6 of 10 customers |
| `admin@globex.test` | `admin` | Globex Corporation | |
| `member@globex.test` | `member` | Globex Corporation | |

Plans: **Free** ($0, 3 users, 10 customers), **Pro** ($29/month, 10 users, 1,000 customers), **Enterprise** ($99/month, unlimited).

Two tenants are seeded, so isolation can be checked straight away: log in as Acme, ask for a Globex customer id, get a 404. The seeded customers are spread over the past year, so the dashboard shows a real growth curve.

## Using Swagger UI

Open http://localhost:8000/api/documentation, send `POST /auth/login` with `{"email": "owner@acme.test", "password": "password"}`, and copy `data.access_token` (including the `1|` prefix). Click **Authorize**, paste the token without a `Bearer ` prefix, and every endpoint then runs as that user.

The same with curl:

```bash
curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' -d '{"email":"owner@acme.test","password":"password"}'

curl -s http://localhost:8000/api/v1/customers -H 'Authorization: Bearer <access_token>'
```

## Architecture

| Area | How it works | Detail |
|---|---|---|
| Request path | Route (`auth:sanctum` → `tenant` → `throttle` → `can:<ability>`) → Controller → Form Request → Service → API Resource. One service per domain; a repository only where a read is cached. | [system design §6](docs/system-design.md) |
| Multi-tenancy | One database, one schema, a `tenant_id` column on every tenant-owned table. `ResolveTenant` takes the tenant from the token's user, never from the request, and a global scope filters every tenant-owned model — so a controller that forgets a `where` still cannot read another tenant's rows. | [system design §2–§3](docs/system-design.md) |
| Tenancy in jobs | The tenant context is cleared after every request and every job. A queue worker is one long-lived process, so a leftover tenant would otherwise follow one job into the next. | [system design §3](docs/system-design.md) |
| Authorization | Roles are an enum, and `Ability` maps each ability to the roles that hold it. Each ability becomes one Gate on the route, so a check costs no query. Rules about the data live in the service. | [system design §5](docs/system-design.md) |
| Caching | Five Redis keys, 24 hours each. A model observer clears each one after the transaction commits, so a write from anywhere clears what it made stale. | [caching §1–§2](docs/caching.md) |
| Rate limiting | Four limiters, counted in Redis under hashed keys: login and register per email + IP, register per IP, public plan routes per IP, logged-in routes per user. | [caching §4](docs/caching.md) |
| Background jobs | `RefreshPlatformStats` rebuilds the analytics numbers ~30 s after a write and collapses a burst into one run. `ProcessDueSubscriptions` expires canceled subscriptions hourly. | [caching §5](docs/caching.md) |

**Built for large tenants.** A tenant is expected to hold millions of customers, so the numbers a request needs are stored, not counted:

- `tenant_stats` keeps the exact user and customer counts, written by observers inside the same transaction as the row. Plan limits, usage and a plain listing's `meta.total` read that one row.
- `tenant_monthly_stats` stores customers added per month, for the dashboard.
- `platform_stats` and `plan_stats` are filled by a queued job, so analytics reads stored numbers.
- Every index on a tenant-owned table leads with `tenant_id` and ends in the listing order, so a page comes straight off the index.

Measured on 10M customers: a listing page costs 0.1 ms whether the tenant holds 10 rows or 5,000,000 ([database §7](docs/database.md)).

## API behaviour worth knowing

Swagger shows the request and response shapes. These are the rules a shape cannot show; full samples are in [docs/api.md](docs/api.md).

| Situation | Response |
|---|---|
| Another tenant's id in the URL | **404**, the same body as a missing record |
| A user of a **suspended** tenant on a tenant route | **403**; a platform admin gets 403 there too |
| `POST /users` or `POST /customers` at the plan's limit | **429** with `{"message", "feature", "limit", "used"}` and no `Retry-After`. Try `owner@globex.test` |
| Changing to a plan whose limits are below current usage | **422** on `plan`, one message per feature over the limit. Try `owner@acme.test` with `{"plan": "free"}` |
| `POST /subscription` while one is live · `PATCH` to the same plan · a second cancel | **409** |
| Demoting, disabling or deleting the last active owner | **409** |
| `DELETE /admin/plans/{id}` for a plan any subscription uses | **409**; deactivate it with `is_active: false` instead |
| A tenant whose subscription expired | **404** on `/subscription*`, the dashboard and creates; `POST /subscription` starts a new one |
| Unknown `filter[...]` key, `per_page` over 100, unknown body field | **422** |
| `PATCH /tenant` | Owner and admin, and only your own company: `name` and `timezone`. `slug` or `status` in the body is a **422** |

A subscription runs with `ends_at: null`. Cancelling sets `ends_at` to the end of the current period and it stays `active` until then; the hourly job then marks it `expired`.

## Tests and formatting

```bash
docker compose exec app php artisan test                 # Pest, against the saas_testing database
docker compose exec app ./vendor/bin/pint --test         # Laravel Pint (drop --test to fix)
```

The suite covers tenant isolation, role rules, plan limits, the downgrade refusal, rate limits, cache invalidation and the counters, the expiry sweep, analytics, and exact query counts as N+1 guards.

Both commands also run on GitHub Actions for every push to `master` and every pull request ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)).

## Day-to-day commands

| Purpose | Command |
|---|---|
| Stop (keeps data) | `docker compose down` |
| Logs | `docker compose logs -f app worker scheduler` |
| Shell in the app container | `docker compose exec app bash` |
| Postgres shell | `docker compose exec db psql -U saas -d saas` |
| Cached keys and rate-limit counters | `docker compose exec redis redis-cli -n 1 --scan` |
| Run the subscription sweep now | `docker compose exec app php artisan schedule:test --name='App\Jobs\ProcessDueSubscriptions'` |
| Re-seed from scratch | `docker compose exec app php artisan migrate:fresh --seed --force` then `docker compose exec redis redis-cli FLUSHALL` |
| Wipe the volumes and start again | `docker compose down -v` then `docker compose up -d` |

## Documentation

| Document | Covers |
|---|---|
| [docs/system-design.md](docs/system-design.md) | Stack, multi-tenancy, isolation, auth, authorization, structure, security |
| [docs/database.md](docs/database.md) | Schema, migrations and seeding, indexes, query rules, measurements at 10M rows |
| [docs/api.md](docs/api.md) | Conventions, envelope, pagination and filtering, every endpoint with samples, feature limits |
| [docs/caching.md](docs/caching.md) | What is cached, how it is cleared, rate limits, background jobs |
| Swagger UI | Interactive reference, generated from the code |
