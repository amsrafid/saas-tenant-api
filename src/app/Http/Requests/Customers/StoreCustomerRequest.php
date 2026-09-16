<?php

namespace App\Http\Requests\Customers;

use App\Enums\CustomerStatus;
use App\Http\Requests\Concerns\NormalizesEmail;
use App\Models\Customer;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new customer; the email is unique within the current tenant only.
 */
class StoreCustomerRequest extends FormRequest
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
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(Customer::class, 'email')
                    ->where(fn (Builder $query) => $query->where('tenant_id', app(TenantContext::class)->id())),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'status' => [Rule::enum(CustomerStatus::class)],
        ];
    }
}
