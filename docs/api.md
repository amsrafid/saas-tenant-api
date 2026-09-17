# API Design

Supports submission requirement **#4**. Endpoint samples with real request/response bodies are generated from the working implementation and served as Swagger UI (§7), so documentation and code cannot drift.

Base path: `/api/v1`. Versioned from day one — an unversioned API cannot make a breaking change without breaking every client.

---

## 1. Conventions

- **Auth:** `Authorization: Bearer <sanctum-token>` on everything except `POST /auth/register`, `POST /auth/login`, `GET /plans` and `GET /plans/{slug}`.
- **Content:** JSON in, JSON out. `Accept: application/json` is enforced so Laravel never returns an HTML error page to an API client.
- **Naming:** plural nouns for collections, `snake_case` field names, ISO-8601 UTC timestamps (`2026-09-19T11:30:00.000000Z`, Laravel's default date serialisation).
- **Verbs:** `GET` read, `POST` create, `PATCH` partial update (not `PUT` — every update here is partial), `DELETE` remove.
- **No `tenant_id` anywhere in a request.** It is derived from the authenticated token. A client cannot address another tenant even by trying.

## 2. Response envelope

Every successful response is a plain Laravel API Resource (`JsonResource`) — a controller never returns a raw model or array. Dates are the model's Carbon attributes, which Laravel serialises as ISO-8601 in UTC.

A resource returns only the fields a client acts on. Bookkeeping columns — `created_at`, `updated_at`, a plan's `sort_order` — are not returned, and the query behind the response does not select them; a timestamp appears only when it carries domain meaning (a subscription's `starts_at`, `ends_at`, `canceled_at`).

**Single resource**
```json
{ "data": { "id": 12, "name": "Acme Ltd", "slug": "acme-ltd", "status": "active", "timezone": "Asia/Dhaka" } }
```

**Collection** — Laravel's default paginated resource shape, present even on a single page:
```json
{
  "data": [ { "id": 1, "name": "Jane Doe", "email": "jane@acme.test", "status": "active" } ],
  "links": { "first": "…?page=1", "last": "…?page=3", "prev": null, "next": "…?page=2" },
  "meta": { "current_page": 1, "from": 1, "last_page": 3, "links": [ … ], "path": "…/api/v1/customers",
            "per_page": 15, "to": 15, "total": 42 }
}
```

**Error** — Laravel's own JSON error rendering, switched on for every `api/*` route (`shouldRenderJsonWhen` in `bootstrap/app.php`). Every error has `message`; a 422 also has `errors` keyed by field:
```json
{
  "message": "The email has already been taken.",
  "errors": { "email": ["The email has already been taken."] }
}
```
With `APP_DEBUG=false` (production) no stack trace or SQL is included; a 5xx says only `Server Error`.

| Status | Used for |
|---|---|
| 200 | successful read or update |
| 201 | resource created |
| 204 | successful delete |
| 401 | missing/invalid token |
| 403 | authenticated but the role lacks the ability |
| 404 | not found **or** belongs to another tenant — deliberately indistinguishable |
| 409 | state conflict (e.g. already subscribed) |
| 422 | validation failure |
| 429 | rate limit, or a plan's user/customer limit reached (§6) |

**Rate limits** ([caching §6](caching.md)): every authenticated route 60 requests/minute per user; `GET /plans*` 60/minute per IP; login and register 5/minute per IP+email, register also 10/hour per IP. Responses carry `X-RateLimit-Limit`/`X-RateLimit-Remaining`; a rate-limit 429 carries `Retry-After` in seconds and Laravel's body `{ "message": "Too Many Attempts." }`, while a plan user/customer count-limit 429 has no `Retry-After` and its own body (§6).

`ForceJsonAccept` is prepended to the `api` middleware group, so a client that sends no `Accept` header still gets JSON — including the 401 for a missing token, which would otherwise be a redirect to a login page.

## 3. Pagination, filtering, search

Every listing endpoint uses the same parameter names and behaviour. Each resource has its own listing Form Request (`List{X}Request`) holding plain rules — including the whitelist of filter keys — and its service builds the query with the model's `search(columns, term)` scope from the `Searchable` trait.

| Parameter | Behaviour |
|---|---|
| `page` | 1-based; out-of-range returns an empty `data` with valid `meta`, not a 404 |
| `per_page` | default 15, **hard cap 100** — a client cannot request the whole table |
| `search` | prefix match on the resource's designated searchable columns |
| `filter[status]`, `filter[role]`, `filter[plan_id]` | whitelisted per resource; an unknown filter key is a 422, not silently ignored |

Behaviour:
- A `per_page` above 100 (or below 1), a `page` below 1, an unknown `filter` key and a filter value outside its enum are all **422** — never clamped or ignored.
- The order is fixed: newest first (`created_at` descending, then `id` descending so rows with equal timestamps never repeat or vanish between pages). There is no `sort` parameter.
- `search` is at most 100 characters; `%`, `_` and `\` in it match literally.
- `links` keep the request's query string. Scramble documents the parameters from the listing Form Request's rules.
- **`meta.total` without a table count.** `GET /customers` and `GET /users` with no `filter` and no `search` take `total` from the tenant's exact stored count (`tenant_stats`) and run only the page query; with a `filter` or `search` they count the matching rows, backed by the `(tenant_id, …)` indexes ([database §5, §7](database.md)). The response shape is the same either way. The admin tenant listing always counts — tenants are few.
- An unknown **top-level** query parameter (`?tenant_id=1`, `?sort=name`) is ignored, not a 422: Laravel's unknown-field check reads only the request body. It reaches no query, because only validated keys are read.

Whitelisting matters for more than tidiness: passing user input into a `where` column name is an injection vector. The whitelist is the control.

## 4. Validation

Every write endpoint has a Form Request. Rules are declared once and read directly by the OpenAPI generator (§7), so a validation rule and its documentation can never disagree. Unknown fields are rejected rather than ignored, so a client typo fails loudly instead of silently doing nothing. On listings this covers `filter[...]` keys; unknown top-level query parameters are ignored (§3). A malformed JSON body is read as empty, so it fails as missing required fields (422), not as a 400.

## 5. Endpoints

### Auth
| Method | Path | Notes |
|---|---|---|
| POST | `/auth/register` | Creates tenant **and** its owner user in one transaction. Assigns the Free plan. Returns token + tenant + user. Throttled per IP+email (5/min, shared with login) and per IP (10/hour). |
| POST | `/auth/login` | Throttled per IP+email (5/min, shared with register). Generic failure message: 422 on `email`, identical for unknown email and wrong password. Disabled account → 403. |
| POST | `/auth/logout` | Revokes the presented token only. 204. |
| GET | `/auth/me` | Current user with tenant and role, for every authenticated user (no tenant middleware). `tenant` is `null` for a platform admin. |

Register and login both return `{ "data": { "access_token", "token_type": "Bearer", "expires_at", "user": { "id", "name", "email", "role", "status", "tenant": { "id", "name", "slug", "status", "timezone" } } } }`; `/auth/me` returns the same `user` object. Register takes `tenant_name`, `name`, `email`, `password` (8–72 characters).

### Tenant (current company)
| Method | Path | Role |
|---|---|---|
| GET | `/tenant` | any member |
| PATCH | `/tenant` | owner, admin |

- **Only the caller's own tenant** is addressed — it is the tenant `ResolveTenant` already put in `TenantContext`, so `GET` runs no query of its own (read from the `tenant:{id}` cache, [caching §1](caching.md)). There is no id to point at another tenant.
- **Response:** the `tenant` object of the auth responses, `{ "data": { "id", "name", "slug", "status", "timezone" } }`, for GET and PATCH (200).
- **PATCH body:** any subset of `name` (≤255) and `timezone` (an IANA zone id such as `Europe/London`; an offset like `+06:00` is a 422). `slug` is fixed once registered and `status` belongs to the platform admin (`PATCH /admin/tenants/{id}`), so sending either is a 422 like any unknown field. `TenantObserver` drops the cached tenant after the update commits.
- **Changing `timezone` applies from the next request:** new customers' dashboard growth month follows the new zone; past months already written are not re-bucketed.

```http
PATCH /api/v1/tenant
{ "name": "Acme Ltd", "timezone": "Europe/London" }
```
```json
200 { "data": { "id": 1, "name": "Acme Ltd", "slug": "acme", "status": "active", "timezone": "Europe/London" } }
```

### Users (staff)
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/users` | any member | paginated, `filter[role]`, `filter[status]`, `search` |
| POST | `/users` | owner, admin | **enforces `max_users`** → 429 (§6); 201 |
| GET | `/users/{id}` | any member | 404 across tenants |
| PATCH | `/users/{id}` | owner, admin | partial; role rules below |
| DELETE | `/users/{id}` | owner | hard delete, 204; revokes the user's tokens |

Fixed at `S1-11`:
- **Body:** POST takes `name`, `email` (trimmed and lower-cased, **unique across all tenants** — it is the login), `password` (8–72), `role` (`owner`/`admin`/`member`); new users are `active`. PATCH accepts any subset of `name`, `email`, `role`, `status` (`active`/`disabled`); no password change.
- **Response:** the `user` object of `/auth/me` without `tenant`: `{ "data": { "id", "name", "email", "role", "status" } }`.
- **Role rules**, checked in `UserService` after the ability gate:
  1. You cannot grant a role above your own (create or update) → 403.
  2. You cannot modify or delete a user whose role is above your own, so an admin cannot demote or disable the owner → 403.
  3. You cannot change your own role → 403.
  4. The last active owner cannot be deleted, demoted or disabled → 409. This is checked before rule 3, so a sole owner demoting themselves learns why.
- **Disabling or deleting a user deletes their tokens**, so access ends on the next request. A role change keeps them: gates read the role on every request.

### Customers
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/customers` | any member | paginated, newest first, `filter[status]`, `search` (name, email) |
| POST | `/customers` | any member | **enforces `max_customers`** → 429 (§6); 201 |
| GET | `/customers/{id}` | any member | 404 across tenants |
| PATCH | `/customers/{id}` | any member | partial; 404 across tenants |
| DELETE | `/customers/{id}` | owner, admin | hard delete, 204; 404 across tenants |

Fixed at `S1-10`:
- **Members create and edit customers; only owners and admins delete them.** Customers are day-to-day working records, so every staff member maintains them; deletion is permanent (no soft deletes), so it is held back from `member`.
- **Body:** `name` (required, ≤255), `email` (required, trimmed and lower-cased, **unique within the tenant** — the same email at another tenant is allowed), `phone` (≤32, nullable), `status` (`active`/`inactive`, defaults to `active`). PATCH accepts any subset.
- **Response:** `{ "data": { "id", "name", "email", "phone", "status" } }` — one shape for the listing and a single customer.
- **Authorization runs before validation**, so a caller without the ability gets 403 whatever the body.
- **Flow:** route (`->can(Ability::X)`, `{id}` numeric only) → controller → Form Request → `CustomerService` → `CustomerResource`. `CustomerService::find()` is a `findOrFail` through the tenant scope, so another tenant's id is a 404 like a missing one. A non-numeric id does not match the route (404).

### Plans
| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/plans` | public | active plans with their features, in display order (the plan's `sort_order`, then `id`; `sort_order` itself is not returned); paginated (`page`, `per_page`), served from cache |
| GET | `/plans/{slug}` | public | any plan, an inactive one included (`is_active` says so); 404 for an unknown slug; served from cache |
| POST | `/admin/plans` | platform_admin | 201 |
| PATCH | `/admin/plans/{id}` | platform_admin | partial, feature limits included |
| DELETE | `/admin/plans/{id}` | platform_admin | 204; a plan any subscription refers to → 409 |

Fixed at `S1-12`:
- **Body:** POST takes `name` (≤255), `slug` (lower-case letters, digits and single hyphens, unique), `price_cents` (integer ≥ 0, minor units), `currency` (three upper-case letters), `billing_period` (`monthly`/`yearly`), `is_active` (default `true`), `sort_order` (0–32767, default `0`) and `features` — an object with **every** feature key (`max_users`, `max_customers`), each an integer ≥ 0 or `null` for unlimited. PATCH accepts any subset of those except `slug`, which is fixed once created; `features` may carry only the limits that change.
- **Response:** `{ "data": { "id", "name", "slug", "price_cents", "currency", "billing_period", "is_active", "features": { "max_users": 3, "max_customers": 10 } } }` — one shape for the listing, a single plan and the admin writes.
- **Deleting** a plan is refused with 409 while any subscription row refers to it — a canceled or expired one included, because the foreign key restricts on every row and plan history must survive. Deactivate it instead (`is_active: false`): it leaves the listing and stays readable by slug, and existing subscriptions are untouched.
- A tenant user of any role gets 403 on the admin routes; authorization runs before validation.

```http
POST /api/v1/admin/plans
{ "name": "Team", "slug": "team", "price_cents": 4900, "currency": "USD", "billing_period": "monthly",
  "features": { "max_users": 25, "max_customers": 5000 } }
```
```json
201 { "data": { "id": 4, "name": "Team", "slug": "team", "price_cents": 4900, "currency": "USD",
                "billing_period": "monthly", "is_active": true,
                "features": { "max_users": 25, "max_customers": 5000 } } }
```

### Subscription
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/subscription` | any member | current plan, status, period, limits |
| POST | `/subscription` | owner | subscribe; 409 if already active |
| PATCH | `/subscription` | owner | change plan. **A downgrade is refused with 422 when current usage exceeds the target plan's limits**, naming the offending feature — silently breaking the invariant would be worse than refusing. |
| DELETE | `/subscription` | owner | cancel; runs to the end of the current billing period (set as `ends_at`) rather than terminating immediately; 200 with the subscription |
| GET | `/subscription/usage` | any member | per-feature `used` / `limit` / `remaining`, `null` limit = unlimited |

Fixed at `S1-13`:
- **Body:** POST and PATCH take `plan` — the slug of an **active** plan. An unknown or inactive slug is a 422 on `plan` (`The selected plan is invalid.`). Unknown fields are a 422 as everywhere else.
- **Response:** `{ "data": { "id", "status", "starts_at", "ends_at", "canceled_at", "plan": { …the plan object of /plans, features included… } } }` for GET, POST (201), PATCH (200) and DELETE (200). The limits are the plan's `features`. `ends_at` is `null` while the subscription runs uncanceled; it is set only by cancelling or changing plan.
- **Only the live subscription is addressed** — the tenant's one `active` row. With none (it expired), GET, PATCH, DELETE and usage are 404, and POST is the way back in; POST while one is live is 409.
- **Changing plan** ends the live subscription now (`status: canceled`, `canceled_at` and `ends_at` set to now) and starts a new `active` one on the target plan with `ends_at: null`, in one transaction. History is kept; there is no proration. The same plan again is 409.
- **The downgrade rule** applies to every plan change and to POST: when the tenant's current `max_users` or `max_customers` usage is above the target plan's limit, the request is a 422 on `plan` with one message per feature over the limit. Usage exactly at the limit is allowed.
- **Cancelling** sets `canceled_at` to now and `ends_at` to the end of the current billing period — the first boundary after now, counted in whole months or years from `starts_at` (a month-end start clamps, e.g. started 31 Jan → ends 30 Sep, without drifting). The subscription stays `active` until `ends_at`. A second cancel is 409.
- **Period end** (hourly job, [caching §7](caching.md)): there is no billing, so nothing renews — an uncanceled subscription simply keeps running with `ends_at: null`. A canceled one becomes `expired` once `ends_at` passes, after which the tenant has no live subscription. The change can trail `ends_at` by up to an hour. A canceled subscription may still change plan, which starts a fresh, uncanceled one.
- **Usage** covers the features with something to measure: `max_users` and `max_customers` (the tenant's stored counts, every status, exact — §6). `remaining` is `null` for an unlimited feature and never below `0`.

```http
GET /api/v1/subscription
```
```json
200 { "data": { "id": 1, "status": "active", "starts_at": "2026-06-16T10:00:00.000000Z", "ends_at": null, "canceled_at": null,
                "plan": { "id": 2, "name": "Pro", "slug": "pro", "price_cents": 2900, "currency": "USD", "billing_period": "monthly",
                          "is_active": true, "features": { "max_users": 10, "max_customers": 1000 } } } }
```
```http
DELETE /api/v1/subscription
```
```json
200 { "data": { "id": 1, "status": "active", "starts_at": "2026-06-16T10:00:00.000000Z", "ends_at": "2026-10-16T10:00:00.000000Z",
                "canceled_at": "2026-09-18T12:00:00.000000Z", "plan": { …as above… } } }
```
```http
PATCH /api/v1/subscription
{ "plan": "free" }
```
```json
422 { "message": "Current max_users usage (4) exceeds the Free plan's limit of 3.",
      "errors": { "plan": [ "Current max_users usage (4) exceeds the Free plan's limit of 3." ] } }
```
```http
GET /api/v1/subscription/usage
```
```json
200 { "data": { "max_users": { "used": 4, "limit": 10, "remaining": 6 },
                "max_customers": { "used": 8, "limit": 1000, "remaining": 992 } } }
```

### Dashboard
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/dashboard/analytics` | any member | the live plan, usage per feature (users and customers against the limits), customers added in each of the last 12 months; read from stored counters, not cached |

Fixed at `S2-05`:
- **Authorization:** ability `dashboard:view`, held by owner, admin and member; a platform admin gets 403 from the tenant middleware.
- **Response:** `plan` (`id`, `name`, `slug`); `usage` — the same object as `GET /subscription/usage`, so the user and customer totals (every status) are its `used` values and the ratio is `used`/`limit`; `customer_growth` — twelve `{ month, customers_added }` entries, oldest first, ending with the current month. **Months are the tenant's** (`tenants.timezone`): a customer created at 23:30 UTC on 30 September belongs to October for an `Asia/Dhaka` tenant. A month nobody was added in reads `0`.
- **Growth counts additions.** A deleted customer stays in the month it was added; the current total is `usage.max_customers.used`.
- **No live subscription** (it expired): 404, like `/subscription/usage`, which the response is built on.
- **The request aggregates nothing** ([database §2 `tenant_monthly_stats`](database.md)): on a warm cache it runs two queries — the `tenant_stats` primary-key read behind `usage`, and at most twelve `tenant_monthly_stats` rows by primary-key range. The gaps are filled in PHP. The query count does not change with the tenant's size (a test asserts the exact SQL, with no `count`, `sum` or `GROUP BY`).
- Totals by status (active/inactive customers or users) are not reported: they would need a status-change counter on every update, and nothing asked for more than the totals the plan limits already count.

```
GET /api/v1/dashboard/analytics    (Acme, seeded)

200 { "data": {
  "plan": { "id": 2, "name": "Pro", "slug": "pro" },
  "usage": { "max_users": { "used": 4, "limit": 10, "remaining": 6 },
             "max_customers": { "used": 8, "limit": 1000, "remaining": 992 } },
  "customer_growth": [ { "month": "2025-10", "customers_added": 1 }, { "month": "2025-11", "customers_added": 1 },
                       { "month": "2025-12", "customers_added": 0 }, { "month": "2026-01", "customers_added": 1 },
                       …six more…,
                       { "month": "2026-08", "customers_added": 1 }, { "month": "2026-09", "customers_added": 1 } ] } }
```

### Platform admin (`/admin`, `platform_admin` only)
| Method | Path | Notes |
|---|---|---|
| GET | `/admin/tenants` | all tenants, by `id` descending, with the live plan and user/customer counts; `filter[status]`, `filter[plan_id]`, prefix `search` on `name` and `slug` |
| PATCH | `/admin/tenants/{id}` | body `status` (`active`/`suspended`) only; 200 with the same shape as the listing; unknown id → 404 |
| GET | `/admin/analytics` | platform-wide totals, and each plan's live tenants and MRR in cents; read from job-maintained summary tables, cached 24 h |

Fixed at `S1-15`:
- **Authorization:** `auth:sanctum` without the `tenant` middleware; abilities `tenants:manage` (both tenant routes) and `platform-analytics:view`, held by `platform_admin` only. A tenant user of any role gets 403 before validation.
- **No tenant-owned model is queried through its scope here.** `Tenant` and `Plan` are not tenant-scoped; the counts come from the `tenant_stats` table joined by name.
- **Plan** is the live (`active`) subscription's plan, joined — at most one row per tenant because the join repeats the partial unique index's predicate, so `meta.total` stays exact. `null` when the tenant has no live subscription. `filter[plan_id]` filters on that join.
- **Usage** is `users` (every status) and `customers` from `tenant_stats`, left-joined on its primary key — never a count of the tenants' rows, and exact (§6). The listing is two queries (count, page) however many tenants the page holds.
- **Suspend / reactivate** writes `status`; `TenantObserver` drops the cached tenant and queues the platform stats refresh after commit, so the tenant's users get 403 (`This tenant account is suspended.`) from their next tenant request, and are let back in the same way. Tokens are not revoked ([system design](system-design.md) §3); `/auth/me` still answers for them. Writing the current status again is a 200.
- **Analytics:** `tenants` counts every tenant, the suspended ones, and `active` as the difference; `users` excludes platform admins; `plans` lists every plan in display order with its live subscription count and `mrr_cents` in its own `currency` — every `active` subscription pays (a canceled-but-running one still counts until `ends_at`), a yearly price divided by 12 and rounded down. There is no platform-wide MRR total: plans may differ in currency, and a client sums the plan rows it wants. **The request aggregates nothing**: it selects the `platform_stats` row and `plan_stats` joined to `plans` ([database §2](database.md)); `RefreshPlatformStats` computes them on the queue ~30 s after a tenant, subscription, plan, user or customer write, so figures lag by up to ~30 s plus queue wait. Zeros before the first refresh. Two queries on a miss, **cached 24 h** under `platform:analytics`, dropped when a stats row actually changes ([caching](caching.md) §1–§2); a hit runs no query.

```
GET /api/v1/admin/tenants?filter[plan_id]=2

200 { "data": [ { "id": 1, "name": "Acme Corporation", "slug": "acme", "status": "active", "timezone": "Asia/Dhaka",
                  "plan": { "id": 2, "name": "Pro", "slug": "pro" },
                  "usage": { "users": 4, "customers": 8 } } ],
      "links": { … }, "meta": { "current_page": 1, "per_page": 15, "total": 1, … } }

PATCH /api/v1/admin/tenants/1
{ "status": "suspended" }

200 { "data": { "id": 1, "name": "Acme Corporation", "slug": "acme", "status": "suspended", "timezone": "Asia/Dhaka",
                "plan": { "id": 2, "name": "Pro", "slug": "pro" }, "usage": { "users": 4, "customers": 8 } } }

GET /api/v1/admin/analytics

200 { "data": {
  "tenants": { "total": 2, "active": 2, "suspended": 0 },
  "users": 7, "customers": 14,
  "plans": [ { "id": 1, "name": "Free", "slug": "free", "tenants": 1, "currency": "USD", "mrr_cents": 0 },
             { "id": 2, "name": "Pro", "slug": "pro", "tenants": 1, "currency": "USD", "mrr_cents": 2900 },
             { "id": 3, "name": "Enterprise", "slug": "enterprise", "tenants": 0, "currency": "USD", "mrr_cents": 0 } ] } }
```

## 6. Feature-limit enforcement

`POST /users` checks `max_users` and `POST /customers` checks `max_customers`, from `UserService::create()` and `CustomerService::create()` through one method, `SubscriptionService::ensureWithinLimit(FeatureKey)`. No middleware and no per-feature checker classes: the two count-based limits share one rule.

- **The limit** is the tenant's live subscription's plan feature, read from the cache (`tenant:{id}:subscription` → `plan:{slug}`), so a platform admin's limit edit applies on the next create.
- **The usage** is the tenant's stored count in `tenant_stats` — the same value as `GET /subscription/usage`, one primary-key lookup of the created resource's column, never a `count(*)` of the table. The `Customer`/`User` observers increment and decrement it inside the create's or delete's transaction, so it is exact the moment the write commits; a delete frees a slot immediately.
- **The limit is exact under concurrency.** The check and the insert share one transaction, and the count is read `FOR UPDATE`: a concurrent create for the same tenant waits for the first to commit, then sees its row. Creates are therefore serialised per tenant — the accepted cost ([database §2, §7](database.md)).
- `null` limit = unlimited: the counter is neither read nor locked. `0` blocks every create. **A create is refused when `used >= limit`**: with 3 of 3 users the next create is a 429, with 2 of 3 it succeeds.
- The check runs after validation, and for users after the role rule (a 403 wins over a 429). Nothing is written on a 429.
- **No `Retry-After`.** A count limit does not reset with time — only an upgrade or a delete frees a slot — so there is no honest value to send.
- **No live subscription** (a canceled subscription expired): the create answers 404, like every `/subscription` endpoint.

```
POST /api/v1/users    (Globex, Free plan, 3 of 3 users)
{ "name": "Extra", "email": "extra@globex.test", "password": "secret123", "role": "member" }

429 { "message": "The plan's max_users limit of 3 has been reached.",
      "feature": "max_users", "limit": 3, "used": 3 }
```

## 7. API documentation tooling — Scramble, not L5-Swagger

**Decision: `dedoc/scramble`** (OpenAPI 3.1 generator), rejecting the more common `darkaonline/l5-swagger`.

L5-Swagger wraps `swagger-php`, which documents an endpoint through `@OA\Get(...)` annotation blocks written above each controller method — typically 20-40 lines per endpoint. Across the ~30 endpoints in §5 that is several hundred lines of annotation living inside the controllers. Two problems:

1. It contradicts this project's comment rule (root `CLAUDE.md`): docblocks are capped at one or two lines of description plus tags. Hand-written annotation blocks are exactly the kind of comment that goes stale the moment a validation rule changes.
2. It duplicates information the code already states. The request shape is in the Form Request, the response shape is in the API Resource, the parameter types are in the route binding. Restating all three in an annotation means three places to update and two of them will be forgotten.

Scramble derives the specification from those same structures — Form Requests, API Resources, enums, route model binding, type hints — with **no annotations at all**. The design decisions already made in [system design §6](system-design.md) and §2-§4 of this document are precisely what it reads, so the documentation is a by-product of the architecture rather than a parallel artifact.

**Generation and presentation are separate concerns**, and only the first is what Scramble is chosen for:

| Concern | Tool | Why |
|---|---|---|
| Produce the OpenAPI 3.1 spec | **Scramble**, from Form Requests / API Resources / route bindings | no annotations, cannot drift from the code |
| Present it to a reviewer | **Swagger UI**, pointed at that spec | the interface the client asked for, and the one reviewers recognise |

Scramble ships its own UI (Stoplight Elements / Scalar). That is replaced: Swagger UI is served at **`/api/documentation`**, reading the spec at **`/docs/api.json`**. Swagger UI is a static asset pointed at any OpenAPI URL, so this costs a route and a blade view — it does not constrain the generator.

**What it produces**
- An OpenAPI 3.1 JSON spec at `/docs/api.json`
- **Swagger UI at `/api/documentation`** — a reviewer opens one URL, reads every endpoint, and calls the API from the browser with a bearer token
- A standard OpenAPI file any client can consume — but the deliverable is the hosted Swagger UI, not a file to import

**Where hand-written text is still needed.** Scramble infers structure, not intent. Short one- or two-line docblock descriptions on controller methods become endpoint summaries, and the non-obvious behaviours — the 429 on a plan limit, the 422 on a downgrade below usage, 404-not-403 for cross-tenant — are documented explicitly rather than inferred. That prose belongs in the README's API section, not in annotations.

Fixed at `S2-08` — the few places Scramble cannot see, filled with one line each rather than annotation blocks:
- **Array responses** (`/subscription/usage`, `/dashboard/analytics`, `/admin/analytics`) return `JsonResource::make($array)`, which Scramble cannot look inside: an `@response array{data: …}` tag on the controller method gives the shape.
- **Computed resource fields** (a plan's `features` object, the admin tenant listing's joined `plan` and `usage`): an `@var array{…}` above the array item.
- **Errors thrown inside a service** (`abort_if` 409s, the plan-limit 429 on `POST /users` and `POST /customers`) are not propagated to the controller's operation: a `#[Response(status, description, type)]` attribute on the controller method.
- **Cross-endpoint behaviour** (throttle 429s, 403 for a suspended tenant, 404 across tenants) is stated once in the spec's description (`config/scramble.php` `info.description`), shown at the top of Swagger UI, rather than repeated on ~30 operations.

**Risk, stated plainly.** Scramble is pre-1.0 (v0.13.x as of Aug 2026). Its Laravel 13 support was verified at install time in `S1-02`: **0.13.43 resolves against Laravel 13.32 and generates an OpenAPI 3.1 spec, so the fallback was not needed.** Had it failed, the fallback was a **hand-written `openapi.yaml`** served through the same Swagger UI — roughly an extra hour, still Swagger, still no annotations in the controllers, but it must be kept in step with the code by hand. L5-Swagger remains rejected either way, for the reasons above. The decision is reversible at that point and nowhere later.
