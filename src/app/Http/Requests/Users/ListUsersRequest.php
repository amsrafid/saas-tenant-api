<?php

namespace App\Http\Requests\Users;

use App\Enums\TenantRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the user listing: page, page size, search and role and status filters.
 */
class ListUsersRequest extends FormRequest
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
            'filter' => ['array:role,status'],
            'filter.role' => [Rule::enum(TenantRole::class)->except(TenantRole::PlatformAdmin)],
            'filter.status' => [Rule::enum(UserStatus::class)],
        ];
    }

    /**
     * Get the custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['filter.array' => 'The filter may only contain: role, status.'];
    }
}
