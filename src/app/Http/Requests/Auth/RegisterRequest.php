<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates a company registration.
 */
class RegisterRequest extends FormRequest
{
    use NormalizesEmail;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenant_name' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            // At most 72 bytes: bcrypt silently ignores anything longer.
            'password' => ['required', 'string', Password::min(8), 'max:72'],
        ];
    }
}
