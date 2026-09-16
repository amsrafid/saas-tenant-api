<?php

namespace Database\Seeders;

use App\Enums\BillingPeriod;
use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Repositories\PlanRepository;
use App\Services\AdminService;
use Illuminate\Database\Seeder;

/**
 * Seeds the Free, Pro and Enterprise plans and their limits; this seeder is the source of truth for them.
 */
class PlanSeeder extends Seeder
{
    /**
     * Plan definitions keyed by slug; a null limit means unlimited.
     *
     * @var array<string, array{name: string, price_cents: int, sort_order: int, features: array<string, int|null>}>
     */
    private const PLANS = [
        Plan::FREE_SLUG => [
            'name' => 'Free',
            'price_cents' => 0,
            'sort_order' => 1,
            'features' => [
                FeatureKey::MaxUsers->value => 3,
                FeatureKey::MaxCustomers->value => 10,
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'price_cents' => 2900,
            'sort_order' => 2,
            'features' => [
                FeatureKey::MaxUsers->value => 10,
                FeatureKey::MaxCustomers->value => 1000,
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price_cents' => 9900,
            'sort_order' => 3,
            'features' => [
                FeatureKey::MaxUsers->value => null,
                FeatureKey::MaxCustomers->value => null,
            ],
        ],
    ];

    /**
     * Create each plan and limit, or bring an existing one back in line with the definitions; a re-run writes nothing.
     */
    public function run(PlanRepository $plans, AdminService $admin): void
    {
        $changedPlans = $this->upsertChangedPlans();
        $changedFeatures = $this->upsertChangedFeatures();

        if (empty($changedPlans) && empty($changedFeatures)) {
            return;
        }

        // Upserts fire no PlanObserver event, so the caches it would drop are dropped here.
        $plans->forgetMany(array_keys(self::PLANS));
        $admin->forgetAnalytics();
    }

    /**
     * Existing plans are read once and compared in PHP, so only a new or changed plan is written.
     */
    private function upsertChangedPlans(): int
    {
        $existing = Plan::select(['slug', 'name', 'price_cents', 'currency', 'billing_period', 'is_active', 'sort_order'])
            ->whereIn('slug', array_keys(self::PLANS))
            ->get()
            ->keyBy('slug');

        $changed = collect(self::PLANS)
            ->map(fn (array $definition, string $slug) => [
                'slug' => $slug,
                'name' => $definition['name'],
                'price_cents' => $definition['price_cents'],
                'currency' => 'USD',
                'billing_period' => BillingPeriod::Monthly->value,
                'is_active' => true,
                'sort_order' => $definition['sort_order'],
            ])
            ->reject(fn (array $row) => $existing->has($row['slug'])
                && $existing[$row['slug']]->name === $row['name']
                && $existing[$row['slug']]->price_cents === $row['price_cents']
                && $existing[$row['slug']]->currency === $row['currency']
                && $existing[$row['slug']]->billing_period->value === $row['billing_period']
                && $existing[$row['slug']]->is_active === $row['is_active']
                && $existing[$row['slug']]->sort_order === $row['sort_order'])
            ->values()
            ->all();

        if (! empty($changed)) {
            Plan::upsert($changed, ['slug'], ['name', 'price_cents', 'currency', 'billing_period', 'is_active', 'sort_order']);
        }

        return count($changed);
    }

    /**
     * Existing limits are read once and compared in PHP, so only a new or changed limit is written.
     */
    private function upsertChangedFeatures(): int
    {
        $planIds = Plan::whereIn('slug', array_keys(self::PLANS))->pluck('id', 'slug');

        $existing = PlanFeature::select(['plan_id', 'key', 'limit_value'])
            ->whereIn('plan_id', $planIds->values())
            ->get()
            ->keyBy(fn (PlanFeature $feature) => "{$feature->plan_id}:{$feature->key->value}");

        $changed = collect(self::PLANS)
            ->flatMap(fn (array $definition, string $slug) => collect($definition['features'])
                ->map(fn (?int $limit, string $key) => ['plan_id' => $planIds[$slug], 'key' => $key, 'limit_value' => $limit])
                ->values())
            ->reject(fn (array $row) => $existing->has("{$row['plan_id']}:{$row['key']}")
                && $existing["{$row['plan_id']}:{$row['key']}"]->limit_value === $row['limit_value'])
            ->values()
            ->all();

        if (! empty($changed)) {
            PlanFeature::upsert($changed, ['plan_id', 'key'], ['limit_value']);
        }

        return count($changed);
    }
}
