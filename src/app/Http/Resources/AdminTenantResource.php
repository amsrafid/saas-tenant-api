<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tenant as a platform admin sees it: its live plan, or null, and its user and customer counts.
 *
 * @mixin Tenant
 */
class AdminTenantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'timezone' => $this->timezone,
            /** @var array{id: int, name: string, slug: string}|null */
            'plan' => empty($this->plan_id) ? null : [
                'id' => $this->plan_id,
                'name' => $this->plan_name,
                'slug' => $this->plan_slug,
            ],
            /** @var array{users: int, customers: int} */
            'usage' => [
                'users' => $this->users_count,
                'customers' => $this->customers_count,
            ],
        ];
    }
}
