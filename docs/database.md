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
└─────────┘────< feature_usage (metered consumption per period)
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
| `trial_ends_at` | timestamptz null | |
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
| `status` | varchar(20) | `active`, `invited`, `disabled` |
| `email_verified_at`, `last_login_at` | timestamptz null | |
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
| `metadata` | jsonb null | tenant-defined extra fields without a schema change |
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

### `plan_features`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `plan_id` | bigint FK → plans | cascade |
| `key` | varchar(50) | `max_users`, `max_customers`, `api_requests_per_day`, `storage_mb` |
| `limit_value` | integer **nullable** | **null means unlimited** — see §3 |
| **unique** | `(plan_id, key)` | one limit per feature per plan |

Limits are rows, not columns. Adding a new limit type is an insert plus a checker class, not a migration on every plan — this is what makes the plan system extensible.

### `subscriptions`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `tenant_id` | bigint FK → tenants | cascade |
| `plan_id` | bigint FK → plans | **restrict** — a plan with live subscriptions cannot be deleted |
| `status` | varchar(20) | `trialing`, `active`, `past_due`, `canceled`, `expired` |
| `starts_at`, `ends_at` | timestamptz | |
| `canceled_at` | timestamptz null | |

History is preserved: changing plans ends the current subscription and inserts a new row rather than mutating in place, so the analytics endpoint can report plan history and churn.

### `feature_usage`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `tenant_id` | bigint FK → tenants | cascade |
| `feature_key` | varchar(50) | |
| `period_start` | date | billing period bucket |
| `used` | integer | atomic increment |
| **unique** | `(tenant_id, feature_key, period_start)` | idempotent upsert target |

Only **metered** features (API requests) need rows here. **Count-based** limits (users, customers) are derived with a `COUNT` against the source table and cached — storing a counter for something already countable invites drift between the counter and reality.

### `personal_access_tokens`
Sanctum's standard table, with its timestamps changed to `timestamptz` like every other table.

## 3. Decisions worth defending

**Email is globally unique on `users`, per-tenant unique on `customers`.**
A user authenticates, so the email must resolve to exactly one account before any tenant is known — a per-tenant unique email would make login ambiguous and force a "which company?" step. Customers never authenticate, so the same email may legitimately exist for two different companies. The consequence — one person cannot be staff at two tenants with one email — is accepted; multi-tenant membership needs a `tenant_user` pivot and is out of scope. The schema can accept that later without touching `customers`.

**`limit_value = NULL` means unlimited, not zero.**
`0` is a meaningful limit (feature disabled). A sentinel like `-1` would sort and aggregate incorrectly. Null is the honest representation of "no ceiling", and every checker treats it explicitly.

**One active subscription per tenant, enforced by the database.**
Application checks race under concurrent requests. A Postgres **partial unique index** makes it structurally impossible:
```sql
CREATE UNIQUE INDEX subscriptions_one_active_per_tenant
  ON subscriptions (tenant_id)
  WHERE status IN ('trialing', 'active');
```
Historical rows are unaffected because the predicate excludes them. This is a concrete reason the stack is Postgres rather than MySQL, which has no partial indexes.

**Timestamps are stored in UTC; each tenant carries its own display timezone.**
The assignment says nothing about timezones, so this is a decision rather than a requirement. Storage is UTC everywhere (`timestamptz`, `APP_TIMEZONE=UTC`, the database container pinned to `TZ=UTC`) because a tenant in London and a tenant in Dhaka cannot share one local clock — UTC is nobody's local time, which is exactly what makes it neutral. The API returns ISO-8601 with an offset and lets the client render.

A single stored timezone is not enough, though, because **some boundaries are business boundaries, not display**. `api_requests_per_day` resets at a *day* boundary: computed in UTC, a Dhaka tenant's quota would reset at 6 a.m. local, which is impossible to explain to them. The same applies to "today" in dashboard analytics. So `tenants.timezone` (IANA name, default `Asia/Dhaka`) determines where a period starts and ends; the resulting instants are still stored in UTC. Nothing in the schema stores local time.

**Enum values are stored as strings, not integers.** A dump stays readable and a value's meaning survives a code change that reorders an enum.

**Deletes are hard, with `restrict` where history matters.** Soft deletes are not used: they leak into every query and every unique index. Where a record must survive, the state lives in `status`, which is explicit.

## 4. Migration and seeding

Migrations are ordered so foreign keys always resolve: `tenants` → `users` → `plans` → `plan_features` → `subscriptions` → `customers` → `feature_usage`.

Seeders produce a reviewable dataset in one command: three plans (Free / Pro / Enterprise with real limits including one unlimited), two tenants with separate users and customers, and one platform admin. **Two tenants is deliberate** — a reviewer can log in as each and confirm isolation without creating data first.

**Tests never touch the seeded database.** Pest runs against a separate `saas_testing` database on the same Postgres server, created by a container init script. Tests stay on Postgres rather than in-memory SQLite because the behaviour under test is Postgres-specific — partial unique indexes, `timestamptz`, and concurrent subscribe (T-06).

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
| `users` | `(tenant_id, status)` | tenant user listing + status filter; leading column covers plain `tenant_id` |
| `users` | `(tenant_id, role)` | role filter, `max_users` count |
| `customers` | unique `(tenant_id, email)` | duplicate prevention **and** the `tenant_id` lookup path |
| `customers` | `(tenant_id, status)` | listing with status filter |
| `customers` | `(tenant_id, created_at desc)` | default listing order + analytics growth queries |
| `subscriptions` | partial unique `(tenant_id) WHERE status IN ('trialing','active')` | invariant + active-subscription lookup |
| `subscriptions` | `(plan_id)` | **Trap 1** — FK has no index otherwise; needed for per-plan analytics |
| `subscriptions` | `(status, ends_at)` | the expiry sweep job |
| `plan_features` | unique `(plan_id, key)` | limit lookup + FK path |
| `plans` | `(is_active, sort_order)` | the public plan list |
| `feature_usage` | unique `(tenant_id, feature_key, period_start)` | upsert target and read path |

`customers.name`/`email` search uses `ILIKE 'term%'` on a prefix match, which a btree index can serve. Full substring search would need a trigram index (`pg_trgm`) — noted as a known limit rather than added speculatively.

## 6. Query optimization

- **Eager loading everywhere a loop touches a relation.** `Subscription` always loads `plan.features`; customer and user listings load nothing extra, because their API Resources deliberately do not embed relations.
- **No unbounded result sets.** Every listing paginates; the maximum `per_page` is capped server-side so a client cannot request the whole table.
- **Aggregates are aggregated in SQL**, not by fetching rows and counting in PHP — `COUNT`/`GROUP BY` for analytics, `withCount()` for related counts.
- **N+1 is asserted, not assumed.** Pest tests on the listing and dashboard endpoints assert an exact query count, so a future change that reintroduces an N+1 fails CI rather than review.
- **Every query names its columns.** No `SELECT *` — a listing selects only what its API Resource exposes, which keeps the row width small and lets Postgres serve some queries from the index alone. The trap: a `select()` that omits a foreign key silently breaks eager loading, because Laravel has no key to match the relation on, so key columns are always included.
- **Joins and eager loads are chosen, not defaulted to.** A `belongsTo` needing a couple of columns, or a filter/sort on a related column, uses a join with an explicit select. A `hasMany` in a listing is always eager-loaded — **joining it multiplies rows and corrupts `meta.total`**. `withCount()` is used where only the number of related rows matters.
- **Verification method:** each listing and analytics query is checked with `EXPLAIN ANALYZE` against seeded data and must show an index scan, not a sequential scan. Findings are recorded in the README's performance notes.

## 7. Behaviour at scale

The schema assumes tenants eventually holding millions of rows. Two known limits, stated with their migration path rather than left to be discovered:

**`OFFSET` pagination degrades linearly.** `LIMIT 15 OFFSET 150000` makes Postgres walk every preceding row. Acceptable while pages are browsed from the front, which is the real usage pattern for these listings. The fix, when a tenant's data makes it matter, is **keyset (cursor) pagination** — `WHERE (created_at, id) < (?, ?) ORDER BY created_at DESC, id DESC LIMIT 15` — which the existing `(tenant_id, created_at desc)` index already serves. The response envelope's `links` are unaffected; only `meta` changes shape.

**`COUNT(*)` for `meta.total` is the first thing that slows down.** Every listing request currently counts the whole filtered set to report `total` and `last_page`. On a large table that count costs more than the page itself. Three options, in order of preference as data grows: cache the count per filter combination with observer invalidation; switch to `simplePaginate()` and drop `total`; or fall back to an approximate count from `pg_class.reltuples` for unfiltered listings. None is implemented now — the tables are small and `total` is genuinely useful to a client — but the trade-off is explicit, and the cursor path above removes the need for it entirely.

**What already holds at scale:** every tenant-scoped query is served by an index whose leading column is `tenant_id`, so a tenant's working set is a contiguous index range regardless of how many other tenants exist. Growth in the number of tenants therefore does not slow any single tenant's queries — which is the property that matters for a multi-tenant SaaS.
