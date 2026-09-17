<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\SelectPlanRequest;
use App\Http\Resources\SubscriptionResource;
use App\Services\SubscriptionService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * The current tenant's subscription and its usage against the plan's limits.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Get the subscription.
     *
     * The live subscription with its plan and feature limits; 404 when the tenant has none.
     */
    public function show(): SubscriptionResource
    {
        return SubscriptionResource::make($this->subscriptions->current());
    }

    /**
     * Subscribe to a plan.
     *
     * Owner only, for a tenant with no live subscription (409 otherwise). The plan must be active.
     */
    #[OpenApiResponse(409, 'The tenant already has a live subscription.', type: 'array{message: string}')]
    public function store(SelectPlanRequest $request): JsonResponse
    {
        return SubscriptionResource::make($this->subscriptions->subscribe($request->validated('plan')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Change plan.
     *
     * Owner only. Ends the current subscription and starts a new one; refused (422) when usage exceeds a new limit.
     */
    #[OpenApiResponse(409, 'The tenant is already on this plan.', type: 'array{message: string}')]
    public function update(SelectPlanRequest $request): JsonResponse
    {
        // The new plan is a new row, which a resource would answer with 201; to the client it is an update.
        return SubscriptionResource::make($this->subscriptions->changePlan($request->validated('plan')))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Cancel the subscription.
     *
     * Owner only. `ends_at` becomes the end of the current billing period; the subscription stays live until then.
     */
    #[OpenApiResponse(409, 'The subscription is already canceled.', type: 'array{message: string}')]
    public function destroy(): SubscriptionResource
    {
        return SubscriptionResource::make($this->subscriptions->cancel());
    }

    /**
     * Get usage.
     *
     * Per feature: `used`, `limit` and `remaining`; a null limit and remaining mean unlimited.
     *
     * @response array{data: array{max_users: array{used: int, limit: int|null, remaining: int|null}, max_customers: array{used: int, limit: int|null, remaining: int|null}}}
     */
    public function usage(): JsonResource
    {
        return JsonResource::make($this->subscriptions->usage());
    }
}
