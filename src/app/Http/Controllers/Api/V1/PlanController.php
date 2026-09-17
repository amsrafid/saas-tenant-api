<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plans\ListPlansRequest;
use App\Http\Requests\Plans\StorePlanRequest;
use App\Http\Requests\Plans\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Services\PlanService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The public plan catalogue, and its management by a platform admin.
 */
class PlanController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    /**
     * List active plans.
     *
     * Public and paginated, in display order, each with its feature limits; a null limit means unlimited.
     */
    public function index(ListPlansRequest $request): AnonymousResourceCollection
    {
        return PlanResource::collection($this->plans->paginateActive(
            $request->validated('page', 1),
            $request->validated('per_page', 15),
        ));
    }

    /**
     * Get a plan.
     *
     * Public; an inactive plan is still returned, with `is_active` false.
     */
    public function show(string $slug): PlanResource
    {
        return PlanResource::make($this->plans->findBySlug($slug));
    }

    /**
     * Create a plan.
     *
     * Platform admin only. Every feature limit is required; null means unlimited.
     */
    public function store(StorePlanRequest $request): JsonResponse
    {
        return PlanResource::make($this->plans->create($request->validated()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update a plan.
     *
     * Platform admin only. Partial, feature limits included; the slug cannot change.
     */
    public function update(UpdatePlanRequest $request, int $id): PlanResource
    {
        return PlanResource::make($this->plans->update($this->plans->find($id), $request->validated()));
    }

    /**
     * Delete a plan.
     *
     * Platform admin only. A plan with subscriptions is refused (409); deactivate it instead.
     */
    #[OpenApiResponse(409, 'Subscriptions refer to the plan.', type: 'array{message: string}')]
    public function destroy(int $id): Response
    {
        $this->plans->delete($this->plans->find($id));

        return response()->noContent();
    }
}
