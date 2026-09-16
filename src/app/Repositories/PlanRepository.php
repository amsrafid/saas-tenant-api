<?php

namespace App\Repositories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Plans with their features, cached for a day; PlanObserver calls forget() after every plan write, PlanService::update() after a limits-only change.
 */
class PlanRepository
{
    private const ACTIVE_KEY = 'plans:active';

    /**
     * Every active plan with its features, in display order.
     *
     * @return Collection<int, Plan>
     */
    public function active(): Collection
    {
        // The catalogue is a handful of rows written only by a platform admin, so it is cached whole and paged in memory.
        return Cache::remember(self::ACTIVE_KEY, now()->addDay(), fn () => Plan::select(['id', 'name', 'slug', 'price_cents', 'currency', 'billing_period', 'is_active'])
            ->with('features:plan_id,key,limit_value')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get());
    }

    /**
     * The plan with the given slug and its features, active or not.
     */
    public function findBySlug(string $slug): ?Plan
    {
        return Cache::remember($this->key($slug), now()->addDay(), fn () => Plan::select(['id', 'name', 'slug', 'price_cents', 'currency', 'billing_period', 'is_active'])
            ->with('features:plan_id,key,limit_value')
            ->firstWhere('slug', $slug));
    }

    /**
     * Drop the cached plan and the active plan list after the plan was created, changed or deleted.
     */
    public function forget(string $slug): void
    {
        Cache::forget($this->key($slug));
        Cache::forget(self::ACTIVE_KEY);
    }

    /**
     * Drop many cached plans and the active plan list in one cache call.
     *
     * @param  list<string>  $slugs
     */
    public function forgetMany(array $slugs): void
    {
        Cache::deleteMultiple([self::ACTIVE_KEY, ...array_map(fn (string $slug) => $this->key($slug), $slugs)]);
    }

    private function key(string $slug): string
    {
        return "plan:{$slug}";
    }
}
