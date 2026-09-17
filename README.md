# SaaS Subscription & Tenant Management API

A multi-tenant REST API for companies (tenants), their staff users and roles, customers, subscription plans with feature limits, and dashboard analytics.

Laravel 13 · PHP 8.4 · PostgreSQL 16 · Redis 7 · Sanctum · Pest · Docker Compose

## Contents

- [Prerequisites](#prerequisites)
- [Setup](#setup)
- [URLs](#urls)
- [Demo accounts](#demo-accounts)
- [Using Swagger UI](#using-swagger-ui)
- [Architecture](#architecture)
- [API behaviour worth knowing](#api-behaviour-worth-knowing)
- [Tests and formatting](#tests-and-formatting)
- [Day-to-day commands](#day-to-day-commands)
- [Known limits and trade-offs](#known-limits-and-trade-offs)
- [Documentation](#documentation)

## Prerequisites

- **Docker** with **Compose v2** (the `docker compose` command, bundled with Docker Desktop). Tested with Compose 2.21. The legacy `docker-compose` v1 is not supported.
- Nothing else: PHP, Composer and Postgres all run in containers. Windows, macOS and Linux all work.
- Free ports: **8000** (API) and **5432** on `127.0.0.1` (Postgres). To use another API port, put `APP_PORT=8080` in a `.env` file next to `docker-compose.yml`.

## Setup

```bash
git clone <repository-url> saas-tenant-api
cd saas-tenant-api
docker compose up -d
```

That is the whole setup. The first run builds the PHP image and installs Composer dependencies, so it takes a few minutes; later runs take seconds.

What `up` does:

| Service | Role |
|---|---|
| `db`, `redis` | Postgres and Redis, with healthchecks; nothing starts until they are ready |
| `setup` | One-shot (`docker/php/setup.sh`): creates `src/.env` from `.env.example` if missing, generates `APP_KEY`, `composer install`, `migrate --force --seed`, prints the URLs and demo logins, then exits |
| `app` | PHP-FPM; starts only after `setup` succeeds |
| `nginx` | Serves the API on port 8000 |
| `worker` | `queue:work` on the default queue |
| `scheduler` | `schedule:work`: queues the hourly subscription expiry sweep and runs the daily expired-token prune |

Setup can run on every `up`. The seeders are idempotent: they never duplicate rows or overwrite your changes. Tests use a separate `saas_testing` database and never touch the seeded data.

Check progress and the printed summary:

```bash
docker compose ps
docker compose logs setup
```

## URLs

| What | URL |
|---|---|
| API base | http://localhost:8000/api/v1 |
| Swagger UI | http://localhost:8000/api/documentation |
| OpenAPI 3.1 spec (JSON) | http://localhost:8000/docs/api.json |

The docs routes are closed when `APP_ENV=production`.

## Demo accounts

The password for every account is **`password`**.

| Email | Role | Tenant | Plan and usage |
|---|---|---|---|
| `admin@platform.test` | `platform_admin` | none | `/admin/*` only; gets 403 on tenant routes |
| `owner@acme.test` | `owner` | Acme Corporation | Pro: 4 of 10 users, 8 of 1,000 customers |
| `admin@acme.test` | `admin` | Acme Corporation | |
| `member1@acme.test` | `member` | Acme Corporation | |
| `member2@acme.test` | `member` | Acme Corporation | |
| `owner@globex.test` | `owner` | Globex Corporation | Free: 3 of 3 users (at the limit), 6 of 10 customers |
| `admin@globex.test` | `admin` | Globex Corporation | |
| `member@globex.test` | `member` | Globex Corporation | |

Plans: **Free** (3 users, 10 customers, 1,000 requests/day), **Pro** (10 users, 1,000 customers, 10,000 requests/day), **Enterprise** (unlimited users and customers, 100,000 requests/day).

Two tenants are seeded so you can check isolation right away. Log in as Acme and request a Globex customer id, and you get 404. Seeded customers are spread over the past 12 months, so `GET /dashboard/analytics` shows a real growth series.

## Using Swagger UI

1. Open http://localhost:8000/api/documentation.
2. Under **Auth**, open `POST /auth/login`, click **Try it out**, and send `{"email": "owner@acme.test", "password": "password"}`.
3. Copy `data.access_token` from the response (the full string, including the `1|` prefix).
4. Click **Authorize** at the top, paste the token (no `Bearer ` prefix), and click **Authorize**. The token is kept across page reloads.
5. Every other endpoint now runs as that user. `POST /auth/logout` revokes the token.

The same flow with curl:

```bash
curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' -d '{"email":"owner@acme.test","password":"password"}'

curl -s http://localhost:8000/api/v1/customers -H 'Authorization: Bearer <access_token>'
```

## Architecture

Each point below links to the doc with the full reasoning.

**Request path.** Route (`auth:sanctum` → `tenant` middleware → `throttle` → `can:<ability>`) → Controller → Form Request → Service → API Resource. One service per domain in `app/Services`. Repositories exist only for cache-backed reads (tenant, plan, live subscription). No per-resource Policy, Action or DTO classes. See [system design §6](docs/system-design.md).

**Multi-tenancy.** One database, shared schema, a `tenant_id` column on every tenant-owned table. The `ResolveTenant` middleware gets the tenant from the token's user (never from the request) and stores it in the `TenantContext` singleton. A global scope on every tenant-owned model filters by that tenant, so a controller that forgets a `where` still cannot read another tenant's rows. The context is cleared after every request and every queued job, because a long-lived worker would otherwise carry one job's tenant into the next. See [system design §2–§3](docs/system-design.md).

**Authorization.** Roles (`owner`, `admin`, `member`, `platform_admin`) are an enum, and the `Ability` enum maps each ability to the roles that hold it. Each ability becomes one Gate, applied on the route. Business rules, such as never granting a role above your own and never removing the last owner, live in the service. See [system design §5](docs/system-design.md).

**Caching (Redis).** Cached: the tenant, each plan with its limits, the active plan list, the tenant's live subscription, and platform analytics. All entries last 24 hours. **Model observers invalidate them after the transaction commits**, so any write path (service, seeder, tinker) clears what it makes stale. Customers, users, usage and the tenant dashboard are deliberately not cached. See [caching §1–§4](docs/caching.md).

**Built for large tenants (target: millions of customers per tenant).**
- **Counters instead of `COUNT(*)`.** `tenant_stats` holds exact user and customer counts. Observers update it inside the same transaction as the insert or delete. Plan-limit checks, usage and unfiltered listing totals read that row instead of counting the table.
- **Precomputed analytics.** `tenant_monthly_stats` stores customers added per month. `platform_stats` and `plan_stats` are refreshed by a queued job, so no analytics request runs an aggregate query.
- **Indexes match the real queries.** They lead with `tenant_id` and cover the two Postgres traps (foreign keys are not auto-indexed; a composite index does not serve its second column alone).

See [database](docs/database.md) §2, §5–§7.

**Rate limiting.** Four named Laravel limiters, all stored in Redis:
- login and register: per email and IP
- register: per IP
- public plan catalogue: per IP
- every authenticated route: per user

See [caching §6](docs/caching.md).

**Background jobs and the scheduler.** Both jobs run on the default queue:
- `RefreshPlatformStats` is debounced and unique across the platform. It rebuilds platform analytics about 30 seconds after a write.
- `ProcessDueSubscriptions` runs hourly. It expires canceled subscriptions once `ends_at` passes. Nothing renews: there is no billing.

See [caching §7](docs/caching.md).

## API behaviour worth knowing

Swagger shows every endpoint's request and response shapes. The spec's own description, at the top of Swagger UI, repeats the rules that apply across endpoints. The behaviours below cannot be inferred from the shapes alone. Full samples are in [docs/api.md](docs/api.md).

| Situation | Response |
|---|---|
| `GET` / `PATCH /tenant` | The caller's own company only; there is no tenant id to address another. `PATCH` (owner, admin) changes `name` and `timezone`; `slug` or `status` in the body is a **422**. A new timezone applies to new customers' dashboard growth month from the next request. |
| Another tenant's id in the URL (`/customers/{id}`, `/users/{id}`) | **404**, identical to a missing record. It never confirms that the record exists. |
| Tenant route called by a user of a **suspended** tenant | **403** `This tenant account is suspended.` A platform admin gets 403 on tenant routes too. |
| `POST /users` or `POST /customers` when the plan's `max_users` / `max_customers` is reached | **429** `{"message", "feature", "limit", "used"}`, with no `Retry-After` (a count limit does not reset over time). Try it as `owner@globex.test`: `POST /users` gives 429. |
| Per-minute throttles (60/min per user, 60/min per IP on `/plans`, 5/min on login and register) | **429** `Too Many Attempts.` with `Retry-After` |
| `PATCH /subscription` (or `POST`) to a plan whose limits are below current usage | **422** on `plan`, one message per exceeded feature. Try it as `owner@acme.test` with `{"plan": "free"}`. |
| `POST /subscription` while a subscription is live · `PATCH` to the current plan · second `DELETE` (cancel) | **409** |
| Demoting, disabling or deleting the last active owner | **409** |
| `DELETE /admin/plans/{id}` for a plan any subscription refers to | **409**; deactivate it with `is_active: false` instead |
| Tenant with no live subscription (a canceled one has expired) | **404** on `/subscription*`, `/dashboard/analytics`, and user/customer creates. `POST /subscription` subscribes again. |
| Unknown `filter[...]` key, `per_page` above 100, or an unknown body field | **422** (never silently ignored) |

Subscription lifecycle:
- A running subscription has `ends_at: null`; it runs until canceled or changed.
- Cancelling sets `canceled_at` and `ends_at` to the end of the current billing period, counted from `starts_at`; the subscription stays `active` until then.
- The hourly sweep then marks it `expired`. There is no automatic fallback to Free.
- There is no payment integration.

With the local `APP_DEBUG=true`, error bodies also include a stack trace; production runs with `APP_DEBUG=false`.

## Tests and formatting

```bash
docker compose exec app php artisan test                 # Pest suite, against the saas_testing database
docker compose exec app ./vendor/bin/pint --test         # Laravel Pint, check only (drop --test to fix)
```

The suite covers:
- tenant isolation: cross-tenant 404s, scope failures when no tenant is set, and context reset between queued jobs
- role rules
- plan limits and the locked counter
- the downgrade refusal
- rate limits
- observer-based cache invalidation and counters
- the expiry sweep
- analytics
- exact query counts on listing and dashboard endpoints (N+1 guards)

## Day-to-day commands

| Purpose | Command |
|---|---|
| Stop (keeps data) | `docker compose down` |
| Logs | `docker compose logs -f app worker scheduler` |
| Shell in the app container | `docker compose exec app bash` |
| Postgres shell | `docker compose exec db psql -U saas -d saas` |
| Cached keys and rate-limit counters | `docker compose exec redis redis-cli -n 1 --scan` |
| Run the subscription sweep now | `docker compose exec app php artisan schedule:test --name='App\Jobs\ProcessDueSubscriptions'` |

**Destructive resets.** None of these run automatically.

| Reset | Command |
|---|---|
| Re-seed the database and clear Redis | `docker compose exec app php artisan migrate:fresh --seed --force` then `docker compose exec redis redis-cli FLUSHALL` |
| Wipe everything (all volumes, the test database included) and start again | `docker compose down -v` then `docker compose up -d` |

## Known limits and trade-offs

These are deliberate, and each is explained in the linked doc.

- **Listing pagination uses `OFFSET`.** A filtered or searched listing also runs `COUNT(*)` for `meta.total`. Unfiltered totals come from `tenant_stats`. The next step would be keyset pagination. See [database §7](docs/database.md).
- **Search is a case-insensitive prefix match** (`lower(col) LIKE 'term%'`), not substring search, which would need `pg_trgm`. See [database §5](docs/database.md).
- **Creates are serialised per tenant.** The exact plan limit locks the tenant's `tenant_stats` row for each user or customer create. See [api §6](docs/api.md).
- **Platform analytics lag writes** by about 30 seconds plus queue wait. Expiry lags `ends_at` by up to an hour.
- **Suspending a tenant does not revoke its tokens.** The middleware refuses them with 403 instead.
- **The containers are for development only.** php-fpm runs as root inside the container, `APP_DEBUG=true`, and there is no TLS.
- **Out of scope:** live deployment, payment capture, a permissions package, a repository for every model, and coverage targets. See [requirements §6](docs/planning/00-requirements.md).

## Documentation

| Document | Covers |
|---|---|
| [docs/system-design.md](docs/system-design.md) | Stack, multi-tenancy, isolation, auth, authorization, structure, security, scalability path |
| [docs/database.md](docs/database.md) | Schema, migrations and seeding, indexing strategy, query optimization, behaviour at scale |
| [docs/api.md](docs/api.md) | Conventions, envelope, pagination and filtering, every endpoint with samples, feature limits, documentation tooling |
| [docs/caching.md](docs/caching.md) | What is cached, invalidation, rate limiting, background jobs and schedule |
| Swagger UI | Interactive reference generated from the code (Scramble) |
