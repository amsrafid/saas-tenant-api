# Database Schema, Optimization & Indexing

Covers submission requirements **#3** (schema and architecture) and **#6** (optimization and indexing).

PostgreSQL 16. Every timestamp is `timestamptz` and stored in UTC.

---

## 1. Tables at a glance

```
                    ┌──────────┐
                    │  plans   │────< plan_features
                    └────┬─────┘      (one limit per feature key)
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

`users` and `customers` are two different things, so they are two tables. Users are staff: they log in and hold a role. Customers are the company's own records: they never log in.

`plans` and `plan_features` belong to the platform, not to a tenant. They carry no `tenant_id`.

## 2. Columns

### `tenants`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `name` | varchar(255) | company name |
| `slug` | varchar(255) | **unique**, URL-safe |
| `status` | varchar(20) | `active`, `suspended` |
| `timezone` | varchar(64) | IANA name, default `Asia/Dhaka` — see §3 |
| `created_at` / `updated_at` | timestamptz | |

### `users`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `tenant_id` | bigint FK → tenants, **nullable** | null = platform admin |
| `name` | varchar(255) | |
| `email` | varchar(255) | **unique across all tenants** — see §3 |
| `password` | varchar(255) | bcrypt |
| `role` | varchar(20) | `owner`, `admin`, `member`, or `platform_admin` with a null tenant |
| `status` | varchar(20) | `active`, `disabled` |
| `created_at` / `updated_at` | timestamptz | |

Deleting a tenant cascades to its users.

### `customers`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `tenant_id` | bigint FK → tenants | cascade |
| `name` | varchar(255) | |
| `email` | varchar(255) | **unique inside the tenant** |
| `phone` | varchar(32) null | |
| `status` | varchar(20) | `active`, `inactive` |
| `created_at` / `updated_at` | timestamptz | |

### `plans`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `name`, `slug` | varchar | slug **unique** |
| `price_cents` | integer | **minor units, never a float** — a float cannot hold a decimal price exactly |
| `currency` | char(3) | ISO 4217 |
| `billing_period` | varchar(10) | `monthly`, `yearly` |
| `is_active` | boolean | an inactive plan stops being sellable; existing subscriptions keep working |
| `sort_order` | smallint | display order |
| `created_at` / `updated_at` | timestamptz | |

### `plan_features`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `plan_id` | bigint FK → plans | cascade |
| `key` | varchar(50) | `max_users`, `max_customers` |
| `limit_value` | integer **nullable** | **null = unlimited** — see §3 |
| `created_at` / `updated_at` | timestamptz | |
| **unique** | `(plan_id, key)` | one limit per feature per plan |

Limits are rows, not columns. A new limit is one `FeatureKey` case and one row per plan — no migration on the `plans` table.

### `subscriptions`
| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `tenant_id` | bigint FK → tenants | cascade |
| `plan_id` | bigint FK → plans | **restrict** — a plan in use cannot be deleted |
| `status` | varchar(20) | `active`, `canceled`, `expired` |
| `starts_at` | timestamptz | |
| `ends_at` | timestamptz, nullable | null while running; set by a cancel or a plan change |
| `canceled_at` | timestamptz null | |
| `created_at` / `updated_at` | timestamptz | |

Changing plan does not edit the row. It ends the current one and inserts a new one, so the history survives — which is also why a plan with any subscription cannot be deleted.

Cancelling sets `canceled_at` and `ends_at` (the end of the current period). The row stays `active` until then, so it still holds the tenant's one live slot. The hourly job ([caching §5](caching.md)) then marks it `expired`.

### `tenant_stats`
| Column | Type | Notes |
|---|---|---|
| `tenant_id` | bigint **PK**, FK → tenants | one row per tenant |
| `users_count` | bigint, default 0 | every status |
| `customers_count` | bigint, default 0 | |
| `updated_at` | timestamptz null | |

**What this is for.** A tenant can hold millions of customers (5M in the test, §7). Counting them with `count(*)` is work that grows with the tenant, and it was needed on every customer create, every user create, every usage read, every plan change, and once per row in the admin listing. All of those now read this one row instead.

**Why it cannot drift.** The `Customer` and `User` observers change the counter **inside the same transaction as the row** — `SET customers_count = customers_count + 1 WHERE tenant_id = ?`. The count commits with the row, or rolls back with it. A primary-key update costs the same whether the tenant holds 10 rows or 10 million.

Two more guards:

- **The plan limit is exact.** The create checks the limit and inserts in one transaction, and reads the counter `FOR UPDATE`. Two creates at `limit - 1` cannot both pass. The cost: one tenant's creates queue behind each other for a few milliseconds.
- **A delete cannot subtract twice.** It re-reads the row `FOR UPDATE` first, so a second concurrent delete finds nothing and 404s.

### `tenant_monthly_stats`
| Column | Type | Notes |
|---|---|---|
| `tenant_id` | bigint, FK → tenants | cascade |
| `month` | date | first day of the month, **in the tenant's timezone** |
| `customers_added` | bigint, default 0 | |

Primary key `(tenant_id, month)`. A row exists only for a month that had an addition.

The dashboard shows twelve months of customer growth. Counted live that is a `GROUP BY` over the tenant's whole customer table. Stored, it is at most twelve rows read by primary key.

`CustomerObserver::created` keeps it correct the same way as `tenant_stats`: one `upsert` inside the write's transaction that adds 1 to the month. The month comes from the customer's `created_at` in the tenant's timezone, so a seeder or a job buckets it exactly as a request would.

The row counts additions. A delete leaves the month alone; the current total is `tenant_stats.customers_count`.

### `platform_stats`
| Column | Type | Notes |
|---|---|---|
| `id` | smallint **PK** | always `1` — the table holds one row |
| `tenants_count` | bigint, default 0 | |
| `suspended_tenants_count` | bigint, default 0 | the response derives `active` as the difference |
| `users_count` / `customers_count` | bigint, default 0 | sums of `tenant_stats`; platform admins are not counted |
| `updated_at` | timestamptz null | |

### `plan_stats`
| Column | Type | Notes |
|---|---|---|
| `plan_id` | bigint **PK**, FK → plans | one row per plan |
| `tenants_count` | bigint, default 0 | live subscriptions on the plan |
| `mrr_cents` | bigint, default 0 | a yearly price counts as price ÷ 12, rounded down |
| `updated_at` | timestamptz null | |

These two tables exist so that `GET /admin/analytics` runs no `count`, `sum` or `GROUP BY`. It reads the one `platform_stats` row and joins `plan_stats` to `plans` for the names. All the counting happens in the queued `RefreshPlatformStats` job ([caching §5](caching.md)), which rebuilds both tables from `tenants`, `tenant_stats` and the live subscriptions.

### `personal_access_tokens`
Sanctum's table, with its timestamps changed to `timestamptz`.

## 3. Decisions

**A user's email is unique everywhere; a customer's email is unique inside its tenant.** A user logs in, so the email has to point at exactly one account before any tenant is known. A customer never logs in, so two companies may both have the same customer email.

**`limit_value = NULL` means unlimited.** `0` already means something — the feature is off. A number like `-1` would sort and sum wrongly.

**One live subscription per tenant, enforced by Postgres.** An application check can race; an index cannot:
```sql
CREATE UNIQUE INDEX subscriptions_one_active_per_tenant
  ON subscriptions (tenant_id)
  WHERE status = 'active';
```
Old rows are outside the index because of the `WHERE`.

**Timestamps are UTC; each tenant carries a display timezone.** Storage is UTC everywhere and the API returns ISO-8601 with an offset. The tenant's timezone is still needed because a month is a business boundary: for a Dhaka tenant, a customer added at 3 a.m. local on the 1st must count in that month, not the one before.

**Enums are stored as strings**, so a dump stays readable and the meaning survives reordering the enum.

**Deletes are real deletes.** Where a row must survive, the state lives in `status`, and the foreign key is `restrict`.

## 4. Migrations and seeding

Migrations run in foreign-key order: `tenants` → `users` → `plans` → `plan_features` → `subscriptions` → `customers` → `tenant_stats` → `platform_stats` → `plan_stats` → `tenant_monthly_stats`, then one migration that reshapes the listing and search indexes to the measured plans (§5, §7).

The seed gives a reviewable dataset in one command: three plans (Free, Pro, Enterprise — one of them unlimited), two tenants with their own users and customers, and one platform admin. Two tenants means isolation can be checked immediately. Globex sits on Free with 3 of 3 users, so the limit can be hit straight away, and the demo customers are spread over the past year, so the dashboard has a real growth curve.

Seeding is **idempotent**, because `setup` runs `migrate --force --seed` on every `docker compose up`. `PlanSeeder` reads the current rows, compares them in PHP, and writes only what changed. Demo rows are created only when missing, so a re-run neither duplicates data nor overwrites a reviewer's changes.

Tests run against a separate `saas_testing` database, on Postgres rather than SQLite, because the behaviour under test is Postgres-specific: the partial unique index, `timestamptz`, and the `FOR UPDATE` limit check.

## 5. Indexes

Two Postgres facts shape this list:

> **Postgres does not index a foreign key for you.** `foreignId()->constrained()` creates the constraint only. Without an index, `WHERE tenant_id = ?` is a sequential scan and every parent delete scans the child table.
>
> **An index on `(a, b)` does nothing for a query on `b` alone.** It serves `a`, and `a` with `b`.

Almost every tenant query is `WHERE tenant_id = ? AND <something>`, so the composite is the useful shape, and it already covers plain `tenant_id`.

| Table | Index | Serves |
|---|---|---|
| `tenants` | unique `(slug)` | lookup by slug |
| `tenants` | `(lower(name) text_pattern_ops)`, `(lower(slug) text_pattern_ops)` | admin tenant search |
| `users` | unique `(email)` | login |
| `users` | `(tenant_id, created_at desc, id desc)` | the listing page, read in order off the index |
| `users` | `(tenant_id, role, created_at desc, id desc)` | `filter[role]` — page in order, and the count index-only; also the owner check |
| `users` | `(tenant_id, status, created_at desc, id desc)` | `filter[status]` |
| `users` | `(tenant_id, lower(name) …)`, `(tenant_id, lower(email) …)` | `search` |
| `customers` | unique `(tenant_id, email)` | duplicate check, and the `tenant_id` path |
| `customers` | `(tenant_id, created_at desc, id desc)` | the listing page, no sort step |
| `customers` | `(tenant_id, status, created_at desc, id desc)` | `filter[status]` |
| `customers` | `(tenant_id, lower(name) …)`, `(tenant_id, lower(email) …)` | `search` |
| `subscriptions` | partial unique `(tenant_id) WHERE status = 'active'` | the one-live-subscription rule, and the lookup |
| `subscriptions` | `(tenant_id)` | history and the tenant cascade — the partial index only covers live rows |
| `subscriptions` | `(plan_id)` | per-plan analytics |
| `subscriptions` | partial `(ends_at) WHERE status = 'active' AND ends_at IS NOT NULL` | the hourly expiry sweep; the index holds only the few canceled-but-running rows |
| `plan_features` | unique `(plan_id, key)` | limit lookup |
| `tenant_stats` | PK `(tenant_id)` | the count read, and the admin listing join |
| `plan_stats` | PK `(plan_id)` | the analytics join |
| `platform_stats` | PK `(id)` | the single row |
| `tenant_monthly_stats` | PK `(tenant_id, month)` | the dashboard's twelve-month range |

**Search** is written as `lower(column) LIKE lower('term%')`, never `ILIKE`. `ILIKE` cannot use a btree at all, and plain `LIKE` cannot use the normal index either, because the collation is `en_US.utf8`. An expression index on `lower(column) text_pattern_ops` turns the prefix into an index range. Two columns means an `OR`, which Postgres answers by combining both indexes.

Each listing index ends in the exact listing order, `created_at desc, id desc`, so a page comes straight off the index with no sort step.

## 6. Query rules

- **Relations are eager loaded** wherever a loop would touch them. Plans load their `features`; the customer and user listings load nothing extra, because their responses embed nothing.
- **Usage is one query.** `max_users` and `max_customers` are subqueries on the `tenant_stats` primary key. Neither a usage read nor a plan-limit check ever counts rows, and a plain listing takes `meta.total` from the same row — tests check this from the query log. A filtered or searched listing counts the matching rows, which the indexes above serve.
- **Every listing paginates**, and `per_page` is capped at 100.
- **Counting happens in a job**, not in a request. `RefreshPlatformStats` stores its results; the analytics endpoint only reads them. The dashboard's monthly series is stored by an observer.
- **N+1 is a failing test, not a review note.** The listing and dashboard tests assert an exact query count. Outside production, `Model::preventLazyLoading()` turns a missed eager load into an exception.
- **Every query names its columns.** No `SELECT *`. A query selects what its response and its rules need, plus the keys a relation matches on — leaving a key out silently breaks eager loading.
- **Join or eager load is a choice.** A `belongsTo` needing a couple of columns is a join. A `hasMany` in a listing is always eager loaded, because joining it multiplies rows and breaks `meta.total`. The admin listing joins `tenant_stats`, which is one row per tenant, instead of `withCount()`.

## 7. Measured at scale

**The sample.** A throwaway database on the same Postgres container, filled with `INSERT … SELECT generate_series`: 100,000 tenants; **9,999,970 customers** (one tenant with 5,000,000, two with 2,000,000, the rest 10 each); **1,499,991 users**; 300,000 subscriptions. Then `VACUUM ANALYZE`.

**The method.** Every endpoint was called through the HTTP kernel with the query log on, and each statement it produced was run twice under `EXPLAIN (ANALYZE, BUFFERS)`. The table shows the warm run, on the 5M-customer tenant.

| Query | Before | After | Plan after |
|---|---:|---:|---|
| `GET /customers` page | 0.07 ms | 0.06 ms | index scan `(tenant_id, created_at desc, id desc)`, no sort; `total` from `tenant_stats` |
| … its count (750k / 4.25M matches) | 25 / 123 ms | 41 / 250 ms | index-only scan on the status composite |
| `GET /customers?search=john.smith12` page | **2,246 ms** | 0.9 ms | both expression indexes combined; before, it filtered 3.7M rows |
| … its count, no match / 118k matches | **1,023 / 1,019 ms** | 0.07 / 395 ms | bitmap heap scan; before, a parallel sequential scan |
| `GET /users` page | **132 ms** | 0.07 ms | index scan; before, a sequential scan plus a sort |
| `GET /admin/tenants?search=mega` count / page | 25 / 46 ms | 0.08 / 0.12 ms | both `lower()` indexes on `tenants` |
| `ProcessDueSubscriptions` batch | 30 ms | 4 ms | the partial `ends_at` index; before, a scan of all 300k rows |
| show / update / delete a row, the locked counter read, the email check, login | ≤ 0.2 ms | ≤ 0.2 ms | primary keys and unique indexes |
| `/dashboard/analytics`, `/subscription/usage`, `/admin/analytics` | ≤ 0.1 ms | ≤ 0.1 ms | the stored counters and summary tables |
| `/subscription`, `/tenant`, `/auth/me` warm | 0 queries | 0 queries | Redis; only Sanctum's own lookups reach Postgres |

The listing page costs the same on a tenant with 10 customers and a tenant with 5,000,000: **0.1 ms either way**, because `tenant_id` leads the index.
