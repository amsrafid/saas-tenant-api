<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Repositories\PlanRepository;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform's plan catalogue; reads go through the cache, which PlanObserver drops on every plan write.
 */
class PlanService
{
    public function __construct(private readonly PlanRepository $plans) {}

    /**
     * One page of the active plans, in display order.
     *
     * @return LengthAwarePaginator<int, Plan>
     */
    public function paginateActive(int $page, int $perPage): LengthAwarePaginator
    {
        $plans = $this->plans->active();

        $paginator = new LengthAwarePaginator(
            $plans->forPage($page, $perPage)->values(),
            $plans->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        return $paginator->withQueryString();
    }

    /**
     * The plan with the given slug, active or not.
     *
     * @throws ModelNotFoundException
     */
    public function findBySlug(string $slug): Plan
    {
        return $this->plans->findBySlug($slug) ?? throw (new ModelNotFoundException)->setModel(Plan::class, [$slug]);
    }

    /**
     * The plan with the given id, read from the database for a write.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id): Plan
    {
        return Plan::select(['id', 'name', 'slug', 'price_cents', 'currency', 'billing_period', 'is_active'])->findOrFail($id);
    }

    /**
     * Create a plan with its feature limits.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Plan
    {
        $plan = DB::transaction(function () use ($data) {
            $plan = Plan::create(Arr::except($data, 'features'));
            $this->saveFeatures($plan, $data['features']);

            return $plan;
        });

        return $plan->load('features:plan_id,key,limit_value');
    }

    /**
     * Apply the given fields and feature limits to the plan; limits not sent are kept.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Plan $plan, array $data): Plan
    {
        DB::transaction(function () use ($plan, $data) {
            $plan->update(Arr::except($data, 'features'));
            $this->saveFeatures($plan, $data['features'] ?? []);
        });

        // The feature upsert fires no model events, so a limits-only change cannot count on PlanObserver.
        $this->plans->forget($plan->slug);

        return $plan->load('features:plan_id,key,limit_value');
    }

    /**
     * Delete the plan and its features; a plan any subscription refers to is kept.
     */
    public function delete(Plan $plan): void
    {
        // Any subscription row blocks the restrict foreign key, a canceled or expired one included.
        $hasSubscriptions = Subscription::withoutGlobalScope(TenantScope::class)->where('plan_id', $plan->id)->exists();

        abort_if($hasSubscriptions, Response::HTTP_CONFLICT, 'A plan with subscriptions cannot be deleted; deactivate it instead.');

        $plan->delete();
    }

    /**
     * @param  array<string, int|null>  $features
     */
    private function saveFeatures(Plan $plan, array $features): void
    {
        $rows = array_map(
            fn (string $key, ?int $limit) => ['plan_id' => $plan->id, 'key' => $key, 'limit_value' => $limit],
            array_keys($features),
            $features,
        );

        PlanFeature::upsert($rows, ['plan_id', 'key'], ['limit_value']);
    }
}
