<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The current tenant's dashboard analytics.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * Get dashboard analytics.
     *
     * The live plan, per-feature usage against its limits, and customers added in each of the last 12 months of the tenant's timezone, oldest first; 404 when the tenant has no live subscription.
     *
     * @response array{data: array{plan: array{id: int, name: string, slug: string}, usage: array{max_users: array{used: int, limit: int|null, remaining: int|null}, max_customers: array{used: int, limit: int|null, remaining: int|null}}, customer_growth: list<array{month: string, customers_added: int}>}}
     */
    public function analytics(): JsonResource
    {
        return JsonResource::make($this->dashboard->analytics());
    }
}
