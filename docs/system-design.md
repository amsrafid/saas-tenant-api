# System Design & Key Technical Decisions

Covers submission requirement **#7**.

---

## 1. Stack

| Part | Choice |
|---|---|
| Framework | Laravel 13 |
| Language | PHP 8.4 |
| Database | PostgreSQL 16 — partial unique indexes and `timestamptz` are both used |
| Cache, queue, rate limits | Redis 7 |
| Auth | Laravel Sanctum tokens |
| Runtime | Docker Compose — nginx, php-fpm, postgres, redis, a queue worker and a scheduler |

## 2. Multi-tenancy

One database, one schema, a `tenant_id` column on every tenant-owned table. One migration run covers every tenant.

`tenant_id` is the first column of every index on those tables. So one tenant's rows sit together in the index, and the number of other tenants does not change how fast that tenant's queries run.

Keeping tenants apart is then the application's job, and §3 is how that is done.

## 3. Tenant isolation

The risk is one forgotten `where('tenant_id', …)`. So isolation does not depend on remembering it:

1. **`ResolveTenant` middleware** reads the tenant from the logged-in user — never from a header, body or query string — and puts it in the `TenantContext` singleton.
2. **A global scope** on every tenant-owned model filters by that tenant, and fills `tenant_id` on create.
3. **Lookups go through the scope.** Another tenant's id gives **404, not 403** — a 403 would confirm the row exists.
4. **`tenant_id` is never fillable** and never comes from the request.
5. **The few places that read across tenants are explicit:** the `/admin` endpoints, the two auth lookups below, `PlanService::delete()`'s check, the counter updates in the `Customer`/`User` observers, the two jobs, and the seeders.
6. **Jobs set the tenant themselves**, and the context is cleared when the job ends.

That last point is the one that bites. A queue worker is one long-lived process running job after job. A singleton still holding the last job's tenant would serve that tenant's data to the next job. So a queue hook clears it, and a test runs two jobs for two tenants and checks nothing leaks.

| Case | What happens |
|---|---|
| A tenant-owned model is queried with no tenant set | `TenantScope` throws `No tenant is set.` — it fails loudly instead of returning everything |
| A row is created | `tenant_id` comes from the context, or the code sets it (registration, seeders); `NOT NULL` catches a miss |
| Sanctum loading a token's user, and login by email | These run before any tenant is known, so they skip the scope on purpose — the only two places that do |
| A platform admin calls a tenant route | 403; `/admin` does not use `ResolveTenant` |
| A user of a suspended tenant calls a tenant route | 403 |
| Middleware order | `ResolveTenant` runs straight after authentication, before route models are bound |
| Context lifetime | Cleared after the request, and before and after every job — the "after" hook runs in a `finally`, so a failed job is covered |

The tenant, the user and the role are each resolved **once per request** and read from memory after that. On a warm cache the tenant costs no query at all.

## 4. Authentication

Sanctum tokens. `POST /auth/register` creates the tenant and its owner in one transaction, so a tenant without an owner cannot exist. Login and register are rate limited ([caching §4](caching.md)).

- **A failed login is 422 on `email`**, with the same message for an unknown email and a wrong password. An unknown email still costs one bcrypt hash, so the response time does not give it away.
- **A disabled user gets 403** — checked after the password, so the message tells nothing to someone without it.
- **Emails are trimmed and lower-cased** before validation, so the unique index behaves case-insensitively.
- **Tokens last 7 days** and carry `['*']`. The role is read fresh on every request, so the gates decide, not the token. Disabling or deleting a user deletes their tokens; changing a role does not.
- **A new tenant starts on the Free plan**, with an `active` subscription and no end date.
- **The slug** is made from the name; if it is taken, a random 6-character suffix is added, and the unique index catches the race.

## 5. Authorization

Four roles: `owner`, `admin`, `member` inside a tenant, and `platform_admin` outside. A user belongs to one tenant and holds one role there.

`Ability::roles()` maps each ability to the roles that hold it. `TenancyServiceProvider` turns each ability into one Gate, and routes apply it with `->can(...)`. A role check is therefore an array lookup, not a query.

- The `match` has no default arm, so a new ability without roles fails loudly instead of opening.
- The gate runs before validation. The controller then loads the row through the scoped service, where another tenant's id is a 404.
- Gates do not compare tenants — the context and the global scope already guarantee that.
- Rules about the data, such as keeping one active owner, live in the service, not the gate.

## 6. Application structure

```
app/
├── Enums/           # Ability (role map), TenantRole, statuses, FeatureKey, BillingPeriod
├── Tenancy/         # TenantContext, TenantScope, BelongsToTenant
├── Models/          # Eloquent models; Concerns/Searchable
├── Services/        # one per domain: Auth, Customer, User, Plan, Subscription, Dashboard, Admin, AuthUser
├── Repositories/    # only the cached reads: Tenant, Plan, Subscription
├── Observers/       # one per model: counters in the transaction, cache clearing after commit
├── Jobs/            # RefreshPlatformStats, ProcessDueSubscriptions
├── Http/
│   ├── Controllers/Api/V1/   # thin: validate, delegate, respond
│   ├── Requests/             # validation rules
│   ├── Resources/            # response shaping
│   └── Middleware/           # ForceJsonAccept, ResolveTenant
└── Providers/       # TenancyServiceProvider: singletons, context reset, gates
```

One path per request: **Controller → Form Request → Service → API Resource.** The service holds the business logic.

A repository exists where a read is cached — the tenant, a plan, the plan list, the live subscription. Everywhere else Eloquent is the data layer. Analytics is cached inside `AdminService`, its only reader.

Patterns used, and where:

| Pattern | Used for |
|---|---|
| Service layer | business logic, one class per domain |
| Repository (cache-aside) | the four cached reads |
| Gates from a role map | authorization |
| Observers | cache clearing, the tenant counters, the monthly growth row |
| Queued job, unique and delayed | precomputing platform analytics off the request path |

## 7. Security

| Concern | Control |
|---|---|
| Reading another tenant's data | global scope, scoped lookups, 404 not 403 (§3), with tests |
| Privilege escalation | a user cannot grant a role above their own, or change their own |
| Mass assignment | `tenant_id` is never fillable; `role`, `status` are set by the service after its checks |
| Credential stuffing | login and register throttled per email + IP, register also per IP; one generic failure message |
| Runaway clients | 60/min per user, 60/min per IP on the public plan routes, counted in Redis |
| Stolen token | tokens expire in 7 days; logout revokes the one used; disabling or deleting a user revokes all of theirs |
| Account enumeration | a missing row and another tenant's row give the same 404 body |
| SQL injection | Eloquent bindings throughout |
| Leaking fields | API Resources list their fields, so a password hash cannot slip out |
| Bad input | a Form Request on every write; unknown fields are rejected |
