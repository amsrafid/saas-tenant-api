<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ListUsersRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Services\UserService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The current tenant's staff users.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    /**
     * List users.
     *
     * Paginated, newest first; filter by `role` and `status` and prefix-search `name` and `email`.
     */
    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        $users = $this->users->paginate(
            $request->validated('filter.role'),
            $request->validated('filter.status'),
            $request->validated('search'),
            $request->validated('per_page', 15),
        );

        return UserResource::collection($users);
    }

    /**
     * Create a user.
     *
     * The email must be unique across all tenants; you cannot grant a role above your own.
     */
    #[OpenApiResponse(429, 'The plan\'s max_users limit is reached.', type: 'array{message: string, feature: string, limit: int, used: int}')]
    public function store(StoreUserRequest $request): JsonResponse
    {
        return UserResource::make($this->users->create($request->validated()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Get a user.
     *
     * A user of another tenant is a 404, exactly like a missing one.
     */
    public function show(int $id): UserResource
    {
        return UserResource::make($this->users->find($id));
    }

    /**
     * Update a user.
     *
     * Partial. You cannot edit a user above your own role, change your own role, or demote or disable the last owner (409).
     */
    #[OpenApiResponse(409, 'The tenant must keep an active owner.', type: 'array{message: string}')]
    public function update(UpdateUserRequest $request, int $id): UserResource
    {
        return UserResource::make($this->users->update($this->users->find($id), $request->validated()));
    }

    /**
     * Delete a user.
     *
     * Permanent, and revokes the user's tokens; the last owner cannot be deleted (409).
     */
    #[OpenApiResponse(409, 'The tenant must keep an active owner.', type: 'array{message: string}')]
    public function destroy(int $id): Response
    {
        $this->users->delete($this->users->find($id));

        return response()->noContent();
    }
}
