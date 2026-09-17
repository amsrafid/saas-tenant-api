<?php

namespace App\Http\Requests\Plans;

use App\Enums\BillingPeriod;
use App\Enums\FeatureKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a partial plan update; only the fields and feature limits sent are checked, and the slug is fixed.
 */
class UpdatePlanRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price_cents' => ['sometimes', 'required', 'integer', 'min:0', 'max:2147483647'],
            'currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/'],
            'billing_period' => ['sometimes', 'required', Rule::enum(BillingPeriod::class)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'features' => ['sometimes', 'array:'.implode(',', $featureKeys)],
            ...array_fill_keys(
                array_map(fn (string $key) => "features.{$key}", $featureKeys),
                ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            ),
        ];
    }
}
