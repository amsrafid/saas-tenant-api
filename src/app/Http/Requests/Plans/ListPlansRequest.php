<?php

namespace App\Http\Requests\Plans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the public plan listing: page and page size.
 */
class ListPlansRequest extends FormRequest
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
        ];
    }
}
