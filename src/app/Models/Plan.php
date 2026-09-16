<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Observers\PlanObserver;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform-owned subscription plan; not tenant-scoped.
 */
#[Fillable(['name', 'slug', 'price_cents', 'currency', 'billing_period', 'is_active', 'sort_order'])]
#[ObservedBy(PlanObserver::class)]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * Slug of the plan every newly registered tenant starts on.
     */
    public const FREE_SLUG = 'free';

    /**
     * A plan created without these starts active and first in display order.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * The limits this plan grants, one row per feature key.
     *
     * @return HasMany<PlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'billing_period' => BillingPeriod::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
