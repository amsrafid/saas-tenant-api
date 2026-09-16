<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a login attempt.
 */
class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
