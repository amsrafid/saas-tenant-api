<?php

namespace App\Http\Requests\Users;

use App\Enums\TenantRole;
use App\Enums\UserStatus;
use App\Http\Requests\Concerns\NormalizesEmail;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a partial user update; only the fields sent are checked.
 */
class UpdateUserRequest extends FormRequest
{
    use NormalizesEmail;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($this->route('id')),
            ],
            'role' => ['sometimes', 'required', Rule::enum(TenantRole::class)->except(TenantRole::PlatformAdmin)],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
        ];
    }
}
