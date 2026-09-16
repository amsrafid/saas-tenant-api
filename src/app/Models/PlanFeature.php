<?php

namespace App\Models;

use App\Enums\FeatureKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One feature limit on a plan; a null limit value means unlimited.
 */
#[Fillable(['key', 'limit_value'])]
class PlanFeature extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => FeatureKey::class,
            'limit_value' => 'integer',
        ];
    }
}
