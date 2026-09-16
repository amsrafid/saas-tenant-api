# Caching, Rate Limiting & Background Jobs

Covers submission requirement **#5** (caching strategy and invalidation). Redis 7 backs the cache, the queue, and the rate-limit buckets.

---

## 1. What gets cached, and what does not

Caching is a decision per case, not a default. A value is cached only when it is **read far more often than written** and **expensive or repetitive to produce**.

| Cached | Not cached |
|---|---|
| Active subscription + plan limits (read on nearly every request) | Customer and user listings — filtered, sorted and paginated, so the key space is unbounded and the hit rate would be near zero |
| Active plan list (changes rarely, read publicly) | Anything a client can vary freely by query string |
| Dashboard analytics (aggregate queries over the whole tenant) | Single-record reads — already a primary-key index hit; caching would add a network round-trip to save one |
| Usage counts against limits | Auth state — Sanctum resolves the token per request by design |

Caching a paginated listing is the common mistake here: it multiplies keys by every filter/sort/page combination, so almost every read misses while every write has to invalidate a set nobody can enumerate.

## 2. Key naming — every tenant-owned key carries its tenant

```
tenant:{tenant_id}:subscription           → active subscription + plan + features
tenant:{tenant_id}:usage:{period_start}   → per-feature usage counts
tenant:{tenant_id}:dashboard              → analytics payload
plans:active                              → platform-wide active plan list (no tenant segment: not tenant data)
```

**A missing tenant segment in a key is a cross-tenant data leak, not a performance bug.** Keys are therefore built only by a `CacheKey` helper that takes the tenant from `TenantContext` — never by string concatenation at the call site. This is exactly the kind of mistake that is invisible in review and obvious in production.

## 3. TTLs — length follows invalidation reliability, not guesswork

**A TTL is a safety net, not the invalidation strategy.** It follows that a value with reliable observer-based invalidation should have a *long* TTL: expiry is never the mechanism that keeps it correct, so a short one only costs recomputation. A value that must expire quickly to stay correct is a value whose invalidation is missing — a design smell to fix, not a number to tune.

| Key | TTL | Invalidated by | Reasoning |
|---|---|---|---|
| `tenant:{id}:subscription` | **24 hours** | `Subscription`, `Plan`, `PlanFeature` observers | Read on nearly every request; every write that could change it fires an observer |
| `tenant:{id}:usage:counts` | **24 hours** | `User`, `Customer` create/delete observers | Count-based limits change only through Eloquent writes, all of which are observed |
| `tenant:{id}:dashboard:{date}` | **24 hours** | the same write observers | The date in the key makes period rollover self-invalidating, so no short TTL is needed to keep the growth series current. The date is the tenant's local date ([database §3](database.md)), not the UTC one, so a tenant's "today" turns over at their midnight |
| `plans:active` | **24 hours** | `Plan`, `PlanFeature` observers | Platform-wide, changes only on an admin edit |
| `tenant:{id}:usage:metered:{period}` | **60 seconds** | nothing — incremented continuously | The exception: API request counts rise without any Eloquent event to hook, so this one genuinely depends on expiry. Kept short and deliberately called out as the only such key |

**No `Cache::forever()` anywhere.** An unbounded key with a missed invalidation is stale permanently, with no self-healing path. 24 hours is the ceiling: long enough that expiry is irrelevant in practice, short enough that a bug repairs itself within a day.

## 4. Invalidation — observers, not TTLs

**The TTL is not the invalidation strategy; the observer is.** The TTL only bounds the damage if an observer is ever missed.

| Event | Invalidates |
|---|---|
| `Subscription` created / updated / deleted | `tenant:{id}:subscription`, `tenant:{id}:dashboard` |
| `Plan` or `PlanFeature` saved / deleted | `plans:active`, **and** `tenant:{id}:subscription` for every tenant on that plan |
| `User` created / deleted | `tenant:{id}:usage:*`, `tenant:{id}:dashboard` |
| `Customer` created / deleted | `tenant:{id}:usage:*`, `tenant:{id}:dashboard` |
| `Tenant` updated | `tenant:{id}:dashboard` |

Three details that make this correct rather than merely present:

1. **Eloquent events do not fire on bulk operations.** A mass `insert()`, `update()` or `delete()` bypasses observers entirely. Every such call site carries an explicit `forget()`; there are few, and they are listed in the README.
2. **A plan edit fans out.** Changing `max_users` on the Pro plan must invalidate the cached limits of *every* tenant on Pro, not just the plan list. This is handled by a queued job on the `low` queue — correctness without blocking the admin's request.
3. **Limits are re-checked at write time, inside the transaction.** The cached usage count drives the *response* (a fast "you have 3 seats left"), but the authoritative check before creating a user or customer reads the real count. A cache must never be the last line of defence for a business invariant — a stale count would otherwise let a tenant exceed a paid limit.

Cache writes use `Cache::remember()` (read-through), so a miss repopulates transparently and no code path can read a half-populated value.

## 5. Query performance

Covered in detail in [database §5–§6](database.md). In summary: indexes follow real query patterns with the two Postgres traps checked explicitly, every relation touched in a loop is eager-loaded, aggregates are computed in SQL, and listing/dashboard endpoints carry Pest query-count assertions so a reintroduced N+1 fails the test suite rather than review.

## 6. Rate limiting

| Limiter | Limit | Key |
|---|---|---|
| `auth` | 5 / minute | IP + submitted email — slows credential stuffing without locking a real user out by IP alone |
| `api` | 60 / minute | authenticated user id |
| `plan` | the tenant's `api_requests_per_day` feature | tenant id, daily bucket |

The third is the interesting one: **the rate limit is driven by the subscription plan**, so quota becomes a product feature rather than a fixed constant. It reads the same cached plan limits as everything else, and returns 429 with `Retry-After` plus the usage numbers in the body.

Unauthenticated routes are limited by IP. All buckets live in Redis, so limits hold across every application container rather than per-process.

## 7. Background jobs — three priority queues

Jobs are dispatched to a queue deliberately; nothing is left on `default` by accident. Workers run `queue:work --queue=high,default,low`, so a long analytics job can never delay a user-facing one.

| Queue | Work | Why |
|---|---|---|
| `high` | password reset, email verification | a person is actively blocked waiting |
| `default` | user invitations, subscription change notifications | transactional, expected within a minute |
| `low` | usage aggregation, dashboard precomputation, plan-change cache fan-out, expired-subscription sweep | nothing is waiting synchronously |

**Scheduled work:** an hourly command transitions subscriptions past `ends_at` to `expired` (using the `(status, ends_at)` index), and a daily command rolls `feature_usage` into a new period.

All jobs are **idempotent** and carry an explicit tenant id, set on `TenantContext` before touching data — a queued job has no request to infer tenancy from, and this is precisely where a missing tenant scope would silently operate on the wrong company's data.
