<?php

namespace App\Http\Requests\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the plan chosen when subscribing or changing plan; whether it is sellable is checked by the service.
 */
class SelectPlanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'max:255'],
        ];
    }
}
