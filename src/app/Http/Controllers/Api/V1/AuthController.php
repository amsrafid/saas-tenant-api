<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\IssuedTokenResource;
use App\Http\Resources\UserResource;
use App\Repositories\TenantRepository;
use App\Services\AuthService;
use App\Services\AuthUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Registration, login, logout and the current user.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuthUserService $authUser,
        private readonly TenantRepository $tenants,
    ) {}

    /**
     * Register a company.
     *
     * Creates the tenant, its owner and a Free plan subscription in one transaction, and returns the owner's token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        return IssuedTokenResource::make($this->auth->register($request->validated()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Log in.
     *
     * Throttled to 5 attempts a minute per IP and email; a wrong email and a wrong password get the same 422.
     */
    public function login(LoginRequest $request): IssuedTokenResource
    {
        return IssuedTokenResource::make($this->auth->login($request->validated('email'), $request->validated('password')));
    }

    /**
     * Log out.
     *
     * Revokes only the token this request was made with; the user's other tokens stay valid.
     */
    public function logout(): Response
    {
        $this->authUser->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Get the current user.
     *
     * Returns the user with their role and tenant; `tenant` is null for a platform admin.
     */
    public function me(): UserResource
    {
        $user = $this->authUser->user();

        return UserResource::make($user->setRelation('tenant', $this->tenants->find($user->tenant_id)));
    }
}
