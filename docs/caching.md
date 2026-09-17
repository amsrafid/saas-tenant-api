# Caching, Rate Limiting & Background Jobs

Covers submission requirement **#5** (caching strategy and invalidation). Redis 7 backs the cache, the queue, and the rate-limit buckets.

---

## 1. What is cached

Read-through `Cache::remember()` on Redis. A value with reliable invalidation gets **24 hours**; there is no `Cache::forever()`, so a missed invalidation heals within a day.

| Key | TTL | Read by | Invalidated by |
|---|---|---|---|
| `tenant:{id}` | 24 h | `TenantRepository::find()` — `ResolveTenant` on every tenant request, `GET /auth/me`, login | `TenantObserver` (`updated`, `deleted`) → `TenantRepository::forget()` |
| `plan:{slug}` | 24 h | `PlanRepository::findBySlug()` — the plan with its features: registration's Free plan, `GET /plans/{slug}`, the plan-limit check on user and customer create | `PlanObserver` (`created`, `updated`, `deleted`) → `PlanRepository::forget($slug)`; plus one explicit call in `PlanService::update()` (§2) |
| `plans:active` | 24 h | `PlanRepository::active()` — every active plan with its features, `GET /plans` | the same `forget()`: it drops `plan:{slug}` and `plans:active` together, so no plan write can miss the list |
| `tenant:{id}:subscription` | 24 h | `SubscriptionRepository::current()` — the tenant's live (`active`) subscription plus its plan's slug, for every `/subscription` endpoint, the dashboard and the plan-limit check | `SubscriptionObserver` (`created`, `updated`, `deleted`) → `SubscriptionRepository::forget($tenantId)` |
| `platform:analytics` | 24 h | `AdminService::analytics()` — `GET /admin/analytics`, the `platform_stats` row and `plan_stats` joined to `plans`, cached as a plain array | `AdminService::forgetAnalytics()`, from `PlatformStatsObserver` (`created`, `updated`), from `PlanObserver` on any plan write and from `RefreshPlatformStats` after its `plan_stats` upsert (an upsert fires no event) — the only writes the figures are read from; a refresh that changes no figure clears nothing |

A null result (a missing plan, a tenant with no live subscription) is stored by `remember()` but read back as a miss, so it is never served from cache.

**No in-request memo.** `PlanRepository` and `SubscriptionRepository` read Redis directly. A repeated read in one request is a sub-millisecond Redis GET, not a database query, so a memo layer is not worth its extra rules yet.

Each key holds only the columns its consumers read: a tenant is `id`, `name`, `slug`, `status`, `timezone`; a plan is its API fields without `sort_order` or timestamps, with features as `plan_id`, `key`, `limit_value`. A new consumer that needs another column adds it to the repository's select.

**The subscription key holds no limits.** It caches the subscription's `id`, `tenant_id` (for `SubscriptionObserver`), `plan_id`, `status`, `starts_at`, `ends_at` and `canceled_at` and its plan's `slug` (one join); the limits are read from `plan:{slug}`, which every plan write already drops. A platform admin editing a plan's limits therefore reaches every subscribed tenant on the next request, with no per-tenant fan-out — a plan's slug never changes, so the link cannot go stale. The price is a second cache read per request.

**Analytics aggregates nothing on a request.** The figures are precomputed into `platform_stats` and `plan_stats` by `RefreshPlatformStats` (§7, [database §2](database.md)); a miss is two plain selects, no `count`, `sum` or `GROUP BY` (a test asserts it from the query log). The cache still earns its place: a hit runs no query, and invalidation is exact — the key is dropped only when a stats row actually changes, at most once per refresh (~30 s under continuous writes).

`plans:active` holds the whole active catalogue and `GET /plans` pages it in memory, so one key serves every `page`/`per_page` and one `forget()` clears it. That is sound only because the catalogue is a handful of rows written by a platform admin; a catalogue that grew large would page in SQL instead, with the page in the key.

## 2. Invalidation — model observers, after commit

**Invalidation lives in model observers, not in the services that write.** One observer per model, attached with `#[ObservedBy]`, so every write through Eloquent — a service, a seeder, `tinker`, a test factory — drops what it makes stale, and a new service cannot forget to. Every `forget()` and every dispatch runs only once the transaction commits, so a concurrent read cannot re-cache the old row between the forget and the commit, and a rolled-back write drops nothing. The `Plan`, `Subscription` and `PlatformStats` observers implement `ShouldHandleEventsAfterCommit` (`plan_stats` has no observer: it is only ever upserted). The `Tenant`, `Customer` and `User` observers do not: they write `tenant_stats` **inside** the write's transaction (the zeroed row, the counter), so the count commits or rolls back with the row, and they defer their cache drops and dispatches with `DB::afterCommit()` instead.

**Observers listen to `created`/`updated`/`deleted`, never `saved`.** `save()` fires `saved` even when nothing is dirty; `updated` fires only when a column actually changed. So an identical `RefreshPlatformStats` run, or a no-op `PATCH`, clears nothing (the refresh case is tested).

| Observer | Events | Does |
|---|---|---|
| `TenantObserver` | `created` | inserts the tenant's zeroed `tenant_stats` row in the same transaction (so the owner's increment in registration finds it); dispatches `RefreshPlatformStats` after commit |
| | `updated` | after commit: `TenantRepository::forget($id)`; dispatches `RefreshPlatformStats` only when `status` changed |
| | `deleted` | after commit: `TenantRepository::forget($id)`, dispatches `RefreshPlatformStats` |
| `PlanObserver` | `created`, `updated`, `deleted` | `PlanRepository::forget($slug)`, `AdminService::forgetAnalytics()` (analytics lists plan names and currency, which a refresh does not rewrite for a plan without subscribers), dispatches `RefreshPlatformStats` (price, currency and billing period feed MRR; a new plan gets its `plan_stats` row from the job) |
| `SubscriptionObserver` | `created`, `updated`, `deleted` | `SubscriptionRepository::forget($tenantId)`, dispatches `RefreshPlatformStats` |
| `PlatformStatsObserver` | `created`, `updated` | `AdminService::forgetAnalytics()` (`plan_stats` is upserted by `RefreshPlatformStats`, which clears it itself) |
| `CustomerObserver`, `UserObserver` | `created`, `deleted` | in the write's transaction: `tenant_stats` `customers_count`/`users_count` `+ 1` / `- 1` (one `UPDATE … SET n = n + 1` on the primary key, O(1) at any tenant size); `CustomerObserver::created` also upserts `+ 1` into the tenant's `tenant_monthly_stats` month ([database §2](database.md)); `RefreshPlatformStats` after commit (§7). A platform admin (no tenant) touches nothing |

`DatabaseSeeder` does not use `WithoutModelEvents`, so seeding goes through the same observers.

**Where an observer cannot fire, the code that writes calls `forget()` itself**, with a one-line comment saying why:

| Write | Why no event | Explicit call |
|---|---|---|
| `PlanFeature::upsert()` in `PlanService` | a query-builder upsert fires no model events; a limits-only `PATCH /admin/plans/{id}` changes no plan column | `PlanService::update()` calls `PlanRepository::forget($slug)` after the transaction. `create()` needs none: the plan insert in the same transaction fires `PlanObserver` after commit |
| The `tenant_stats` counter `increment()` in `CustomerObserver`/`UserObserver` | a query-builder increment fires no `TenantStats` event | the observer dispatches `RefreshPlatformStats` itself, inside `DB::afterCommit()` |
| `Plan::upsert()` / `PlanFeature::upsert()` in `PlanSeeder` | a query-builder upsert fires no model events | when either upsert wrote a row, the seeder calls `PlanRepository::forgetMany()` for every seeded slug and `AdminService::forgetAnalytics()`; a re-run writes and drops nothing |
| `DatabaseSeeder` refreshes platform stats | not a cache — the queued refresh would land after setup ends | runs `RefreshPlatformStats::handle()` in-process last; a re-run writes nothing, since an unchanged model saves no row |
| `ProcessDueSubscriptions` expires subscriptions with a builder `update()` per `chunkById` chunk of ids | a builder update fires no `SubscriptionObserver` | the job calls `SubscriptionRepository::forgetMany()` with the chunk's tenant ids, and dispatches `RefreshPlatformStats` once at the end when anything expired |
| A plan or tenant delete cascades to its `plan_stats` / `tenant_stats` row | a foreign-key cascade fires no model event | the `deleted` observer dispatches `RefreshPlatformStats`; a tenant delete changes `platform_stats`, whose observer drops the analytics key |

Any future mass `update()`/`delete()`/`insert()` on a query builder fires no events either and must call the repository `forget()` for each tenant it touches, and dispatch `RefreshPlatformStats` once. Plain token deletes (`$user->tokens()->delete()`) touch nothing cached. A plan limit enforced for a business rule is still re-checked at write time; the cache is never the last line of defence.

## 3. Serialisation

Laravel 13 refuses to unserialise objects from the cache unless their class is allowed, so `config/cache.php` lists `Plan`, `PlanFeature`, `Subscription`, `Tenant` and the Eloquent `Collection` (the active plan list, and the `features` relation inside each cached plan) in `serializable_classes`. A model cached later must be added there. Tests use the non-serialising array store, so `PlanEndpointsTest` and `SubscriptionEndpointsTest` switch it to serialising once to catch a missing class.

## 4. Not cached, deliberately

- **Login's user lookup** — reads credentials and status; a cached copy would accept a changed password or a disabled account.
- **Sanctum's token and user lookup** — revoking a token (logout) must take effect on the very next request.
- **Customers** — listings vary by filter, search and page, change often, and are cheap indexed queries; a cache would miss most of the time and be flushed on every write.
- **Subscription usage** (`GET /subscription/usage`, the downgrade check, the plan-limit check on user and customer create) — one query of primary-key lookups on `tenant_stats`. It is already a pre-aggregated read; caching it would add a second copy to invalidate.
- **Tenant dashboard** (`GET /dashboard/analytics`) — built from exactly the figures that change fastest: the user and customer counts and the growth month change on every create. A cached copy would be dropped by every customer write of a busy tenant. Uncached it is already O(1) — the cached subscription and plan, one `tenant_stats` primary-key read, and at most twelve `tenant_monthly_stats` rows by primary-key range — so a cache would add an invalidation path and save two indexed reads.
- **Anything without an invalidation path** — it would be correct only by expiry.

## 5. Query performance

Covered in detail in [database §5–§6](database.md). In summary: indexes follow real query patterns with the two Postgres traps checked explicitly, every relation touched in a loop is eager-loaded, aggregates are computed in SQL, and listing/dashboard endpoints carry Pest query-count assertions (the dashboard's asserts its exact SQL) so a reintroduced N+1 fails the test suite rather than review.

## 6. Rate limiting

Named limiters in `AppServiceProvider`, applied with Laravel's `throttle:{name}` middleware. No custom middleware: a limiter returns a `Limit`, and the framework's `ThrottleRequests` does the counting, the 429 and the headers.

| Limiter | Limit | Key | Routes |
|---|---|---|---|
| `auth` | 5 / minute | submitted email + IP — slows credential stuffing without locking a real user out by IP alone | `POST /auth/login`, `POST /auth/register` (one shared bucket) |
| `register` | 10 / hour | IP | `POST /auth/register` — each registration creates a tenant, so a fresh email per attempt must not bypass the limit |
| `public` | 60 / minute | IP | `GET /plans`, `GET /plans/{slug}` — unauthenticated, and an unknown slug costs a query |
| `api` | 60 / minute | authenticated user id (from `AuthUserService`) | every `auth:sanctum` route: `/auth/logout`, `/auth/me`, `/admin/*`, tenant routes |

All buckets live in Redis (the cache store, db 1), so limits hold across every application container rather than per-process. Laravel stores each bucket under an MD5 of the limiter name and key, so no email or IP appears in a Redis key. There is no per-plan daily request quota: a plan limits users and customers only (§1, [API §6](api.md)). Tests run the same limiters on the array store with `travel()` for resets.

## 7. Background jobs — one queue

Both jobs, **`RefreshPlatformStats`** and **`ProcessDueSubscriptions`**, run on the default queue, and the worker runs `queue:work --tries=3 --max-time=3600`. Nothing a person waits on is queued, so there is nothing for a priority split to protect; a second queue is added, with `--queue=high,default` on the worker, when the first job that needs it exists.

**Scheduled work** is defined in `routes/console.php` and run by the `scheduler` container (`php artisan schedule:work`, one instance — a second would run every entry twice):

| Entry | When | Runs in |
|---|---|---|
| `ProcessDueSubscriptions` | hourly | queued; the scheduler only dispatches it, the worker runs it |
| `sanctum:prune-expired --hours=24` | daily, 00:00 UTC | the scheduler container; deletes tokens expired over a day ago (tokens expire after 7 days) |

All jobs are **idempotent**. A job that works on one tenant's data carries that tenant's id and sets it on `TenantContext` first — a queued job has no request to infer tenancy from, and this is precisely where a missing tenant scope would silently operate on the wrong company's data. Both built jobs are platform-wide by design: they work across every tenant with the scope removed and set no context. The worker clears the context around every job either way ([system design §3](system-design.md)).

**Tenant counts are not a job.** `tenant_stats` is kept exact by the `Customer`/`User` observers in the write's own transaction (§2, [database §2](database.md)) — an O(1) primary-key `UPDATE`, never a recount.

**`RefreshPlatformStats`** (no arguments) does every aggregation behind `GET /admin/analytics`, so the endpoint only selects stored rows ([database §2](database.md)).
- Dispatched after commit by `TenantObserver` (create, status change, delete), `CustomerObserver`/`UserObserver` (create, delete), `SubscriptionObserver` and `PlanObserver`, **30 seconds out**, `ShouldBeUniqueUntilProcessing` with one platform-wide lock (no `uniqueId`): any burst of writes across all tenants collapses into one pending refresh, and the lock is released as the job *starts*, so a write landing during a refresh queues a fresh one. `uniqueFor` = 600 s only bounds a lock whose payload was lost (e.g. a Redis flush between dispatch and run). Analytics trail a write by ~30 s plus queue wait. The lock is taken at dispatch, which is after commit, so a rolled-back write never holds it.
- Two aggregate reads — `count(*)` and `count(*) filter (where status = 'suspended')` over `tenants` with `sum()` subqueries over `tenant_stats`; plans left-joined to `active` subscriptions, grouped and counted — then, in one transaction, saves the `platform_stats` row and writes only the `plan_stats` rows whose figures changed in **one** `upsert` (existing rows read in one query and compared in PHP — no query per plan; a plan with no subscribers gets zeros). MRR per plan is computed in PHP from the grouped counts. A changed `platform_stats` row fires `PlatformStatsObserver` `updated`; a non-empty `plan_stats` upsert fires no event, so the job drops `platform:analytics` itself after commit. An identical run writes no row and drops nothing.
- Reads no tenant-scoped data through the scope and needs no `TenantContext` (`tenant_stats` is read with the scope removed, across every tenant by design).

**`ProcessDueSubscriptions`** (no arguments, hourly) expires every canceled subscription whose `ends_at` has passed ([database §2 `subscriptions`](database.md)). There is no renewal: an uncanceled subscription has `ends_at: null` and runs until it is canceled or changed.
- `chunkById(1000)` over `active` rows with `ends_at <= now()`; each chunk is one `update()` to `expired` by id plus one `forgetMany()`. `RefreshPlatformStats` is dispatched once, only if a row expired.
- Idempotent: a second run finds nothing due and writes, forgets and dispatches nothing. Two overlapping runs would write the same `expired` to the same rows — harmless.
- A tenant whose subscription expired has no live subscription: `/subscription` answers 404 and `POST /subscription` is the way back ([API §5](api.md)); user and customer creates answer 404.
- Works across every tenant with the tenant scope removed, bound by nothing but the due condition — it sets no `TenantContext`.
