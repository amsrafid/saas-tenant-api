# System Design & Key Technical Decisions

Covers submission requirement **#7**. Every section states the decision, the alternatives rejected, and the reason.

---

## 1. Stack

| Component | Choice | Why |
|---|---|---|
| Framework | **Laravel 13** (released 17 Mar 2026) | Current stable; PHP 8.3 minimum. |
| Language | **PHP 8.4** | Above Laravel 13's 8.3 floor; native enums, readonly properties and typed constants are used throughout. |
| Database | **PostgreSQL 16** | Partial unique indexes, `timestamptz`, and `jsonb` are all used below. Genuine feature use, not preference. |
| Cache / queue | **Redis 7** | One dependency serving cache, queue, and rate-limit buckets. |
| Auth | **Laravel Sanctum** (token) | The API has no first-party browser client, so token auth is the right shape. OAuth2 (Passport) solves third-party delegated access, which nothing here needs. |
| Runtime | **Docker Compose** | nginx + php-fpm + postgres + redis + queue worker. |

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
3. **Scoped route-model binding** — a cross-tenant id returns **404, not 403**. A 403 confirms the record exists; 404 reveals nothing.
4. **`tenant_id` is never mass-assignable and is never read from client input.** It comes from the authentication context only.
5. **Platform-admin access** runs through an explicit, auditable `withoutTenantScope()` call in a dedicated admin namespace — the only place isolation is bypassed, so it is the only place that needs reviewing for leaks.
6. **Queue jobs and console commands** carry the tenant explicitly and set it on `TenantContext` before touching data; there is no ambient request for them to infer it from. Crucially, the context is also **reset when the job finishes**: a queue worker is a long-lived process, and a singleton still holding the previous job's tenant would serve that tenant's data to the next one. This is enforced by a queue hook rather than left to each job, and covered by a test asserting two jobs for different tenants do not leak context.

`TenantContext` and `AuthUserService` also serve a performance purpose: the current tenant, user, role, subscription and plan limits are resolved **once per request** and read from memory thereafter, so a controller, action, policy and API resource touching the current tenant cost one query between them rather than four.

This is backed by tests (cross-tenant read/update/delete must all 404) rather than by review discipline.

## 4. Authentication

Sanctum personal access tokens. `POST /auth/register` creates a tenant and its owner user atomically in one transaction — a tenant with no owner, or an owner with no tenant, are both unreachable states. Login is rate-limited per IP + email (§ [caching](caching.md) §6). Tokens carry abilities derived from the user's role, giving a second enforcement layer independent of policies.

## 5. Authorization: roles as enums, permissions as a map

Roles are a small fixed set — `owner`, `admin`, `member` within a tenant, plus a separate `platform_admin` outside tenancy. A `RolePermission` map (enum → set of abilities) is the single source of truth, consumed by Laravel Policies and an `EnsureRole` middleware.

Rejected: a permissions package. It would add a package, three tables, and a per-request DB round-trip to model a fixed matrix that fits in one enum — and would hide the authorization design behind configuration rather than demonstrating it. The map is cached in the config layer, so a role check costs nothing.

Note that "role" is **tenant-scoped**: a role check always takes a tenant explicitly. There is no ambient global role.

## 6. Application structure: SOLID without ceremony

```
app/
├── Models/                      # Eloquent models only
├── Http/
│   ├── Controllers/Api/V1/      # thin — validate, delegate, respond
│   ├── Requests/                # validation rules
│   ├── Resources/               # response shaping
│   └── Middleware/              # ResolveTenant, EnsureRole, EnforcePlanLimit
├── Services/
│   ├── Tenancy/                 # TenantContext, actions, policies, enums
│   ├── Subscriptions/           # plan assignment, limit checks, usage
│   ├── Customers/
│   └── Analytics/
├── Support/Contracts/           # interfaces where an implementation could vary
└── Jobs/  Listeners/  Observers/
```

- **Controllers are thin.** One operation = one `Action` class with a single public method. Controllers never contain business logic.
- **Interfaces only where substitution is real** — e.g. `UsageMeterInterface` (DB-counted today, Redis-counted under load). Interfaces are not created reflexively for every class; an interface with exactly one permanent implementation is indirection, not abstraction.
- **Patterns applied where the problem asks for one**, and named in the README so they read as intent: *Strategy* for feature-limit enforcement (each limit type is its own checker), *Observer* for cache invalidation, *Policy* for authorization, *Action/Command* for write operations. No pattern is used where a plain method would do.

## 7. Security posture

Called out explicitly because the covering email names security awareness as an evaluated skill.

| Concern | Control |
|---|---|
| Cross-tenant data access | Global scope + scoped binding + 404-not-403 (§3), with tests |
| Privilege escalation | Role changes authorized by policy; a user can never grant a role above their own, nor change their own |
| Mass assignment | `tenant_id`, `role`, and `status` are guarded; never bound from request input |
| Credential attacks | Per-IP+email throttling on login; generic failure message that does not reveal whether an email exists |
| Token compromise | Abilities scoped per role; logout revokes the presented token; tokens expire |
| Enumeration | Cross-tenant and unauthorized lookups both return 404 with an identical body |
| Injection | Eloquent bindings throughout; no raw SQL string interpolation |
| Sensitive data in transit/logs | Password and token fields excluded from responses via API Resources and from logs via `$hidden` |
| Input validation | Form Requests on every write endpoint; unknown fields rejected rather than ignored |

## 8. Scalability path

The design carries its own next steps, which the README states rather than implies:

1. **Read scaling** — analytics queries move to a read replica; a `read`/`write` connection split is configuration, not a rewrite.
2. **Usage metering** — counts move from DB aggregates to Redis counters behind `UsageMeterInterface`, flushed periodically by a job.
3. **Hot tenants** — a single large tenant can be moved to its own database; `tenant_id`-leading indexes make the extraction a filtered copy.
4. **Queue throughput** — workers scale horizontally per queue; the three-priority split (§ [caching](caching.md) §7) means slow analytics work can never delay a user-facing job.
