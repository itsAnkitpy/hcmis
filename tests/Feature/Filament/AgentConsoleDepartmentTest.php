<?php

use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Department;
use App\Models\Disposition;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

beforeEach(function () {
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);
});

/**
 * inbound-audio slice 7 (D6) — an ANSWERED call's row is written by the agent's screen,
 * not the call flow, so the department and the widened flag reach it only by being
 * copied off the ring-time note at wrap-up. Miss that copy and every answered call
 * saves no department while every missed-call test stays green.
 */
it('copies the department and widened off the ring-time note onto the answered call', function (bool $widened) {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);
    $ticket = (string) Str::uuid();

    [$department, $disposition] = TenantContext::run($tenant->id, function () use ($agent, $ticket, $widened): array {
        $department = Department::factory()->create();
        CallHandoff::factory()->forAgent($agent)->ticket($ticket)->create([
            'arrived_at' => now()->subMinute(),
            'department_id' => $department->id,
            'department_widened' => $widened,
        ]);

        return [$department, Disposition::factory()->create()];
    });

    $page = new AgentConsole;
    $page->callCorrelationId = $ticket;
    $page->callPartyNumber = '9991234567';
    TenantContext::run($tenant->id, function () use ($page, $disposition): void {
        $page->saveCustomer('Ravi Menon');   // wrap-up records against a matched customer
        $page->saveWrapUp($disposition->id);
    });

    $call = TenantContext::run($tenant->id, fn (): Call => Call::query()->sole());

    expect($call->department_id)->toBe($department->id)
        ->and($call->department_widened)->toBe($widened);
})->with(['widened' => true, 'not widened' => false]);
