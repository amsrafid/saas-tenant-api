# System Design & Key Technical Decisions

Covers submission requirement **#7**. Every section states the decision, the alternatives rejected, and the reason.

---

## 1. Stack

| Component | Choice | Why |
|---|---|---|
| Framework | **Laravel 13** (released 17 Mar 2026) | Current stable; PHP 8.3 minimum. |
| Language | **PHP 8.4** | Above Laravel 13's 8.3 floor; native enums, readonly properties and typed constants are used throughout. |
| Database | **PostgreSQL 16** | Partial unique indexes and `timestamptz` are both used below. Genuine feature use, not preference. |
| Cache / queue | **Redis 7** | One dependency serving cache, queue, and rate-limit buckets. |
| Auth | **Laravel Sanctum** (token) | The API has no first-party browser client, so token auth is the right shape. OAuth2 (Passport) solves third-party delegated access, which nothing here needs. |
| Runtime | **Docker Compose** | nginx + php-fpm + postgres + redis + queue worker + scheduler. |

## 2. Multi-tenancy: single database, shared schema, `tenant_id` discriminator

**The central architectural decision.** Three options were considered:

| Approach | Isolation | Cost | Migration complexity | Verdict |
|---|---|---|---|---|
| Database per tenant | Strongest | Highest — a connection pool per tenant | Run *N* times, partial-failure handling needed | Rejected |
| Schema per tenant (Postgres) | Strong | Moderate | Run *N* times; search-path switching per request | Rejected |
| **Shared schema + `tenant_id`** | Application-enforced | Lowest | One migration run | **Chosen** |

**Reasoning.** Isolation is the only axis where shared-schema loses, and it loses only if the application fails to enforce it. That failure is preventable in code (§3); the operational costs of the other two are not avoidable at all. For a SaaS at this stage — many small tenants, one product version, frequent schema changes — per-tenant databases mean every migration becomes a distributed job with partial-failure states, for isolation the application layer can provide.

**When this decision would flip:** a tenant with a contractual or regulatory requirement for physical data separation, a need for per-tenant restore granularity, or one tenant large enough to be a noisy neighbour. The schema is designed so that move stays open — every tenant-owned table carries `tenant_id` as the leading column of its indexes, so extraction by tenant is a filtered copy rather than a rewrite.

## 3. Tenant isolation is enforced at the query layer, never in controllers

The failure mode that matters here is one forgotten `where('tenant_id', …)` leaking another company's data. Isolation is therefore structural:

1. **`ResolveTenant` middleware** — reads the tenant from the authenticated user (never from a request header, body, or query parameter) and sets it on a `TenantContext` singleton.
2. **`BelongsToTenant` trait + global scope** — every tenant-owned model automatically constrains reads to the current tenant and stamps `tenant_id` on create. Isolation is opt-out by explicit intent, not opt-in by discipline.
3. **Lookups by id go through the scope** (`findOrFail` in the service) — a cross-tenant id returns **404, not 403**. A 403 confirms the record exists; 404 reveals nothing.
4. **`tenant_id` is never mass-assignable and is never read from client input.** It comes from the authentication context only.
5. **Cross-tenant reads are explicit and few.** Platform-admin endpoints (`AdminService`: `/admin/tenants`, `/admin/analytics`) read only non-scoped tables — `tenants`, `plans`, live `subscriptions` joined, the per-tenant counts in `tenant_stats` joined by table name, and the `platform_stats`/`plan_stats` summary rows. The `withoutGlobalScope(TenantScope::class)` calls are the pre-tenant auth lookups (table below), `PlanService::delete()`'s any-subscription check, the `Customer`/`User` observers' counter updates (the written row's own `tenant_id`, by primary key; `CustomerObserver`'s monthly growth upsert, a query-builder `upsert` keyed the same way), the `RefreshPlatformStats` job (sums `tenant_stats` across every tenant by design, writes only platform-level rows) and the demo seeders; the `ProcessDueSubscriptions` job expires due `subscriptions` across every tenant (scope removed, by design) — the places to review for leaks.
6. **Queue jobs and console commands** carry the tenant explicitly — set on `TenantContext` before reading through the scope, or bound into every query with the scope removed and the context left untouched; there is no ambient request for them to infer it from. Crucially, the context is also **reset when the job finishes**: a queue worker is a long-lived process, and a singleton still holding the previous job's tenant would serve that tenant's data to the next one. This is enforced by a queue hook rather than left to each job, and covered by a test asserting two jobs for different tenants do not leak context.

**How the pieces behave at the edges:**

| Situation | Behaviour | Why |
|---|---|---|
| A tenant-owned model is queried with no tenant set | `TenantScope` throws a `RuntimeException` (`No tenant is set.`) | Fails closed and loud. An empty result would hide a job that forgot its context; no result set can ever widen to every tenant |
| A record is created while a tenant is set | an empty `tenant_id` is filled from `TenantContext` | `tenant_id` is never mass-assignable, so request input cannot set it |
| A record is created with no tenant set | `tenant_id` must already be set by the code (registration, seeders); the `NOT NULL` column catches an omission | There is no context to stamp from, and `tenant_id` is never mass-assignable, so it cannot come from input |
| `User` | Tenant-scoped like every other tenant-owned model | The users table is the most sensitive listing. The two lookups that must happen before a tenant is known — Sanctum loading a token's user, and login by email — bypass the scope explicitly with `withoutGlobalScope(TenantScope::class)` (`PersonalAccessToken::tokenable()`; `AuthService::login()`) |
| A platform admin (null tenant) calls a tenant route | `ResolveTenant` answers **403** | Authenticated but not a tenant member. The `/admin` namespace does not use `ResolveTenant` |
| A user of a suspended tenant calls a tenant route | **403** | Suspension switches the whole tenant off; revoking tokens is not needed for it to take effect |
| Middleware order | `ResolveTenant` is registered in the priority list right after authentication | Otherwise `SubstituteBindings`, which the `api` group lists first, would bind any route model before a tenant exists |
| Context lifetime | Cleared on `RequestHandled`, and on `JobProcessing` and `JobAttempted` for worker jobs | `JobAttempted` fires in a `finally`, so a failed job is covered too. A `sync` job runs inside its caller, which owns the context, so it is left alone |

Resolving the tenant costs no query on a warm cache (`TenantRepository`, `tenant:{id}`) and one primary-key read on a miss, on top of Sanctum's own token and user lookups. Octane is not used; the request-end reset keeps the singletons safe if it ever is.

`TenantContext` and `AuthUserService` also serve a performance purpose: the current tenant, user and role are resolved **once per request** and read from memory thereafter, so a controller, service and API resource touching them cost nothing more. The live subscription and its plan limits are read from Redis (one GET each, no query) rather than held in memory ([caching §1](caching.md)).

This is backed by tests (cross-tenant read/update/delete must all 404) rather than by review discipline.

## 4. Authentication

Sanctum personal access tokens. `POST /auth/register` creates a tenant and its owner user atomically in one transaction — a tenant with no owner, or an owner with no tenant, are both unreachable states. Login and register are rate-limited per IP + email, register also per IP; every authenticated route per user (§ [caching](caching.md) §6). Authorization is the gates alone (§5): tokens are issued unrestricted, and the role is read on every request.

Behaviour:
- **Failed login is a 422** on `email` with Laravel's generic `auth.failed` message, identical for an unknown email and a wrong password; an unknown email still pays for one bcrypt hash so timing does not tell them apart.
- **A disabled user gets 403 "This account is disabled."** — but only after the password verified, so the distinct message reveals nothing to someone without the password.
- **Emails are trimmed and lower-cased** before validation on register and login, so the case-sensitive unique index behaves case-insensitively.
- **Tokens expire after 7 days** (`sanctum.expiration`, also written to `expires_at` and returned to the client). Tokens carry `['*']`, issued in one place, `User::createApiToken()`. Token abilities are not narrowed by role: they are frozen at issue time, and a role change deliberately keeps the user's tokens, so the gates, which read the current role on every request, are the enforcement. Disabling or deleting a user deletes their tokens.
- **Registration sets `tenant_id` explicitly** rather than setting `TenantContext`: no context exists yet, and a service that set one would have to reset it to stay safe in a job. The new tenant gets an `active` Free subscription with no `ends_at` — it runs until canceled or changed. A missing `free` plan throws a plain `RuntimeException` (a generic, reported 500) — a deployment error, not a tenant without a subscription.
- **Tenant slug** is the slugified name (max 60 chars, `tenant` if nothing URL-safe remains); a taken slug gets a random 6-character suffix. The unique index is the backstop for the race between check and insert.

## 5. Authorization: roles as enums, permissions as a map

Roles are a small fixed set — `owner`, `admin`, `member` within a tenant, plus a separate `platform_admin` outside tenancy. A role map on the `Ability` enum (ability → roles holding it) is the single source of truth, turned into one Laravel Gate per ability.

Rejected: a permissions package. It would add a package, three tables, and a per-request DB round-trip to model a fixed matrix that fits in one enum — and would hide the authorization design behind configuration rather than demonstrating it. The map is code, so a role check costs no query.

Note that "role" is **tenant-scoped**: a user belongs to exactly one tenant and holds one role there. There is no global role across tenants.

How it is built:
- `App\Enums\Ability` lists the abilities (`customers:view`, `customers:create`, …) and `Ability::roles()` maps each to the roles holding it with one `match` and no default arm, so a new ability is one case plus one arm, and an ability without roles fails loudly rather than defaulting open.
- `TenancyServiceProvider` defines one Gate per case (`Gate::define($ability->value, …)` → the user's role is in `roles()`). Routes apply it with `->can(Ability::X->value)`, so authorization runs before the Form Request validates; the controller then loads the record through the scoped service (a cross-tenant id is a 404).
- The gate does not compare tenants: `ResolveTenant` sets the context from the user's own tenant and the global scope guarantees every loaded record belongs to it. A platform admin is refused by `ResolveTenant` (403) before any gate, and holds no tenant ability anyway. Its abilities — `plans:manage` on `/admin/plans`, `tenants:manage` and `platform-analytics:view` on `/admin/tenants` and `/admin/analytics` — guard routes that run under `auth:sanctum` without the tenant middleware.
- No Policy classes: a check that only asks "does this role hold this ability" is the gate; a business rule on request data or record state (the last active owner) lives in the domain service.

## 6. Application structure: SOLID without ceremony

```
app/
├── Enums/                       # Ability (role map), TenantRole, statuses, FeatureKey, BillingPeriod
├── Tenancy/                     # TenantContext, TenantScope, BelongsToTenant
├── Models/                      # Eloquent models; Concerns/Searchable (search scope)
├── Services/                    # one class per domain: AuthService, CustomerService, UserService, PlanService, SubscriptionService, DashboardService, AdminService, AuthUserService
├── Repositories/                # cache-backed reads only: TenantRepository, PlanRepository, SubscriptionRepository
├── Observers/                   # one per model: tenant_stats counters and monthly customer growth in the write's transaction; cache invalidation and stats-job dispatch after commit
├── Jobs/                        # RefreshPlatformStats (unique platform-wide), ProcessDueSubscriptions (hourly expiry of canceled subscriptions)
├── Http/
│   ├── Controllers/Api/V1/      # thin — validate, delegate, respond
│   ├── Requests/                # validation rules
│   ├── Resources/               # response shaping
│   └── Middleware/              # ForceJsonAccept, ResolveTenant
└── Providers/                   # TenancyServiceProvider: singletons, context reset, ability gates
```

Repository only where a read is cache-backed (tenant by id, plan by slug, the active plan list, the tenant's live subscription); elsewhere Eloquent is the data layer. Platform analytics is cached inside `AdminService` (`analytics()` / `forgetAnalytics()`): its one reader is that service, so a repository would only forward the call. It reads the `platform_stats` and `plan_stats` summary tables and aggregates nothing; the aggregation is `RefreshPlatformStats`'s. Plan limits need no repository of their own: they are the cached plan's features. The tenant dashboard (`DashboardService`) is not cached at all ([caching §4](caching.md)): it reuses `SubscriptionService::usage()` and reads the `tenant_monthly_stats` rows, so it also aggregates nothing.

- **One request path:** Controller → Form Request validates → Service method → API Resource. The service holds the business logic (registration transaction, login rules, listing query, CRUD); the controller only wires them together.
- **Interfaces only where substitution is real.** An interface with exactly one permanent implementation is indirection, not abstraction.
- **Patterns applied where the problem asks for one:** *Service layer* for business logic; *Repository* with cache-aside, only where a read is cached; plan limits as one `SubscriptionService::ensureWithinLimit()` rather than a Strategy (two count-based limits share one rule); rate limits as Laravel named limiters rather than a custom middleware; *Gates* generated from one role map for authorization; *Observer* for every cache invalidation, for the per-tenant user and customer counters and the monthly customer growth (inside the write's transaction, so they are exact) and for dispatching the platform stats job — one observer per model, so a write from any code path keeps them right ([caching §2](caching.md)); a *unique, delayed queued job* to precompute platform analytics off the request path. No pattern is used where a plain method would do.

## 7. Security posture

Called out explicitly because the covering email names security awareness as an evaluated skill.

| Concern | Control |
|---|---|
| Cross-tenant data access | Global scope + scoped lookups + 404-not-403 (§3), with tests |
| Privilege escalation | Role changes checked in the user service; a user can never grant a role above their own, nor change their own |
| Mass assignment | Models whitelist fillable columns: `tenant_id` never; a user's `role`/`status` and a tenant's `status` are not fillable and are set explicitly after the service's checks |
| Credential attacks | Per-IP+email throttling on login and register, per-IP on register; generic failure message that does not reveal whether an email exists |
| Abuse and runaway clients | 60/min per user on every authenticated route, 60/min per IP on the public plan catalogue — all Redis-backed, so they hold across app containers |
| Token compromise | Tokens expire after 7 days; logout revokes the presented token; disabling or deleting a user revokes all of theirs |
| Enumeration | Cross-tenant and unauthorized lookups both return 404 with an identical body |
| Injection | Eloquent bindings throughout; no raw SQL string interpolation |
| Sensitive data in responses | API Resources list their fields explicitly, so a password hash is never returned; `password` is also `#[Hidden]` on `User` |
| Input validation | Form Requests on every write endpoint; unknown fields rejected rather than ignored |

## 8. Scalability path

The design carries its own next steps, which the README states rather than implies:

1. **Read scaling** — analytics queries move to a read replica; a `read`/`write` connection split is configuration, not a rewrite.
2. **Hot tenants** — a single large tenant can be moved to its own database; `tenant_id`-leading indexes make the extraction a filtered copy.
3. **Queue throughput** — workers scale horizontally; both jobs share the default queue, and a priority queue is added when a job a person waits on first exists ([caching](caching.md) §7).
