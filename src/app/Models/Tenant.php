<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Models\Concerns\Searchable;
use App\Observers\TenantObserver;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer company of the platform; the unit of data isolation.
 */
#[Fillable(['name', 'slug', 'timezone'])]
#[ObservedBy(TenantObserver::class)]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use Searchable;

    /**
     * Mirrors the column default, so a tenant just inserted reports its timezone without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'Asia/Dhaka',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }
}
