# Database Schema, Optimization & Indexing

Covers submission requirements **#3** (schema and architecture explanation) and **#6** (database optimization and indexing).

PostgreSQL 16. All timestamps are `timestamptz`; the application timezone is UTC and no column stores server-local time.

---

## 1. Entity overview

```
                    ┌──────────┐
                    │  plans   │────< plan_features
                    └────┬─────┘      (limit per feature key)
                         │
                         │ (one active)
                    ┌────┴──────────┐
    ┌──────────────>│ subscriptions │
    │               └───────────────┘
┌───┴─────┐
│ tenants │────< users        (staff of the company)
│(company)│────< customers    (the company's own customers)
│         │────< tenant_monthly_stats  (customers added per month)
└─────────┘──── tenant_stats  (one row: user and customer counts)

plans ──── plan_stats      (one row per plan: live tenants, MRR)
platform_stats             (a single row: platform-wide totals)
```

Two distinct "people" concepts, deliberately separate tables:
- **`users`** — staff of a tenant who log in and hold roles.
- **`customers`** — the tenant's own end-customers, business records that never authenticate.

Merging them behind a `type` column would put nullable auth columns on every customer row and make authorization ambiguous. They are different entities that happen to both have a name and an email.

`plans` and `plan_features` are **platform-owned, not tenant-owned** — they carry no `tenant_id` and are excluded from the tenant global scope.

**Framework tables are kept to the two that need a database.** Cache, queue and sessions all run on Redis, so Laravel's default `cache`, `cache_locks`, `jobs`, `job_batches` and `sessions` tables are not created. `personal_access_tokens` stays because Sanctum tokens must be durable. `failed_jobs` stays because Laravel records failed jobs in the database even when the queue itself is Redis — a failed job is evidence to inspect and retry, not something to lose on a Redis eviction. `password_reset_tokens` is dropped: no password-reset endpoint is in scope.

## 2. Tables

### `tenants`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `name` | varchar(255) | company name |
| `slug` | varchar(255) | **unique**, URL-safe identity |
| `status` | varchar(20) | enum-backed: `active`, `suspended` |
| `timezone` | varchar(64) | IANA name, default `Asia/Dhaka` — see §3 |
| `created_at` / `updated_at` | timestamptz | |

### `users`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `tenant_id` | bigint FK → tenants, **nullable** | null = platform admin, outside all tenancy |
| `name` | varchar(255) | |
| `email` | varchar(255) | **globally unique** — see §3 |
| `password` | varchar(255) | bcrypt |
| `role` | varchar(20) | `owner`, `admin`, `member`; platform admins use `platform_admin` with `tenant_id` null |
| `status` | varchar(20) | `active`, `disabled` |
| `created_at` / `updated_at` | timestamptz | |

`ON DELETE CASCADE` from `tenants`: deleting a company removes its staff.

### `customers`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `tenant_id` | bigint FK → tenants | cascade |
| `name` | varchar(255) | |
| `email` | varchar(255) | **unique per tenant**, not globally |
| `phone` | varchar(32) null | |
| `status` | varchar(20) | `active`, `inactive` |
| `created_at` / `updated_at` | timestamptz | |

### `plans`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `name`, `slug` | varchar | slug **unique** |
| `price_cents` | integer | **integer minor units, never float** — binary floats cannot represent decimal currency exactly |
| `currency` | char(3) | ISO 4217 |
| `billing_period` | varchar(10) | `monthly`, `yearly` |
| `is_active` | boolean | soft retirement: an inactive plan stops being sellable without breaking existing subscriptions |
| `sort_order` | smallint | display ordering |
| `created_at` / `updated_at` | timestamptz | |

### `plan_features`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `plan_id` | bigint FK → plans | cascade |
| `key` | varchar(50) | `max_users`, `max_customers` |
| `limit_value` | integer **nullable** | **null means unlimited** — see §3 |
| `created_at` / `updated_at` | timestamptz | |
| **unique** | `(plan_id, key)` | one limit per feature per plan |

Limits are rows, not columns. Adding a new limit type is a `FeatureKey` case and a row per plan, not a migration on every plan — this is what makes the plan system extensible.

### `subscriptions`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `tenant_id` | bigint FK → tenants | cascade |
| `plan_id` | bigint FK → plans | **restrict** — a plan with live subscriptions cannot be deleted |
| `status` | varchar(20) | `active`, `canceled`, `expired` |
| `starts_at` | timestamptz | |
| `ends_at` | timestamptz, nullable | null while running uncanceled; set by a cancel (end of the current billing period) or a plan change (now) |
| `canceled_at` | timestamptz null | |
| `created_at` / `updated_at` | timestamptz | |

History is preserved: changing plans ends the current subscription and inserts a new row rather than mutating in place, so plan history survives, which is also why a plan any subscription refers to cannot be deleted. The ended row becomes `canceled` with `canceled_at` and `ends_at` set to the moment of the change. Cancelling without a change sets `canceled_at` and `ends_at` (the first billing-period boundary after now, counted from `starts_at` in PHP): the row stays `active` until `ends_at`, so it still holds the one-live-subscription slot.

**Period end** is handled by the hourly `ProcessDueSubscriptions` job ([caching §7](caching.md)): an `active` row whose `ends_at` has passed becomes `expired` (dates kept). Only a canceled live row has an `ends_at`, so the due condition needs no `canceled_at` check. There is no billing, so **nothing renews**: an uncanceled subscription has no period end to act on.

**No usage history table.** Usage is the tenant's current user and customer count, kept in `tenant_stats`, and nothing — usage endpoint, dashboard, analytics — reads past usage, so there is no `feature_usage` table to fill with history no one reads.

### `tenant_stats`
| Column | Type | Notes |
|---|---|---|
| `tenant_id` | bigint **PK**, FK → tenants | cascade; one row per tenant |
| `users_count` | bigint, default 0 | the tenant's users, every status |
| `customers_count` | bigint, default 0 | the tenant's customers |
| `updated_at` | timestamptz null | last counter change; no `created_at` |

**Why a counter table, when a counter can drift from reality.** A tenant is expected to reach millions of customers (measured with 5M in one tenant, §8). A `count(*)` over that tenant's range is an index-only scan linear in its rows, and it ran on every `POST /customers` and `POST /users` (the plan limit), every `GET /subscription/usage`, every plan change (the downgrade rule), per listed tenant in `GET /admin/tenants`, and over the whole table in `GET /admin/analytics`. Every one of those now reads this table — a primary-key lookup or a join — except analytics, which reads `platform_stats`, summed from this table by a job.

**Drift is closed by atomicity, not by recounting.** The `Customer`/`User` observers run **inside the write's transaction** and adjust the counter there — `UPDATE tenant_stats SET customers_count = customers_count + 1 WHERE tenant_id = ?` on `created`, `- 1` on `deleted` — so the count commits or rolls back with the row ([caching §2](caching.md)). A primary-key update is O(1) whatever the tenant's size; recounting 10M rows per write burst is not. `TenantObserver` inserts the zeroed row in the tenant's creating transaction (registration, factories, the seeder), so the owner's increment in the same registration transaction finds it. The seeder inserts through models, so the observers count its rows too.

- **Limits are exact.** `UserService::create()` and `CustomerService::create()` run the limit check and the insert in one transaction, and `ensureWithinLimit()` reads the counter with `SELECT … FOR UPDATE`. A second create for the same tenant waits on that row lock until the first commits and then reads the new count, so concurrent creates at `limit - 1` cannot overshoot. **Accepted cost:** creates (of users and customers alike, since the increment locks the same row) are serialised per tenant for the length of the transaction — a few milliseconds; other tenants are unaffected.
- **Deletes are exact.** The delete runs in a transaction that first re-selects the row `FOR UPDATE`: a second concurrent delete of the same row waits, then finds nothing (404) instead of firing `deleted` a second time and decrementing twice.
- **What bypasses it:** a query-builder `insert()`/`delete()` or raw SQL on `users`/`customers` fires no observer and must adjust the counter itself. Nothing does today; a bulk import would.

### `tenant_monthly_stats`
| Column | Type | Notes |
|---|---|---|
| `tenant_id` | bigint, FK → tenants | cascade |
| `month` | date | first day of the month **in the tenant's timezone** |
| `customers_added` | bigint, default 0 | customers created in that month |

Primary key `(tenant_id, month)`. A row exists only for a month something was added in.

**Why it exists.** The dashboard shows customer growth over twelve months. Computed on the request, that is a `GROUP BY date_trunc('month', created_at)` over the tenant's customers — a range scan linear in the tenant's size, 10M rows at the target scale, on every dashboard view. Stored, it is at most twelve rows read by primary-key range.

**Kept exact the same way as `tenant_stats`.** `CustomerObserver::created` runs, inside the write's transaction, one query-builder `upsert` on `(tenant_id, month)` whose conflict update is `customers_added = tenant_monthly_stats.customers_added + 1`. It is O(1) and commits or rolls back with the customer. The month is the customer's `created_at` in the tenant's timezone, read through `TenantRepository` (the cached tenant, already warm on a request) — so a seeder or a job, which has no `TenantContext`, buckets a customer exactly as a request does. The upsert fires no model event, which is fine: nothing observes this table.
- **Additions only.** A delete does not touch the row: the series answers "customers added per month", and the current total is `tenant_stats.customers_count`. Decrementing would also need the deleted row's `created_at` (one more column on every delete's select) for a figure nobody reads.
- **Changing a tenant's timezone** (`PATCH /tenant`) buckets later customers by the new zone and leaves earlier months as stored; they are not re-bucketed.
- **What bypasses it:** the same as `tenant_stats` — a builder or raw insert on `customers` must add its months itself. The monthly insert takes a row lock on its `(tenant_id, month)`, the same per-tenant serialisation `tenant_stats` already imposes.

### `platform_stats`
| Column | Type | Notes |
|---|---|---|
| `id` | smallint **PK** | always `1`; the table holds one row |
| `tenants_count` | bigint, default 0 | every tenant |
| `suspended_tenants_count` | bigint, default 0 | suspended tenants; the analytics response derives `active` as `tenants_count - suspended_tenants_count` |
| `users_count` / `customers_count` | bigint, default 0 | sums of `tenant_stats`; platform admins are not counted |
| `updated_at` | timestamptz null | last refresh; no `created_at` |

### `plan_stats`
| Column | Type | Notes |
|---|---|---|
| `plan_id` | bigint **PK**, FK → plans | cascade; one row per plan |
| `tenants_count` | bigint, default 0 | `active` subscriptions on the plan |
| `mrr_cents` | bigint, default 0 | `active` subscriptions × price, a yearly price ÷ 12 rounded down, in the plan's currency |
| `updated_at` | timestamptz null | last refresh; no `created_at` |

**Why summary tables for analytics.** `GET /admin/analytics` runs no aggregate at all: it selects the `platform_stats` row and `plan_stats` joined to `plans` for the labels. Every `count`, `sum` and `GROUP BY` runs in the queued `RefreshPlatformStats` job ([caching §7](caching.md)), which recomputes both tables from `tenants`, `tenant_stats` and live `subscriptions` — never incrementally — dispatched by the observers of every model the figures come from. The request cost is two tiny reads however many tenants or subscriptions exist; the job's cost follows the number of tenants (one `tenant_stats` row each) and live subscriptions, never a tenant's customers. No migration backfills the tables: `DatabaseSeeder` runs the job in-process, and before its first run the endpoint answers zeros.

### `personal_access_tokens`
Sanctum's standard table, with its timestamps changed to `timestamptz` like every other table.

## 3. Decisions worth defending

**Email is globally unique on `users`, per-tenant unique on `customers`.**
A user authenticates, so the email must resolve to exactly one account before any tenant is known — a per-tenant unique email would make login ambiguous and force a "which company?" step. Customers never authenticate, so the same email may legitimately exist for two different companies. The consequence — one person cannot be staff at two tenants with one email — is accepted; multi-tenant membership needs a `tenant_user` pivot and is out of scope. The schema can accept that later without touching `customers`.

**`limit_value = NULL` means unlimited, not zero.**
`0` is a meaningful limit (feature disabled). A sentinel like `-1` would sort and aggregate incorrectly. Null is the honest representation of "no ceiling", and the limit check tests for it with `is_null()` before reading or locking the counter.

**One active subscription per tenant, enforced by the database.**
Application checks race under concurrent requests. A Postgres **partial unique index** makes it structurally impossible:
```sql
CREATE UNIQUE INDEX subscriptions_one_active_per_tenant
  ON subscriptions (tenant_id)
  WHERE status = 'active';
```
Historical rows are unaffected because the predicate excludes them. This is a concrete reason the stack is Postgres rather than MySQL, which has no partial indexes.

**Timestamps are stored in UTC; each tenant carries its own display timezone.**
The assignment says nothing about timezones, so this is a decision rather than a requirement. Storage is UTC everywhere (`timestamptz`, `APP_TIMEZONE=UTC`, the database container pinned to `TZ=UTC`) because a tenant in London and a tenant in Dhaka cannot share one local clock — UTC is nobody's local time, which is exactly what makes it neutral. The API returns ISO-8601 with an offset and lets the client render.

A single stored timezone is not enough, though, because **some boundaries are business boundaries, not display**. The dashboard's customer growth is bucketed by *month*: computed in UTC, a customer a Dhaka tenant adds at 3 a.m. local on the 1st would count toward the previous month, which is impossible to explain to them. So `tenants.timezone` (IANA name, default `Asia/Dhaka`) determines where a period starts and ends; the resulting instants are still stored in UTC. Nothing in the schema stores local time.

**Enum values are stored as strings, not integers.** A dump stays readable and a value's meaning survives a code change that reorders an enum.

**Deletes are hard, with `restrict` where history matters.** Soft deletes are not used: they leak into every query and every unique index. Where a record must survive, the state lives in `status`, which is explicit.

## 4. Migration and seeding

Migrations are ordered so foreign keys always resolve: `tenants` → `users` → `plans` → `plan_features` → `subscriptions` → `customers` → `tenant_stats` → `platform_stats` → `plan_stats` → `tenant_monthly_stats`, then one migration that reshapes the listing, search and sweep indexes to the measured query plans (§5, §8).

Seeders produce a reviewable dataset in one command: three plans (Free / Pro / Enterprise with real limits including one unlimited), two tenants with separate users and customers, and one platform admin. **Two tenants is deliberate** — a reviewer can log in as each and confirm isolation without creating data first.

Seeding is **idempotent**, because the `setup` service runs `migrate --force --seed` on every `docker compose up`. Plans and their limits are reference data kept in line with `PlanSeeder`: it reads the existing rows once, compares them in PHP, and writes only new or changed rows in one `upsert` per table (on `slug` and `(plan_id, key)`); an upsert fires no model event, so when it writes anything it drops the plan caches and the platform analytics itself. A re-run writes nothing. Demo tenants, users, customers and subscriptions are created only when missing (tenant `slug`, user `email`, customer `(tenant_id, email)`, one active subscription per tenant) and never overwritten, so a re-run neither duplicates rows nor undoes a reviewer's changes. Globex is on Free with three users, so `max_users` can be hit straight away. Each demo customer is created a fixed number of months before the seeding date, so the dashboard's growth series has a year of history; the observers fill `tenant_monthly_stats` from those dates as they insert.

**Tests never touch the seeded database.** Pest runs against a separate `saas_testing` database on the same Postgres server, created by a container init script. Tests stay on Postgres rather than in-memory SQLite because the behaviour under test is Postgres-specific — the partial unique index that rejects a second live subscription, `timestamptz`, and the `FOR UPDATE` limit check.

## 5. Indexing strategy

Indexes follow the actual query patterns, not a rule of thumb. Two Postgres-specific traps drive most of this list.

> **Trap 1 — Postgres does not auto-index foreign keys.** Unlike MySQL/InnoDB, `foreignId()->constrained()` creates only the FK *constraint*. Without an explicit index, every `WHERE tenant_id = ?` is a sequential scan, and every parent delete scans the child table.
>
> **Trap 2 — a composite index on `(a, b)` does nothing for a query filtering on `b` alone.** It serves `a`, and `a`+`b`. A query on `b` by itself needs its own index.

Because nearly every tenant-owned query is `WHERE tenant_id = ? AND <something>`, the composite is the useful shape and a bare `tenant_id` index is usually redundant — the composite's leading column already covers it.

| Table | Index | Serves |
|---|---|---|
| `tenants` | unique `(slug)` | lookup by slug |
| `users` | unique `(email)` | login |
| `users` | `(tenant_id, created_at desc, id desc)` | the user listing's page, read in order off the index; leading column covers plain `tenant_id` and the FK |
| `users` | `(tenant_id, role, created_at desc, id desc)` | `filter[role]`: the page in order and the `COUNT(*)` index-only; also the last-owner check |
| `users` | `(tenant_id, status, created_at desc, id desc)` | `filter[status]`: the same |
| `users` | `(tenant_id, lower(name) text_pattern_ops)`, `(tenant_id, lower(email) text_pattern_ops)` | `search` — see below |
| `customers` | unique `(tenant_id, email)` | duplicate prevention **and** the `tenant_id` lookup path |
| `customers` | `(tenant_id, created_at desc, id desc)` | the listing's page in its exact order `created_at desc, id desc` — no sort node (growth is stored in `tenant_monthly_stats`, not queried from here) |
| `customers` | `(tenant_id, status, created_at desc, id desc)` | `filter[status]`: the page in order and the `COUNT(*)` index-only |
| `customers` | `(tenant_id, lower(name) text_pattern_ops)`, `(tenant_id, lower(email) text_pattern_ops)` | `search`, as a `BitmapOr` of the two |
| `tenants` | `(lower(name) text_pattern_ops)`, `(lower(slug) text_pattern_ops)` | the admin tenant listing's `search` |
| `subscriptions` | partial unique `(tenant_id) WHERE status = 'active'` | invariant + active-subscription lookup |
| `subscriptions` | `(tenant_id)` | **Trap 1** — the partial index only matches active rows, so plan history and the tenant cascade need a full index |
| `subscriptions` | `(plan_id)` | **Trap 1** — FK has no index otherwise; needed for per-plan analytics |
| `subscriptions` | partial `(ends_at) WHERE status = 'active' AND ends_at IS NOT NULL` | the hourly `ProcessDueSubscriptions` sweep: `ends_at <= now()` is one range over canceled live rows only. Partial, not `(status, ends_at)`: ended history rows also lie past `ends_at`, so the planner over-estimated due rows and seq-scanned the table (§8). `ends_at IS NOT NULL` keeps every running subscription's null out, so the index holds only the few canceled-but-running rows; Postgres proves `ends_at <= now()` implies it and uses the index (checked with `EXPLAIN`) |
| `plan_features` | unique `(plan_id, key)` | limit lookup + FK path |
| `tenant_stats` | primary key `(tenant_id)` | the per-tenant count lookup, the admin listing join and the analytics join. **Trap 1** is covered: the FK column is the primary key, so no separate index |
| `plan_stats` | primary key `(plan_id)` | the analytics join to `plans`. **Trap 1** is covered by the primary key |
| `platform_stats` | primary key `(id)` | the single-row analytics read |
| `tenant_monthly_stats` | primary key `(tenant_id, month)` | the dashboard's twelve-month range (`tenant_id = ? AND month >= ?`) and the observer's `ON CONFLICT` target. **Trap 1** is covered: `tenant_id` leads the primary key |

Search is a case-insensitive prefix match written as `lower(column) LIKE lower('term%')`, not `ILIKE`. `ILIKE` can never use a btree, and a plain `LIKE 'term%'` cannot use `(tenant_id, email)` either, because the database collation is `en_US.utf8`. A btree expression index `(tenant_id, lower(column) text_pattern_ops)` turns the prefix into an index range, and serves `lower() LIKE` only — that is why the query is written that way. Searching two columns is an `OR`, which Postgres answers with a `BitmapOr` of the two indexes. **Accepted cost:** `lower(email)` duplicates the unique `(tenant_id, email)` in size (577 MB each at 5M customers); the unique index cannot serve a prefix under this collation. Full substring search would need a trigram index (`pg_trgm`) — a known limit rather than added speculatively.

**Removed by the reshape migration**, each shown unused or superseded by `EXPLAIN` (§8): `(tenant_id, status)` and `(tenant_id, created_at desc)` on customers and `(tenant_id, status)`/`(tenant_id, role)` on users (replaced by the ordered composites above, which also serve the counts), `subscriptions (status, ends_at)` (replaced by the partial index), `plans (is_active, sort_order)` (a catalogue of a handful of rows is always a sequential scan, and the list is served from cache).

## 6. Query optimization

- **Eager loading everywhere a loop touches a relation.** Plans load `features`; customer and user listings load nothing extra, because their API Resources deliberately do not embed relations. The live subscription joins `plans` for the slug alone (a `belongsTo` needing one column) and takes the plan with its features from the plan cache.
- **Usage is read in one query, never counted on a request**: `max_users` and `max_customers` are scalar subqueries on the `tenant_stats` primary key, in a single `SELECT`. No request path runs `count(*)` over `users` or `customers`; tests assert it from the query log.
- **No unbounded result sets.** Every listing paginates; the maximum `per_page` is capped server-side so a client cannot request the whole table.
- **Aggregates are aggregated in SQL, and off the request path**, not by fetching rows and counting in PHP — `RefreshPlatformStats` runs `count … filter` over `tenants`, `sum()` over `tenant_stats` and a `GROUP BY` over live subscriptions, and stores the results; the analytics endpoint only selects them. The tenant dashboard's growth series is stored per month by `CustomerObserver` (§2 `tenant_monthly_stats`) and its totals are `tenant_stats`, so it aggregates nothing either. Remaining request-path aggregates are the `COUNT(*)` behind a *filtered or searched* listing's `meta.total`, and the admin tenant listing's (§7).
- **N+1 is asserted, not assumed.** Pest tests on the listing and dashboard endpoints assert an exact query count, so a future change that reintroduces an N+1 fails the suite rather than review. Outside production, `Model::preventLazyLoading()` also turns any relation read that was not eager-loaded into an exception.
- **Every query names its columns.** No `SELECT *`, and no shared column constants — each query writes its list inline and selects only what its caller reads: the fields its API Resource exposes, the columns business logic needs (`password` at login, `role`/`status` for the user rules, `timezone` for usage) and relation keys. A column used only in `WHERE` or `ORDER BY` is not selected. This keeps the row width small and lets Postgres serve some queries from the index alone. Saving a model loaded this way is safe: Eloquent writes only dirty attributes, so unselected columns are left untouched. The trap: a `select()` that omits a foreign key silently breaks eager loading, because Laravel has no key to match the relation on, so key columns are always included.
- **Joins and eager loads are chosen, not defaulted to.** A `belongsTo` needing a couple of columns, or a filter/sort on a related column, uses a join with an explicit select. A `hasMany` in a listing is always eager-loaded — **joining it multiplies rows and corrupts `meta.total`**. The admin tenant listing joins `tenant_stats` (one row per tenant, like a `belongsTo`) for its counts instead of `withCount()`, which would count every listed tenant's rows.
- **Verification method:** every request-path query, as Laravel generates it (taken from the query log), is checked with `EXPLAIN (ANALYZE, BUFFERS)` against a 10M-customer sample and must show an index scan, not a sequential scan over a tenant-owned table. Findings are in §8.

## 7. Behaviour at scale

The schema assumes tenants eventually holding millions of rows. Two known limits, stated with their migration path rather than left to be discovered:

**`OFFSET` pagination degrades linearly.** `LIMIT 15 OFFSET 150000` makes Postgres walk every preceding index entry: measured 0.05 ms for page 1, 2 ms for page 1,000, 24 ms for page 10,000 and ~300 ms for page 100,000 of a 5M-customer tenant (§8). Acceptable while pages are browsed from the front, which is the real usage pattern for these listings. The fix, when a tenant's data makes it matter, is **keyset (cursor) pagination** — `WHERE (created_at, id) < (?, ?) ORDER BY created_at DESC, id DESC LIMIT 15` — which the existing `(tenant_id, created_at desc, id desc)` index already serves exactly. The response envelope's `links` are unaffected; only `meta` changes shape.

**`COUNT(*)` for `meta.total` only runs on a narrowed listing.** An unfiltered, unsearched `GET /customers` or `GET /users` — the default view — takes `total` from the tenant's `tenant_stats` counter (exact, see §2) and runs only the page query: `paginate($perPage, total: …)`, so the response shape is Laravel's default pagination, unchanged. With a `filter` or `search`, the count runs over the matching set only — index-only for a status or role filter, a bitmap over the expression indexes for a search — so its cost follows the number of **matches**, not the tenant's size: ~60 µs per 1,000 status matches (250 ms for 4.25M `active`), ~3.3 ms per 1,000 search matches, because a `LIKE` must be rechecked on the heap (0.1 ms for a name, 395 ms for `john` = 118k matches, ~1 s for the one-letter `a` = 379k). **Left as is** (the page itself stays under 1 ms): only a very short prefix on a multi-million-row tenant reaches ~1 s, and capping the count does not help — `count(*)` over a `LIMIT 10001` subquery still took 234 ms for `john`, because the bitmap is built over every match first. The next step, if it matters, is `simplePaginate()` (no `total`) for searched listings, or a minimum search length. The admin tenant listing keeps its count: tenants are few.

**Per-tenant creates are serialised.** The exact plan limit costs a row lock on the tenant's `tenant_stats` row for each user or customer create, so one tenant's creates run one at a time (§2). Deliberate: a create is a single-row insert, and a tenant bulk-loading millions of rows would go through an import path that adjusts the counter once, not through the API. Only the platform analytics trail writes, by one queued refresh (~30 s plus queue wait).

**The subscription sweep is bounded however many subscriptions exist.** It touches only canceled live rows past `ends_at`, found through the partial `(ends_at) WHERE status = 'active' AND ends_at IS NOT NULL` index, with `chunkById(1000)` — `SELECT id, tenant_id … WHERE id > ? ORDER BY id LIMIT 1000`, then one `UPDATE … WHERE id IN (…)` per chunk. `chunkById`, not `chunk`: the update removes rows from the `WHERE`, which would shift an offset and skip rows. Memory holds at most 1,000 ids, and the query count depends on the number of chunks due (2 when under 1,000), not on the table size. No lock: a row an owner changes plan on between the select and the update can end up `expired` instead of `canceled` — both are ended history, and the new live row is untouched.

**What already holds at scale:** every tenant-scoped query is served by an index whose leading column is `tenant_id`, so a tenant's working set is a contiguous index range regardless of how many other tenants exist. Growth in the number of tenants therefore does not slow any single tenant's queries — which is the property that matters for a multi-tenant SaaS.

**The admin tenant listing still counts.** `meta.total` is a `COUNT(*)` over `tenants` left-joined to the live subscription (the join is not removed, because the partial unique index does not prove it unique to the planner): 31 ms at 100,000 tenants, linear in tenants, never in customers. Its page is 0.1 ms, and a search or filter is index-served.

## 8. Measured at scale

**Sample.** A throwaway `saas_perf` database on the same Postgres 16 container (default `shared_buffers` 128 MB, `work_mem` 4 MB), migrated with the real migrations and filled with set-based `INSERT … SELECT generate_series` — 100,000 tenants; **9,999,970 customers**: one tenant with 5,000,000, two with 2,000,000, the rest 10 each; **1,499,991 users**: 1,000,000 / 100,000 / 100,000, then 3 per tenant; 300,000 subscriptions (two ended rows of history and one live row per tenant, ~1% of live rows due); `tenant_stats` and `tenant_monthly_stats` (1,000,045 rows) filled with `GROUP BY` from the rows themselves, then `VACUUM ANALYZE`. Names are drawn with a skew from 100 first × 80 last names, e-mails are `first.last{n}@{one of 10 domains}`, 85% of customers `active`, `created_at` spread evenly over two years in insertion order. The database was dropped afterwards; the load is test-only, so no command for it ships.

```sql
insert into customers (tenant_id, name, email, phone, status, created_at, updated_at)
select t.tenant_id, fn || ' ' || ln,
  lower(fn) || '.' || lower(ln) || s.g || '@' || d.a[1 + floor(random() * 10)::int],
  case when random() < 0.7 then '+8801' || lpad(floor(random() * 1e9)::text, 9, '0') end,
  case when random() < 0.85 then 'active' else 'inactive' end, ts, ts
from tenant_sizes t
cross join lateral generate_series(1, t.customers) s(g)
cross join first_names f cross join last_names l cross join domains d
cross join lateral (select f.a[1 + floor(power(random(), 2) * cardinality(f.a))::int] fn,
                           l.a[1 + floor(power(random(), 2) * cardinality(l.a))::int] ln,
                           now() - interval '730 days' * (1 - s.g::float / t.customers) ts) x
order by ts;
```

**Method.** Each endpoint was called through the HTTP kernel against `saas_perf` with the query log on, and every distinct statement was run with `EXPLAIN (ANALYZE, BUFFERS)` twice inside a rolled-back transaction; the table shows the second (warm) run, on the 5M-customer / 1M-user tenant. The migration `2026_09_17_100004_reshape_listing_search_and_sweep_indexes` took 73 s on this data.

| Query (as generated) | Before | After | Plan after |
|---|---:|---:|---|
| `GET /customers` page | 0.07 ms | 0.06 ms | index scan `(tenant_id, created_at desc, id desc)`, no incremental sort; `total` from `tenant_stats` |
| `GET /customers?filter[status]=inactive` page | 0.14 ms | 0.06 ms | index scan on the status composite |
| … its `COUNT(*)` (750k / 4.25M matches) | 25 / 123 ms | 41 / 250 ms | index-only scan on the status composite (wider than the old `(tenant_id, status)`) |
| `GET /customers?search=john.smith12` page | **2,246 ms** | 0.9 ms | `BitmapOr` of the two expression indexes, top-N sort; before: walked the created_at index filtering 3.7M rows |
| `?search=…` `COUNT(*)`, no match / 118k matches | **1,023 / 1,019 ms** | 0.07 / 395 ms | bitmap heap scan; before: parallel seq scan of the table |
| `?search=john&filter[status]=inactive` count / page | 529 / 2.9 ms | 391 / 0.6 ms | bitmap on the expression indexes / status composite |
| `GET /users` page | **132 ms** | 0.07 ms | index scan `(tenant_id, created_at desc, id desc)`; before: parallel seq scan + sort |
| `GET /users?filter[role]=admin` / `[status]=disabled` page | 76 / 69 ms | 0.06 / 0.05 ms | the role / status composite; counts 6 / 12 ms index-only |
| `?filter[role]=admin&filter[status]=active` count / page | 68 / 77 ms | 74 / 0.06 ms | role composite, status as a filter |
| `GET /users?search=mary` count / page | 212 / 216 ms | 75 / 0.2 ms | expression indexes / created_at composite |
| `GET /admin/tenants?search=mega` count / page | 25 / 46 ms | 0.08 / 0.12 ms | `BitmapOr` on `lower(name)`/`lower(slug)` |
| `GET /admin/tenants` count / page | 32 / 0.16 ms | 31 / 0.12 ms | count: hash join over live subscriptions (§7); page: backward PK scan + three PK/partial-index lookups |
| `ProcessDueSubscriptions` expire batch | 30 ms | 4 ms | partial `(ends_at) WHERE status = 'active'` (measured before renewal was removed and the predicate gained `AND ends_at IS NOT NULL`, which only shrinks the index); before: seq scan of all 300k subscription rows |
| show / update / delete a customer or user; the locked `tenant_stats` read; the unique-email check; `tenant_monthly_stats` upsert; login by email | ≤ 0.2 ms | ≤ 0.2 ms | primary key, `tenant_stats` PK, unique indexes — unchanged |
| `GET /dashboard/analytics`, `/subscription/usage`, `/admin/analytics` | ≤ 0.1 ms | ≤ 0.1 ms | `tenant_stats` PK, `tenant_monthly_stats` PK range, the two summary tables — unchanged |
| `GET /subscription`, `/tenant`, `/auth/me` on a warm cache | 0 queries | 0 queries | Redis; only Sanctum's token and user lookups reach Postgres |
| `RefreshPlatformStats` (job) | 30 + 34 ms | 28 + 31 ms | seq scans of `tenants`/`tenant_stats` (one row per tenant, off the request path); live subscriptions via the partial unique index |

**Deep pages** of the 5M-customer tenant, unfiltered: page 1 0.05 ms, page 1,000 2.2 ms, page 10,000 24 ms, page 100,000 ~300 ms (575 ms before the `id` column joined the index). `filter[status]=inactive`: page 1,000 4.6 ms, page 10,000 ~80 ms. **Search counts** by prefix on the same tenant: `rahman` 0.05 ms, `john.smith` 21 ms (5k matches), `john` 395 ms (118k), `jo` 690 ms (224k), `a` ~1,000 ms (379k, where the planner prefers a parallel seq scan). On a 2M-customer tenant `john` counts in 90 ms and `filter[status]=active` in 52 ms.
