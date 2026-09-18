# Caching, Rate Limiting & Background Jobs

Covers submission requirement **#5**. Redis holds the cache, the queue and the rate-limit counters.

---

## 1. What is cached

Five keys. Each is read with `Cache::remember()` and lives 24 hours. Each one is cleared by a model observer (§2), so the TTL is a safety net, not the plan.

| Key | Holds | Read on |
|---|---|---|
| `tenant:{id}` | the tenant row | every tenant request, `/auth/me`, login |
| `plan:{slug}` | one plan with its feature limits | registration, `GET /plans/{slug}`, every user or customer create |
| `plans:active` | the whole active plan catalogue | `GET /plans` |
| `tenant:{id}:subscription` | the tenant's live subscription and its plan slug | every `/subscription` call, the dashboard, every create |
| `platform:analytics` | the admin analytics response | `GET /admin/analytics` |

Three things are worth knowing:

- **Each key stores only the columns its readers use**, not the whole row.
- **The subscription key stores no limits.** It stores the plan's slug, and the limits come from `plan:{slug}`. So when an admin edits a plan's limits, every tenant on that plan sees the change on the next request. Nothing has to be cleared per tenant.
- **A miss on `platform:analytics` still runs no `count` or `sum`.** The numbers are already stored in two summary tables, filled by a background job (§5). A hit runs no query at all.

Customers and users are not cached. They are read from indexed queries, and a plain listing's total comes from the stored counter ([database §5–§7](database.md)).

## 2. How the cache is cleared

The rule: **the model clears its own cache, through an observer.** Not the service that writes.

Why this way: every write goes through Eloquent — a service, a seeder, `tinker`, a test factory. An observer catches all of them. A new service cannot forget to clear anything.

Two details that matter:

- **Clearing runs after the transaction commits.** If it ran before, another request could read the old row and cache it again. A write that rolls back clears nothing.
- **Observers listen to `created`, `updated` and `deleted` — never `saved`.** `save()` fires `saved` even when no column changed. So a `PATCH` that changes nothing clears nothing.

| Observer | On | Does |
|---|---|---|
| `TenantObserver` | created | writes the tenant's zeroed `tenant_stats` row in the same transaction; queues `RefreshPlatformStats` |
| | updated | clears `tenant:{id}`; queues the stats job when `status` changed |
| | deleted | clears `tenant:{id}`; queues the stats job |
| `PlanObserver` | created, updated, deleted | clears `plan:{slug}`, `plans:active` and `platform:analytics`; queues the stats job |
| `SubscriptionObserver` | created, updated, deleted | clears `tenant:{id}:subscription`; queues the stats job |
| `PlatformStatsObserver` | created, updated | clears `platform:analytics` |
| `CustomerObserver`, `UserObserver` | created, deleted | adds or subtracts 1 in `tenant_stats`, inside the same transaction as the row; `CustomerObserver` also adds 1 to that month in `tenant_monthly_stats`; queues the stats job after commit |

`tenant_stats` is written inside the transaction on purpose: the count then commits or rolls back together with the row it counts. The cache clearing and the job still wait for the commit, through `DB::afterCommit()`.

Seeders run through the same observers — `DatabaseSeeder` does not use `WithoutModelEvents`.

**Four writes do not fire a model event.** A query-builder `upsert`, `update` or `increment` bypasses Eloquent, so those four clear the cache themselves, each with a one-line comment saying why:

| Write | Clears |
|---|---|
| `PlanFeature::upsert()` in `PlanService::update()` | `PlanRepository::forget($slug)` after the transaction |
| the `tenant_stats` counter in `CustomerObserver` / `UserObserver` | queues `RefreshPlatformStats` itself |
| `Plan::upsert()` in `PlanSeeder` | the plan keys and `platform:analytics`, when a row actually changed |
| the `update()` in `ProcessDueSubscriptions` | the subscription key of every tenant in the chunk |

## 3. Serialisation

Laravel 13 will not read an object back from the cache unless its class is listed. `config/cache.php` lists `Plan`, `PlanFeature`, `Subscription`, `Tenant` and `Collection`. Tests use the array store, so two of them switch to a serialising store once, to catch a class missing from that list.

## 4. Rate limiting

Four named limiters in `AppServiceProvider`, applied as `throttle:{name}` on the routes. Laravel does the counting, the 429 and the headers.

| Limiter | Limit | Counted per | Routes |
|---|---|---|---|
| `auth` | 5 / minute | email + IP | login and register, one shared bucket |
| `register` | 10 / hour | IP | register — each one creates a tenant, so a new email must not buy a new allowance |
| `public` | 60 / minute | IP | `GET /plans`, `GET /plans/{slug}` |
| `api` | 60 / minute | user id | every logged-in route |

The counters live in Redis, so the limit holds across app containers. Laravel hashes the key, so no email or IP appears in Redis.

## 5. Background jobs

Two jobs, both on the default queue. The worker runs `queue:work --tries=3 --max-time=3600`. Both work across all tenants, set no tenant context, and can run twice with the same result.

The `scheduler` container runs `schedule:work` (one instance, or every entry would run twice). The schedule is in `routes/console.php`:

| Entry | When |
|---|---|
| `ProcessDueSubscriptions` | hourly |
| `sanctum:prune-expired --hours=24` | daily |

**`RefreshPlatformStats`** does all the counting behind `GET /admin/analytics`, so the request itself only reads two stored rows.

- The observers queue it after a tenant, user, customer, subscription or plan write, **30 seconds later**.
- It is unique platform-wide, so a burst of writes collapses into one run. The lock is released when the job starts, so a write during a run queues the next one.
- It counts tenants, sums `tenant_stats`, groups live subscriptions by plan, then saves `platform_stats` and writes only the changed `plan_stats` rows in one `upsert`.
- Analytics therefore trail a write by about 30 seconds plus queue wait.

**`ProcessDueSubscriptions`** ends canceled subscriptions once their `ends_at` has passed.

- `chunkById(1000)` over `active` rows with `ends_at <= now()`. Each chunk is one `update()` to `expired` plus one cache clear.
- It queues `RefreshPlatformStats` once, and only if something expired.
- After that the tenant has no live subscription: `/subscription` answers 404, and `POST /subscription` starts a new one ([API §5](api.md)).

**Counts are not a job.** `tenant_stats` is kept correct by the observers, inside the write's own transaction (§2).
