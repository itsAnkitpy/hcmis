<?php

namespace Tests\Fixtures;

use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stand-in for the real M5 import job — a no-auth path (no logged-in user).
 * Per D-002, such paths MUST set tenant context explicitly; the job carries
 * its own tenant_id and wraps work in TenantContext::run().
 */
class ImportLeadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public string $label,
    ) {}

    public function handle(): void
    {
        TenantContext::run($this->tenantId, fn () => TenantOwnedModel::create(['label' => $this->label]));
    }
}
