<?php

namespace Tests\Fixtures;

use App\Models\Customer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Test job recording which customers it can see; a null tenant id models a job that forgot to set its context.
 */
class RecordVisibleCustomerEmails implements ShouldQueue
{
    use Queueable;

    /**
     * @var list<array{had_context: bool, emails: list<string>|null}>
     */
    public static array $observations = [];

    public function __construct(
        public readonly ?int $tenantId,
        public readonly bool $throwAfterwards = false,
    ) {}

    /**
     * Set the carried tenant, record what is visible, and optionally fail.
     */
    public function handle(TenantContext $tenantContext): void
    {
        $hadContext = $tenantContext->has();

        if (! empty($this->tenantId)) {
            $tenantContext->set(Tenant::select(['id', 'name', 'slug', 'status', 'timezone'])->findOrFail($this->tenantId));
        }

        self::$observations[] = ['had_context' => $hadContext, 'emails' => $this->visibleEmails()];

        if ($this->throwAfterwards) {
            throw new RuntimeException('Job failed after setting its tenant.');
        }
    }

    /**
     * @return list<string>|null
     */
    private function visibleEmails(): ?array
    {
        try {
            return Customer::orderBy('email')->limit(10)->pluck('email')->all();
        } catch (RuntimeException) {
            return null;
        }
    }
}
