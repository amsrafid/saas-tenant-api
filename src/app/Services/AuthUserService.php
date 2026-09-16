<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The only place the current user is read; a request resolves it from the guard, a job sets it explicitly.
 */
class AuthUserService
{
    private ?User $user = null;

    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * The acting user.
     *
     * @throws AuthenticationException
     */
    public function user(): User
    {
        if (empty($this->user)) {
            $user = $this->auth->guard()->user();

            if (empty($user)) {
                throw new AuthenticationException;
            }

            $this->user = $user;
        }

        return $this->user;
    }

    /**
     * Make the given user the acting one, for jobs and commands that have no request.
     */
    public function set(User $user): void
    {
        $this->user = $user;
    }

    /**
     * Clear the acting user at the end of a request or job.
     */
    public function forget(): void
    {
        $this->user = null;
    }
}
