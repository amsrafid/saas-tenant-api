<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\Customer;
use App\Models\TenantStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The current tenant's customers; tenant isolation comes from the model's global scope.
 */
class CustomerService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Newest customers first, optionally filtered by status and prefix-searched on name and email.
     *
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(?string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        return Customer::select(['id', 'name', 'email', 'phone', 'status'])
            ->when($status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->search(['name', 'email'], $search)
            ->orderByDesc('created_at')
            // Tiebreak on id so rows sharing a created_at never repeat or vanish between pages.
            ->orderByDesc('id')
            // Unfiltered, the total is the tenant's stored count: a count(*) over millions of rows would dominate the request.
            ->paginate($perPage, total: empty($status) && empty($search) ? TenantStats::value('customers_count') : null)
            ->withQueryString();
    }

    /**
     * The customer with the given id; another tenant's customer is not found.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id): Customer
    {
        return Customer::select(['id', 'tenant_id', 'name', 'email', 'phone', 'status'])->findOrFail($id);
    }

    /**
     * Create a customer for the current tenant, within its plan's `max_customers` limit.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $this->subscriptions->ensureWithinLimit(FeatureKey::MaxCustomers);

            return Customer::create($data);
        });
    }

    /**
     * Apply the given fields to the customer.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer;
    }

    /**
     * Delete the customer permanently, together with its count.
     *
     * @throws ModelNotFoundException
     */
    public function delete(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            // A concurrent delete of the same customer waits here, then finds nothing, so the count drops once.
            Customer::select(['id'])->lockForUpdate()->findOrFail($customer->id);
            $customer->delete();
        });
    }
}
