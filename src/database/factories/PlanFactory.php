<?php

namespace Database\Factories;

use App\Enums\BillingPeriod;
use App\Enums\FeatureKey;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds Plan models for tests and seeders.
 *
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'price_cents' => fake()->numberBetween(0, 50_000),
            'currency' => 'USD',
            'billing_period' => BillingPeriod::Monthly,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Give the plan a row for every feature; a feature not listed is unlimited.
     *
     * @param  array<string, int|null>  $limits
     */
    public function withLimits(array $limits = []): static
    {
        return $this->afterCreating(fn (Plan $plan) => $plan->features()->createMany(
            array_map(fn (FeatureKey $key) => ['key' => $key, 'limit_value' => $limits[$key->value] ?? null], FeatureKey::cases()),
        ));
    }
}
