<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan with its feature limits keyed by feature; a null limit means unlimited.
 *
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'billing_period' => $this->billing_period,
            'is_active' => $this->is_active,
            /** @var array{max_users: int|null, max_customers: int|null} */
            'features' => $this->features->mapWithKeys(fn (PlanFeature $feature) => [$feature->key->value => $feature->limit_value]),
        ];
    }
}
