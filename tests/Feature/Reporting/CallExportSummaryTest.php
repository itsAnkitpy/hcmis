<?php

use App\Enums\CallDirection;
use App\Enums\RoleName;
use App\Filament\Pages\Reports\CallExportReport;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * The "Ready to export N calls — …" line (S107). It exists because this page has no
 * table: a filter left over from an earlier click is otherwise invisible until the
 * spreadsheet is already open, and the file then looks like it lost rows. Every test
 * here is really one test — does the line tell the truth about what the button will do.
 */

/** The page, with the filter form holding the given state, inside a client's context. */
function summaryPage(Tenant $tenant, array $filters): CallExportReport
{
    $page = new CallExportReport;
    $page->filters = $filters;

    return $page;
}

it('counts exactly what the download would write', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    $agent = TenantContext::run($tenant->id, function (): User {
        $agent = User::factory()->create(['name' => 'Abhikesh']);
        Call::factory()->count(6)->forAgent($agent)->create();
        Call::factory()->count(3)->create(['agent_id' => null]);

        return $agent;
    });

    TenantContext::run($tenant->id, function () use ($tenant, $agent) {
        expect(summaryPage($tenant, [])->exportSummary())->toContain('9 calls')
            ->and(summaryPage($tenant, ['agentId' => $agent->id])->exportSummary())->toContain('6 calls');
    });
});

// The whole point: the filter that produced the small number has to be named.
it('names a leftover agent filter in the line, not just the smaller number', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    $agent = TenantContext::run($tenant->id, function (): User {
        $agent = User::factory()->create(['name' => 'Abhikesh']);
        Call::factory()->forAgent($agent)->create();
        Call::factory()->create(['agent_id' => null]);

        return $agent;
    });

    TenantContext::run($tenant->id, function () use ($tenant, $agent) {
        expect(summaryPage($tenant, ['agentId' => $agent->id])->exportSummary())
            ->toContain('agent Abhikesh');
    });
});

// CE-3's earlier half. The route is the guard, but the screen is where the supervisor
// can still do something about it — so the warning has to arrive before the click, and
// it has to carry both numbers, exactly as the refusal does.
it('warns on screen when the filters would produce more rows than the cap', function () {
    config(['hcims.call_export_max_rows' => 2]);

    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    TenantContext::run($tenant->id, fn () => Call::factory()->count(3)->create());

    TenantContext::run($tenant->id, fn () => expect(summaryPage($tenant, [])->exportSummary())
        ->toContain('3 calls')
        ->toContain('over the 2')
        ->not->toContain('Ready to export'));
});

it('says plainly when nothing is filtered', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    TenantContext::run($tenant->id, fn () => Call::factory()->create());

    TenantContext::run($tenant->id, fn () => expect(summaryPage($tenant, [])->exportSummary())
        ->toContain('1 call')
        ->toContain('no filters set'));
});

it('describes every filter it can be given, in everyday words', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    $agent = TenantContext::run($tenant->id, fn (): User => User::factory()->create(['name' => 'Abhikesh']));

    TenantContext::run($tenant->id, function () use ($tenant, $agent) {
        $parts = summaryPage($tenant, [
            'startDate' => '2026-08-12',
            'endDate' => '2026-08-13',
            'agentId' => $agent->id,
            'direction' => CallDirection::Inbound->value,
            'hasRecording' => '0',
        ])->appliedFilters();

        expect($parts)->toContain('12 Aug 2026 to 13 Aug 2026')
            ->toContain('agent Abhikesh')
            ->toContain('Inbound only')
            ->toContain('without a recording');
    });
});

it('says "on" rather than a range when both dates are the same day', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    TenantContext::run($tenant->id, fn () => expect(
        summaryPage($tenant, ['startDate' => '2026-08-13', 'endDate' => '2026-08-13'])->appliedFilters()
    )->toContain('on 13 Aug 2026'));
});

// The duration toggle changes how a number is written, not which calls come out, so it
// must not appear in a line whose whole job is explaining a row count.
it('leaves the duration format out of the filter list', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    TenantContext::run($tenant->id, fn () => expect(
        summaryPage($tenant, ['durationFormat' => 'clock'])->appliedFilters()
    )->toBe([]));
});

// The page has to actually mount. A Filament page can compile perfectly and still crash
// on render (a typed cast without Wireable did exactly that once here), and the summary
// line runs a query from inside the view — the one place a compile check cannot reach.
it('renders the page with the summary line in it', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, fn () => Call::factory()->count(2)->create());

    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::actingAs($tl)
        ->test(CallExportReport::class)
        ->assertOk()
        ->assertSee('Ready to export 2 calls');
});

it('keeps the summary and the download link telling the same story', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    $agent = TenantContext::run($tenant->id, function (): User {
        $agent = User::factory()->create(['name' => 'Abhikesh']);
        Call::factory()->count(2)->forAgent($agent)->create();
        Call::factory()->create(['agent_id' => null]);

        return $agent;
    });

    TenantContext::run($tenant->id, function () use ($tenant, $agent) {
        $page = summaryPage($tenant, ['agentId' => $agent->id]);

        expect($page->exportSummary())->toContain('2 calls')
            ->and($page->downloadUrl())->toContain('agentId='.$agent->id);
    });
});

// CE-12a on screen. The extra customer columns follow the campaign filter, and a rule
// the supervisor can see beats one they discover in the file.
it('says on screen which campaign\'s customer fields will be added', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    $campaign = TenantContext::run($tenant->id, fn (): Campaign => Campaign::factory()->create([
        'name' => 'Renewals',
        'custom_fields' => [['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text']],
    ]));

    TenantContext::run($tenant->id, function () use ($tenant, $campaign) {
        expect(summaryPage($tenant, [])->customFieldsNote())
            ->toContain('Pick a single campaign')
            ->and(summaryPage($tenant, ['campaignId' => $campaign->id])->customFieldsNote())
            ->toContain('Renewals')
            ->toContain('Policy Number');
    });
});

it('says plainly when the chosen campaign has no customer fields of its own', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($tl);

    $campaign = TenantContext::run($tenant->id, fn (): Campaign => Campaign::factory()
        ->create(['name' => 'Renewals', 'custom_fields' => []]));

    TenantContext::run($tenant->id, fn () => expect(
        summaryPage($tenant, ['campaignId' => $campaign->id])->customFieldsNote()
    )->toContain('no custom customer fields'));
});
