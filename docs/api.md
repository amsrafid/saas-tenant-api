# API Design

Supports submission requirement **#4**. Endpoint samples with real request/response bodies are generated from the working implementation and served as Swagger UI (§7), so documentation and code cannot drift.

Base path: `/api/v1`. Versioned from day one — an unversioned API cannot make a breaking change without breaking every client.

---

## 1. Conventions

- **Auth:** `Authorization: Bearer <sanctum-token>` on everything except `POST /auth/register`, `POST /auth/login`, and `GET /plans`.
- **Content:** JSON in, JSON out. `Accept: application/json` is enforced so Laravel never returns an HTML error page to an API client.
- **Naming:** plural nouns for collections, `snake_case` field names, ISO-8601 UTC timestamps (`2026-09-19T11:30:00Z`).
- **Verbs:** `GET` read, `POST` create, `PATCH` partial update (not `PUT` — every update here is partial), `DELETE` remove.
- **No `tenant_id` anywhere in a request.** It is derived from the authenticated token. A client cannot address another tenant even by trying.

## 2. Response envelope

Every successful response uses one shape, produced by API Resources — a controller never returns a raw model or array.

**Single resource**
```json
{ "data": { "id": 12, "name": "Acme Ltd", "slug": "acme-ltd", "status": "active",
            "created_at": "2026-09-16T09:00:00Z" } }
```

**Collection** — pagination metadata is always present, even on a single page:
```json
{
  "data": [ { "id": 1, "name": "Jane Doe", "email": "jane@acme.test", "status": "active" } ],
  "meta":  { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 },
  "links": { "first": "…?page=1", "prev": null, "next": "…?page=2", "last": "…?page=3" }
}
```

**Error** — one shape for every failure, so a client needs exactly one error path:
```json
{
  "message": "The given data was invalid.",
  "errors": { "email": ["The email has already been taken."] }
}
```
`errors` is present only on 422. A rendering handler maps every exception to this shape; no stack trace or SQL ever reaches a client.

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
| 429 | rate limit or plan API quota exceeded |

## 3. Pagination, filtering, sorting

Applied uniformly to every listing endpoint via one shared `QueryBuilder` support class, so behaviour and parameter names never vary between resources.

| Parameter | Behaviour |
|---|---|
| `page` | 1-based; out-of-range returns an empty `data` with valid `meta`, not a 404 |
| `per_page` | default 15, **hard cap 100** — a client cannot request the whole table |
| `search` | prefix match on the resource's designated searchable columns |
| `filter[status]`, `filter[role]`, `filter[plan_id]` | whitelisted per resource; an unknown filter key is a 422, not silently ignored |
| `sort` | whitelisted columns only, `-` prefix for descending (`sort=-created_at`); default `-created_at` |

Whitelisting matters for more than tidiness: passing user input into an `orderBy` or `where` column name is an injection vector. The whitelist is the control.

## 4. Validation

Every write endpoint has a Form Request. Rules are declared once and read directly by the OpenAPI generator (§7), so a validation rule and its documentation can never disagree. Unknown fields are rejected rather than ignored, so a client typo fails loudly instead of silently doing nothing.

## 5. Endpoints

### Auth
| Method | Path | Notes |
|---|---|---|
| POST | `/auth/register` | Creates tenant **and** its owner user in one transaction. Assigns the Free plan. Returns token + tenant + user. |
| POST | `/auth/login` | Throttled per IP+email. Generic failure message. |
| POST | `/auth/logout` | Revokes the presented token only. |
| GET | `/auth/me` | Current user with tenant and role. |

### Tenant (current company)
| Method | Path | Role |
|---|---|---|
| GET | `/tenant` | any member |
| PATCH | `/tenant` | owner, admin |

### Users (staff)
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/users` | any member | paginated, `filter[role]`, `filter[status]`, `search` |
| POST | `/users` | owner, admin | **enforces `max_users`** → 429 with the limit in the body |
| GET | `/users/{id}` | any member | 404 across tenants |
| PATCH | `/users/{id}` | owner, admin | cannot grant a role above your own; cannot change your own role |
| DELETE | `/users/{id}` | owner | the last owner cannot be deleted |

### Customers
| Method | Path | Notes |
|---|---|---|
| GET | `/customers` | paginated, `filter[status]`, `search`, `sort` |
| POST | `/customers` | **enforces `max_customers`** |
| GET / PATCH / DELETE | `/customers/{id}` | scoped binding; 404 across tenants |

### Plans
| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/plans` | public | active plans with their features; heavily cached |
| GET | `/plans/{slug}` | public | |
| POST / PATCH / DELETE | `/admin/plans…` | platform_admin | a plan with live subscriptions cannot be deleted → 409 |

### Subscription
| Method | Path | Role | Notes |
|---|---|---|---|
| GET | `/subscription` | any member | current plan, status, period, limits |
| POST | `/subscription` | owner | subscribe; 409 if already active |
| PATCH | `/subscription` | owner | change plan. **A downgrade is refused with 422 when current usage exceeds the target plan's limits**, naming the offending feature — silently breaking the invariant would be worse than refusing. |
| DELETE | `/subscription` | owner | cancel; runs to `ends_at` rather than terminating immediately |
| GET | `/subscription/usage` | any member | per-feature `used` / `limit` / `remaining`, `null` limit = unlimited |

### Dashboard
| Method | Path | Notes |
|---|---|---|
| GET | `/dashboard/analytics` | totals (users, customers, active/inactive), customer growth over the last 12 periods, current plan and usage ratios, cached (§ [caching](caching.md) §3) |

### Platform admin (`/admin`, `platform_admin` only)
| Method | Path |
|---|---|
| GET | `/admin/tenants` — all tenants with plan and usage |
| PATCH | `/admin/tenants/{id}` — suspend / reactivate |
| GET | `/admin/analytics` — platform-wide totals, tenants per plan, MRR from `price_cents` |

This namespace is the **only** place the tenant scope is bypassed, which keeps the isolation audit surface to one directory.

## 6. Feature-limit enforcement

An `EnforcePlanLimit` middleware, parameterised per route (`limit:max_customers`), runs before the controller. Each limit type is its own checker class behind a common interface (Strategy), so a new limit means a new class and a seeder row — no change to any controller.

Exceeding a limit returns **429** with a body naming the feature, the limit, and the current usage, so a client can show a meaningful upgrade prompt rather than a bare error.

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

**Risk, stated plainly.** Scramble is pre-1.0 (v0.13.x as of Aug 2026). Its Laravel 13 support was verified at install time in `S1-02`: **0.13.43 resolves against Laravel 13.32 and generates an OpenAPI 3.1 spec, so the fallback was not needed.** Had it failed, the fallback was a **hand-written `openapi.yaml`** served through the same Swagger UI — roughly an extra hour, still Swagger, still no annotations in the controllers, but it must be kept in step with the code by hand. L5-Swagger remains rejected either way, for the reasons above. The decision is reversible at that point and nowhere later.
