<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Observers\SubscriptionObserver;
use App\Tenancy\BelongsToTenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's subscription to a plan; plan changes insert a new row so history is kept.
 */
#[Fillable(['plan_id', 'starts_at', 'ends_at', 'canceled_at'])]
#[ObservedBy(SubscriptionObserver::class)]
class Subscription extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * The subscribing tenant.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The subscribed plan.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }
}
