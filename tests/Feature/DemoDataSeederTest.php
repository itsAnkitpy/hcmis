<?php

use App\Models\Call;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

it('skips outside the local environment', function () {
    // The test environment is "testing", so the local-only guard must bail.
    $this->seed(DemoDataSeeder::class);

    expect(Tenant::where('slug', 'like', 'demo-%')->count())->toBe(0);
});

it('seeds a coherent demo world in local and is safe to re-run', function () {
    $this->app['env'] = 'local';

    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class); // re-run must not duplicate

    $tenants = Tenant::where('slug', 'like', 'demo-%')->get();
    expect($tenants)->toHaveCount(2);

    foreach ($tenants as $tenant) {
        TenantContext::run($tenant->id, function () {
            $campaigns = Campaign::all();
            expect($campaigns)->toHaveCount(2);

            foreach ($campaigns as $campaign) {
                expect($campaign->dispositions()->count())->toBeGreaterThan(0)
                    ->and($campaign->scripts()->count())->toBe(2)
                    ->and($campaign->leads()->count())->toBe(30);
            }
        });
    }
});

it('seeds a multi-agent lab with coarse-shaped call history (Live Agent Board, Slice 1)', function () {
    $this->app['env'] = 'local';

    $this->seed(DemoDataSeeder::class);

    $tenant = Tenant::where('slug', 'demo-acme-outbound')->firstOrFail();
    $firstCount = TenantContext::run($tenant->id, fn (): int => Call::count());

    $this->seed(DemoDataSeeder::class); // re-run must not duplicate the history

    [$secondCount, $agentSpread, $withDurations] = TenantContext::run($tenant->id, fn (): array => [
        Call::count(),
        (int) Call::distinct()->count('agent_id'),
        Call::whereNotNull('duration_seconds')->count(),
    ]);

    expect($firstCount)->toBeGreaterThan(0)          // history was seeded
        ->and($secondCount)->toBe($firstCount)        // seed-once: re-run adds nothing
        ->and($agentSpread)->toBeGreaterThan(2)       // a real floor of agents, not just the two softphone ones
        ->and($withDurations)->toBe(0);               // coarse v1 shape: no made-up call durations
});
