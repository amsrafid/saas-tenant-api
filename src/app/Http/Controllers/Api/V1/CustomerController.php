<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\ListCustomersRequest;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The current tenant's customers.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    /**
     * List customers.
     *
     * Paginated, newest first; filter by `status` and prefix-search `name` and `email`.
     */
    public function index(ListCustomersRequest $request): AnonymousResourceCollection
    {
        $customers = $this->customers->paginate(
            $request->validated('filter.status'),
            $request->validated('search'),
            $request->validated('per_page', 15),
        );

        return CustomerResource::collection($customers);
    }

    /**
     * Create a customer.
     *
     * The email must be unique within the tenant; the same email may exist at another tenant.
     */
    #[OpenApiResponse(429, 'The plan\'s max_customers limit is reached.', type: 'array{message: string, feature: string, limit: int, used: int}')]
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        return CustomerResource::make($this->customers->create($request->validated()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Get a customer.
     *
     * A customer of another tenant is a 404, exactly like a missing one.
     */
    public function show(int $id): CustomerResource
    {
        return CustomerResource::make($this->customers->find($id));
    }

    /**
     * Update a customer.
     *
     * Partial: only the fields sent change.
     */
    public function update(UpdateCustomerRequest $request, int $id): CustomerResource
    {
        return CustomerResource::make($this->customers->update($this->customers->find($id), $request->validated()));
    }

    /**
     * Delete a customer.
     *
     * Permanent; there is no restore.
     */
    public function destroy(int $id): Response
    {
        $this->customers->delete($this->customers->find($id));

        return response()->noContent();
    }
}
