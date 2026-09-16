<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * A newly issued bearer token with the user and tenant it belongs to.
 *
 * @property NewAccessToken $resource
 */
class IssuedTokenResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'access_token' => $this->resource->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $this->resource->accessToken->expires_at,
            'user' => UserResource::make($this->resource->accessToken->tokenable),
        ];
    }
}
