<?php

namespace App\Http\Requests\Admin;

use App\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the platform tenant listing: page, page size, search, and status and plan filters.
 */
class ListTenantsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['array:status,plan_id'],
            'filter.status' => [Rule::enum(TenantStatus::class)],
            'filter.plan_id' => ['integer', 'min:1'],
        ];
    }

    /**
     * Get the custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['filter.array' => 'The filter may only contain: status, plan_id.'];
    }
}
