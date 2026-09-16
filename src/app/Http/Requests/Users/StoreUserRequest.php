<?php

namespace App\Http\Requests\Users;

use App\Enums\TenantRole;
use App\Http\Requests\Concerns\NormalizesEmail;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates a new user; the email is unique across every tenant, because it is the login.
 */
class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            // At most 72 bytes: bcrypt silently ignores anything longer.
            'password' => ['required', 'string', Password::min(8), 'max:72'],
            'role' => ['required', Rule::enum(TenantRole::class)->except(TenantRole::PlatformAdmin)],
        ];
    }
}
