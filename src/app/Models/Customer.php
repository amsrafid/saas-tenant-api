<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Models\Concerns\Searchable;
use App\Observers\CustomerObserver;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An end-customer of a tenant; a business record that never authenticates.
 */
#[Fillable(['name', 'email', 'phone', 'status'])]
#[ObservedBy(CustomerObserver::class)]
class Customer extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use Searchable;

    /**
     * A customer created without a status starts active.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => CustomerStatus::Active->value,
    ];

    /**
     * The tenant owning this customer record.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
        ];
    }
}
