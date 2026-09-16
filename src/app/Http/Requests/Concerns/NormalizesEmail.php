<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

/**
 * Trims and lower-cases the `email` input, so uniqueness and login are case-insensitive.
 */
trait NormalizesEmail
{
    /**
     * Normalise the email before the rules run.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }
}
