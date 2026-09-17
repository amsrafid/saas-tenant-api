<?php

namespace App\Http\Requests\Plans;

use App\Enums\BillingPeriod;
use App\Enums\FeatureKey;
use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new plan; every feature limit must be sent, null meaning unlimited.
 */
class StorePlanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $featureKeys = array_column(FeatureKey::cases(), 'value');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique(Plan::class, 'slug')],
            'price_cents' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'billing_period' => ['required', Rule::enum(BillingPeriod::class)],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:32767'],
            'features' => ['required', 'array:'.implode(',', $featureKeys)],
            ...array_fill_keys(
                array_map(fn (string $key) => "features.{$key}", $featureKeys),
                ['present', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            ),
        ];
    }
}
