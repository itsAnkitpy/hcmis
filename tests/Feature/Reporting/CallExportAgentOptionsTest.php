<?php

use App\Enums\RoleName;
use App\Filament\Pages\Reports\CallExportReport;
use App\Models\Call;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/** The Agent dropdown's list, read the way the filter form reads it. */
function agentOptionsOf(): array
{
    return (fn (): array => $this->agentOptions())->call(new CallExportReport);
}

/** One client, one named agent, one call of theirs. */
function clientWithAgent(string $name): array
{
    $tenant = Tenant::factory()->create();

    $agent = TenantContext::run($tenant->id, function () use ($name): User {
        $agent = User::factory()->create(['name' => $name]);
        Call::factory()->forAgent($agent)->create();

        return $agent;
    });

    return [$tenant, $agent];
}

// 🔴 S119 N3. The list is cached now, so the CACHE KEY is the whole tenant wall for this
// dropdown. A key that forgot the tenant would serve the first client's staff names to the
// second — the wall broken by a performance change, which is how these usually break. The
// order matters: the first read is what populates the cache the second one must not hit.
it('never serves one client\'s agents to another once the list is cached', function () {
    [$acme, $acmeAgent] = clientWithAgent('Abhikesh');
    [$globex, $globexAgent] = clientWithAgent('Priya');

    $this->actingAs(clientUserWithRole($acme, RoleName::TeamLeader->value));

    TenantContext::run($acme->id, function () use ($acmeAgent) {
        expect(agentOptionsOf())->toBe([$acmeAgent->id => 'Abhikesh']);
    });

    $this->actingAs(clientUserWithRole($globex, RoleName::TeamLeader->value));

    TenantContext::run($globex->id, function () use ($globexAgent) {
        expect(agentOptionsOf())->toBe([$globexAgent->id => 'Priya']);
    });
});
