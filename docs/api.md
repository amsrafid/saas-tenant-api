# API Design

Covers submission requirement **#4**. The samples in §5 are real: they were captured from the running API against the seeded demo data.

Base path: `/api/v1`. Versioned from the start, so a breaking change can ship without breaking every client.

---

## 1. Conventions

- **Auth:** `Authorization: Bearer <sanctum-token>` on everything except `POST /auth/register`, `POST /auth/login`, `GET /plans` and `GET /plans/{slug}`.
- **Content:** JSON in, JSON out. `Accept: application/json` is enforced so Laravel never returns an HTML error page to an API client.
- **Naming:** plural nouns for collections, `snake_case` field names, ISO-8601 UTC timestamps.
- **Verbs:** `GET` read, `POST` create, `PATCH` partial update (not `PUT` — every update here is partial), `DELETE` remove.
- **No `tenant_id` anywhere in a request.** It is derived from the authenticated token, so a client cannot address another tenant even by trying.

## 2. Response envelope

Every response goes through an API Resource; a controller never returns a raw model.

A resource returns only the fields a client uses. `created_at`, `updated_at` and a plan's `sort_order` are not returned, and the query does not even select them. A date appears only when it means something — a subscription's `starts_at`, `ends_at`, `canceled_at`.

**Single resource**
```json
{ "data": { "id": 1, "name": "Acme Corporation", "slug": "acme", "status": "active", "timezone": "Asia/Dhaka" } }
```

**Collection** — Laravel's default paginated resource shape, present even on a single page:
```json
{
  "data": [ { "id": 8, "name": "Oakridge Print House", "email": "team@oakridge-print.test", "phone": null, "status": "inactive" }, … ],
  "links": { "first": "…?per_page=2&page=1", "last": "…?per_page=2&page=4", "prev": null, "next": "…?per_page=2&page=2" },
  "meta": { "current_page": 1, "from": 1, "last_page": 4, "links": [ … ], "path": "http://localhost:8000/api/v1/customers",
            "per_page": 2, "to": 2, "total": 8 }
}
```

**Error** — Laravel's own JSON error rendering, switched on for every `api/*` route. Every error has `message`; a 422 also has `errors` keyed by field:
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

**Rate limits** ([caching §4](caching.md)): 60/minute per user on logged-in routes, 60/minute per IP on `GET /plans*`, 5/minute per IP+email on login and register, and 10/hour per IP on register.

Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`. A throttle 429 carries `Retry-After` and says `Too Many Attempts.`; a plan-limit 429 has its own body and no `Retry-After` (§6).

## 3. Pagination, filtering, search

Every listing takes the same parameters. Each one has a `List{X}Request` holding the rules, including the list of allowed filter keys — which is also what keeps user input out of a column name.

| Parameter | Behaviour |
|---|---|
| `page` | 1-based; out-of-range returns an empty `data` with valid `meta`, not a 404 |
| `per_page` | default 15, **hard cap 100** — a client cannot request the whole table |
| `search` | prefix match on the resource's designated searchable columns |
| `filter[status]`, `filter[role]`, `filter[plan_id]` | whitelisted per resource; an unknown filter key is a 422, not silently ignored |

- A `per_page` above 100 (or below 1), a `page` below 1, an unknown `filter` key and a filter value outside its enum are all **422** — never clamped or ignored.
- The order is fixed: newest first (`created_at` descending, then `id` descending so rows with equal timestamps never repeat or vanish between pages). There is no `sort` parameter.
- `search` is at most 100 characters; `%`, `_` and `\` in it match literally.
- **`meta.total` does not count the table.** With no filter and no search, `GET /customers` and `GET /users` read the total from the stored counter (`tenant_stats`) and run only the page query. With a filter or a search they count the matching rows, which the indexes serve ([database §5–§6](database.md)). The shape is the same either way.
- An unknown top-level query parameter such as `?sort=name` is ignored rather than rejected, because the unknown-field check reads the body only. It reaches no query: the controller reads validated keys only.

## 4. Validation

Every write endpoint has a Form Request. The same rules are read by the OpenAPI generator (§7), so the documentation cannot disagree with the validation.

Unknown fields are rejected, not ignored, so a client typo fails loudly instead of doing nothing. On listings that covers `filter[...]` keys too. Broken JSON reads as an empty body, so it fails as missing required fields (422).

## 5. Endpoints

### Auth
| Method | Path | Notes |
|---|---|---|
| POST | `/auth/register` | Creates tenant **and** its owner user in one transaction. Assigns the Free plan. Returns token + tenant + user. Throttled per IP+email (5/min, shared with login) and per IP (10/hour). |
| POST | `/auth/login` | Throttled per IP+email. Generic failure message: 422 on `email`, identical for unknown email and wrong password. Disabled account → 403. |
| POST | `/auth/logout` | Revokes the presented token only. 204. |
| GET | `/auth/me` | Current user with tenant and role, for every authenticated user (no tenant middleware). `tenant` is `null` for a platform admin. |

Register and login both return `{ "data": { "access_token", "token_type": "Bearer", "expires_at", "user": { "id", "name", "email", "role", "status", "tenant": { "id", "name", "slug", "status", "timezone" } } } }`; `/auth/me` returns the same `user` object. Register takes `tenant_name`, `name`, `email`, `password` (8–72 characters).

```http
POST /api/v1/auth/register
{ "tenant_name": "Initech", "name": "Peter Gibbons", "email": "peter@initech.test", "password": "password123" }
```
```json
201 { "data": { "access_token": "2|9ohrRflV4TqXSeWD6dHDMJG6sSq952esLbuGH30c6ce09758", "token_type": "Bearer",
                "expires_at": "2026-09-24T16:35:48.000000Z",
                "user": { "id": 9, "name": "Peter Gibbons", "email": "peter@initech.test", "role": "owner", "status": "active",
                          "tenant": { "id": 3, "name": "Initech", "slug": "initech", "status": "active", "timezone": "Asia/Dhaka" } } } }
```
```http
POST /api/v1/auth/login
{ "email": "owner@acme.test", "password": "password" }
```
```json
200 { "data": { "access_token": "1|yI7BF0ilTsMVsXzflYzDQsp9PvouFdYBEVmbEnYOf1bb7b8d", "token_type": "Bearer",
                "expires_at": "2026-09-24T16:35:47.000000Z",
                "user": { "id": 2, "name": "Acme Owner", "email": "owner@acme.test", "role": "owner", "status": "active",
                          "tenant": { "id": 1, "name": "Acme Corporation", "slug": "acme", "status": "active", "timezone": "Asia/Dhaka" } } } }
```
```http
POST /api/v1/auth/login
{ "email": "owner@acme.test", "password": "wrong" }
```
```json
422 { "message": "These credentials do not match our records.",
      "errors": { "email": [ "These credentials do not match our records." ] } }
```
```http
GET /api/v1/auth/me
Authorization: Bearer 2|9ohrRflV4TqXSeWD6dHDMJG6sSq952esLbuGH30c6ce09758
```
```json
200 { "data": { "id": 9, "name": "Peter Gibbons", "email": "peter@initech.test", "role": "owner", "status": "active",
                "tenant": { "id": 3, "name": "Initech", "slug": "initech", "status": "active", "timezone": "Asia/Dhaka" } } }
```

### Tenant (current company)
| Method | Path | Role |
|---|---|---|
| GET | `/tenant` | any member |
| PATCH | `/tenant` | owner, admin |

- **Only the caller's own tenant** is addressed — the one `ResolveTenant` already put in `TenantContext`, so `GET` runs no query of its own (read from the `tenant:{id}` cache). There is no id to point at another tenant.
- **Response:** `{ "data": { "id", "name", "slug", "status", "timezone" } }`, for GET and PATCH (200).
- **PATCH body:** any subset of `name` and `timezone` (an IANA zone id; an offset like `+06:00` is a 422). `slug` is fixed once registered and `status` belongs to the platform admin, so sending either is a 422 like any unknown field.
- **Changing `timezone` applies from the next request:** new customers' dashboard growth month follows the new zone; past months are not re-bucketed.

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

- **Body:** POST takes `name`, `email` (trimmed and lower-cased, **unique across all tenants** — it is the login), `password` (8–72), `role`; new users are `active`. PATCH accepts any subset of `name`, `email`, `role`, `status`; no password change.
- **Response:** `{ "data": { "id", "name", "email", "role", "status" } }`.
- **Role rules**, checked in `UserService` after the ability gate:
  1. You cannot grant a role above your own (create or update) → 403.
  2. You cannot modify or delete a user whose role is above your own → 403.
  3. You cannot change your own role → 403.
  4. The last active owner cannot be deleted, demoted or disabled → 409. Checked before rule 3, so a sole owner demoting themselves learns why.
- **Disabling or deleting a user deletes their tokens**, so access ends on the next request. A role change keeps them: gates read the role on every request.

Samples run as `owner@acme.test` unless the heading says otherwise.

```http
GET /api/v1/users?per_page=2
```
```json
200 { "data": [ { "id": 5, "name": "Acme Member Two", "email": "member2@acme.test", "role": "member", "status": "active" },
                { "id": 4, "name": "Acme Member One", "email": "member1@acme.test", "role": "member", "status": "active" } ],
      "links": { "first": "http://localhost:8000/api/v1/users?per_page=2&page=1", "last": "http://localhost:8000/api/v1/users?per_page=2&page=2",
                 "prev": null, "next": "http://localhost:8000/api/v1/users?per_page=2&page=2" },
      "meta": { "current_page": 1, "from": 1, "last_page": 2,
                "links": [ { "url": null, "label": "&laquo; Previous", "page": null, "active": false },
                           { "url": "http://localhost:8000/api/v1/users?per_page=2&page=1", "label": "1", "page": 1, "active": true }, … ],
                "path": "http://localhost:8000/api/v1/users", "per_page": 2, "to": 2, "total": 4 } }
```
```http
POST /api/v1/users
{ "name": "Jane Cooper", "email": "Jane.Cooper@acme.test", "password": "password123", "role": "member" }
```
```json
201 { "data": { "id": 10, "name": "Jane Cooper", "email": "jane.cooper@acme.test", "role": "member", "status": "active" } }
```
```http
PATCH /api/v1/users/10
{ "role": "admin", "status": "disabled" }
```
```json
200 { "data": { "id": 10, "name": "Jane Cooper", "email": "jane.cooper@acme.test", "role": "admin", "status": "disabled" } }
```
```http
GET /api/v1/users/6    (a Globex user)
```
```json
404 { "message": "No query results for model [App\\Models\\User] 6" }
```
Role rules, as `admin@acme.test` (user 2 is the owner, user 3 the admin itself):
```http
POST /api/v1/users          { "name": "New Owner", "email": "new.owner@acme.test", "password": "password123", "role": "owner" }
PATCH /api/v1/users/2       { "status": "disabled" }
PATCH /api/v1/users/3       { "role": "member" }
```
```json
403 { "message": "You cannot grant a role above your own." }
403 { "message": "You cannot modify a user whose role is above your own." }
403 { "message": "You cannot change your own role." }
```
As `owner@acme.test`, the only owner:
```http
PATCH /api/v1/users/2    { "role": "admin" }
DELETE /api/v1/users/2
```
```json
409 { "message": "The tenant must keep at least one active owner." }
```
Plan limit, as `owner@globex.test` (Free plan, 3 of 3 users):
```http
POST /api/v1/users
{ "name": "Extra", "email": "extra@globex.test", "password": "secret123", "role": "member" }
```
```json
429 { "message": "The plan's max_users limit of 3 has been reached.", "feature": "max_users", "limit": 3, "used": 3 }
```

### Customers
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/customers` | any member | paginated, newest first, `filter[status]`, `search` (name, email) |
| POST | `/customers` | any member | **enforces `max_customers`** → 429 (§6); 201 |
| GET | `/customers/{id}` | any member | 404 across tenants |
| PATCH | `/customers/{id}` | any member | partial; 404 across tenants |
| DELETE | `/customers/{id}` | owner, admin | hard delete, 204; 404 across tenants |

- **Members create and edit customers; only owners and admins delete them.** Customers are day-to-day working records, so every staff member maintains them; deletion is permanent, so it is held back from `member`.
- **Body:** `name` (required), `email` (required, trimmed and lower-cased, **unique within the tenant**), `phone` (nullable), `status` (defaults to `active`). PATCH accepts any subset.
- **Response:** `{ "data": { "id", "name", "email", "phone", "status" } }` — one shape for the listing and a single customer.
- **Authorization runs before validation**, so a caller without the ability gets 403 whatever the body. A cross-tenant id is a 404 like a missing one, and a non-numeric id does not match the route.

```http
GET /api/v1/customers?per_page=2
```
```json
200 { "data": [ { "id": 8, "name": "Oakridge Print House", "email": "team@oakridge-print.test", "phone": null, "status": "inactive" },
                { "id": 7, "name": "Silver Pine Hotel", "email": "frontdesk@silverpine.test", "phone": null, "status": "active" } ],
      "links": { "first": "http://localhost:8000/api/v1/customers?per_page=2&page=1", "last": "http://localhost:8000/api/v1/customers?per_page=2&page=4",
                 "prev": null, "next": "http://localhost:8000/api/v1/customers?per_page=2&page=2" },
      "meta": { "current_page": 1, "from": 1, "last_page": 4, "links": [ … ], "path": "http://localhost:8000/api/v1/customers",
                "per_page": 2, "to": 2, "total": 8 } }
```
```http
POST /api/v1/customers
{ "name": "Northwind Traders", "email": "Sales@Northwind.test", "phone": "+8801711000000" }
```
```json
201 { "data": { "id": 15, "name": "Northwind Traders", "email": "sales@northwind.test", "phone": "+8801711000000", "status": "active" } }
```
```http
PATCH /api/v1/customers/15
{ "status": "inactive", "phone": null }
```
```json
200 { "data": { "id": 15, "name": "Northwind Traders", "email": "sales@northwind.test", "phone": null, "status": "inactive" } }
```
```http
POST /api/v1/customers
{ "name": "Dup", "email": "sales@northwind.test" }
```
```json
422 { "message": "The email has already been taken.", "errors": { "email": [ "The email has already been taken." ] } }
```
```http
GET /api/v1/customers/9    (a Globex customer, requested as Acme)
```
```json
404 { "message": "No query results for model [App\\Models\\Customer] 9" }
```
Plan limit, as `owner@globex.test` (Free plan, 10 of 10 customers):
```http
POST /api/v1/customers
{ "name": "Pinecrest Farms", "email": "hello@pinecrest-farms.test" }
```
```json
429 { "message": "The plan's max_customers limit of 10 has been reached.", "feature": "max_customers", "limit": 10, "used": 10 }
```

### Plans
| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/plans` | public | active plans with their features, in display order; paginated, served from cache |
| GET | `/plans/{slug}` | public | any plan, an inactive one included (`is_active` says so); 404 for an unknown slug |
| POST | `/admin/plans` | platform_admin | 201 |
| PATCH | `/admin/plans/{id}` | platform_admin | partial, feature limits included |
| DELETE | `/admin/plans/{id}` | platform_admin | 204; a plan any subscription refers to → 409 |

- **Body:** POST takes `name`, `slug` (lower-case letters, digits and single hyphens, unique), `price_cents` (minor units), `currency`, `billing_period` (`monthly`/`yearly`), `is_active`, `sort_order` and `features` — an object with **every** feature key, each an integer ≥ 0 or `null` for unlimited. PATCH accepts any subset except `slug`, which is fixed once created; `features` may carry only the limits that change.
- **Response:** `{ "data": { "id", "name", "slug", "price_cents", "currency", "billing_period", "is_active", "features": { "max_users": 3, "max_customers": 10 } } }`.
- **Deleting** a plan is refused with 409 while any subscription row refers to it — a canceled or expired one included, because the foreign key restricts on every row and plan history must survive. Deactivate it instead (`is_active: false`): it leaves the listing and stays readable by slug, and existing subscriptions are untouched.
- A tenant user of any role gets 403 on the admin routes.

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
| PATCH | `/subscription` | owner | change plan. **A downgrade is refused with 422 when current usage exceeds the target plan's limits**, naming the offending feature. |
| DELETE | `/subscription` | owner | cancel; runs to the end of the current billing period rather than terminating immediately; 200 with the subscription |
| GET | `/subscription/usage` | any member | per-feature `used` / `limit` / `remaining`, `null` limit = unlimited |

- **Body:** POST and PATCH take `plan` — the slug of an **active** plan. An unknown or inactive slug is a 422 on `plan`.
- **Response:** `{ "data": { "id", "status", "starts_at", "ends_at", "canceled_at", "plan": { …the plan object of /plans… } } }` for GET, POST (201), PATCH (200) and DELETE (200). The limits are the plan's `features`. `ends_at` is `null` while the subscription runs uncanceled.
- **Only the live subscription is addressed** — the tenant's one `active` row. With none (it expired), GET, PATCH, DELETE and usage are 404, and POST is the way back in; POST while one is live is 409.
- **Changing plan** ends the live subscription now (`canceled`, `canceled_at` and `ends_at` set to now) and starts a new `active` one with `ends_at: null`, in one transaction. History is kept; there is no proration. The same plan again is 409.
- **The downgrade rule** applies to every plan change and to POST: when current `max_users` or `max_customers` usage is above the target plan's limit, the request is a 422 on `plan` with one message per feature over the limit. Usage exactly at the limit is allowed.
- **Cancelling** sets `canceled_at` to now and `ends_at` to the first billing-period boundary after now, counted in whole months or years from `starts_at` (a month-end start clamps without drifting). The row stays `active` until `ends_at`. A second cancel is 409.
- **Period end** is handled by the hourly job ([caching §5](caching.md)): a canceled subscription becomes `expired` once `ends_at` passes. Nothing renews — there is no billing.
- **Usage** covers `max_users` and `max_customers` (the tenant's stored counts, every status, exact — §6). `remaining` is `null` for an unlimited feature and never below `0`.

```http
GET /api/v1/subscription
```
```json
200 { "data": { "id": 1, "status": "active", "starts_at": "2026-09-17T16:35:38.000000Z", "ends_at": null, "canceled_at": null,
                "plan": { "id": 2, "name": "Pro", "slug": "pro", "price_cents": 2900, "currency": "USD", "billing_period": "monthly",
                          "is_active": true, "features": { "max_users": 10, "max_customers": 1000 } } } }
```
```http
DELETE /api/v1/subscription
```
```json
200 { "data": { "id": 1, "status": "active", "starts_at": "2026-09-17T16:35:38.000000Z", "ends_at": "2026-10-17T16:35:38.000000Z",
                "canceled_at": "2026-09-17T16:38:12.000000Z", "plan": { …as above… } } }
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
| GET | `/dashboard/analytics` | any member | the live plan, usage per feature, customers added in each of the last 12 months; read from stored counters, not cached |

- **Authorization:** ability `dashboard:view`, held by owner, admin and member; a platform admin gets 403 from the tenant middleware.
- **Response:** `plan` (`id`, `name`, `slug`); `usage` — the same object as `GET /subscription/usage`; `customer_growth` — twelve `{ month, customers_added }` entries, oldest first, ending with the current month. **Months are the tenant's** (`tenants.timezone`): a customer created at 23:30 UTC on 30 September belongs to October for an `Asia/Dhaka` tenant. A month nobody was added in reads `0`.
- **Growth counts additions.** A deleted customer stays in the month it was added; the current total is `usage.max_customers.used`.
- **No live subscription** (it expired): 404, like `/subscription/usage`, which the response is built on.
- **The request aggregates nothing** ([database §2](database.md)): on a warm cache it runs two queries — the `tenant_stats` primary-key read behind `usage`, and at most twelve `tenant_monthly_stats` rows by primary-key range, with gaps filled in PHP. The query count does not change with the tenant's size (a test asserts the exact SQL).

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

- **Authorization:** `auth:sanctum` without the `tenant` middleware; abilities `tenants:manage` and `platform-analytics:view`, held by `platform_admin` only.
- **Plan** is the live (`active`) subscription's plan, joined — at most one row per tenant because the join repeats the partial unique index's predicate, so `meta.total` stays exact. `null` when the tenant has no live subscription. **Usage** comes from `tenant_stats`, left-joined on its primary key — never a count of the tenants' rows. The listing is two queries however many tenants the page holds.
- **Suspend / reactivate** writes `status`; `TenantObserver` drops the cached tenant after commit, so the tenant's users get 403 from their next tenant request, and are let back in the same way. Tokens are not revoked ([system design §3](system-design.md)); `/auth/me` still answers for them.
- **Analytics:** `tenants` counts every tenant, the suspended ones, and `active` as the difference; `users` excludes platform admins; `plans` lists every plan with its live subscription count and `mrr_cents` in its own `currency` (a yearly price ÷ 12, rounded down). There is no platform-wide MRR total: plans may differ in currency. **The request aggregates nothing** — it selects the `platform_stats` row and `plan_stats` joined to `plans`; `RefreshPlatformStats` computes them on the queue ~30 s after a write, so figures lag by that much, and read zeros before the first refresh.

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
- **The usage** is the tenant's stored count in `tenant_stats` — the same value as `GET /subscription/usage`, one primary-key lookup, never a `count(*)` of the table. The observers adjust it inside the create's or delete's transaction, so a delete frees a slot immediately.
- **The limit is exact under concurrency.** The check and the insert share one transaction, and the count is read `FOR UPDATE`, so a tenant's creates queue behind each other ([database §2](database.md)).
- `null` limit = unlimited; `0` blocks every create. **A create is refused when `used >= limit`.** The check runs after validation, and for users after the role rule (a 403 wins over a 429). Nothing is written on a 429, and there is **no `Retry-After`**: a count limit does not reset with time.
- **No live subscription** (a canceled subscription expired): the create answers 404, like every `/subscription` endpoint.

## 7. API documentation

`dedoc/scramble` builds the OpenAPI 3.1 spec from the Form Requests, API Resources, enums and route bindings. There are no annotations in the controllers, so the spec cannot drift from the code. The spec is served at `/docs/api.json`, and Swagger UI reads it at `/api/documentation`, where a reviewer can log in and call every endpoint.

Scramble reads structure, not intent, so four things are written by hand, one line each:

- **Array responses** (`/subscription/usage`, `/dashboard/analytics`, `/admin/analytics`) get an `@response array{...}` tag on the controller method.
- **Computed fields** (a plan's `features`, the admin listing's `plan` and `usage`) get an `@var array{...}`.
- **Errors thrown inside a service** (the 409s, the plan-limit 429) get a `#[Response(...)]` attribute.
- **Rules that apply everywhere** (throttle 429, 403 for a suspended tenant, 404 across tenants) are written once in the spec description, shown at the top of Swagger UI.
