<?php

use App\Enums\CallDirection;
use App\Enums\RoleName;
use App\Filament\Resources\Calls\Pages\ListCalls;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Department;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * CH-3 (PRD/phase-2/customer-history-panel.md) — the supervisor's half of customer
 * profiling: one customer's whole history, on the Call Review screen that already has the
 * filters, the client's-clock date cut, the View page and the gated recording player.
 *
 * It reads the SAME Call::forCustomerNumber() scope the agent's ring-time panel reads, so
 * these also pin the one thing a shared scope cannot protect itself from: the filter and
 * the Leads-list link agreeing on the filter's name and shape. A typo in either lands a
 * supervisor on an unfiltered list with no sign anything went wrong.
 */
it('narrows the call list to one customer number, however it was typed', function () {
    $admin = callReviewHcUser(RoleName::HcAdmin->value);

    [$theirs, $someoneElse] = TenantContext::run(
        Tenant::factory()->create()->id,
        fn (): array => [
            Call::factory()->create([
                'direction' => CallDirection::Outbound,
                'to_number' => '9991234567',
            ]),
            Call::factory()->create([
                'direction' => CallDirection::Outbound,
                'to_number' => '9997654321',
            ]),
        ],
    );

    $this->actingAs($admin);
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(ListCalls::class)
        ->filterTable('number', ['value' => '(999) 123-4567'])   // the scope normalizes
        ->assertCanSeeTableRecords([$theirs])
        ->assertCanNotSeeTableRecords([$someoneElse]);
});

// The Leads list's History action hands the number over in the query string, and nothing
// but a test connects the two spellings: rename the filter or its field on either side and
// a supervisor lands on an unfiltered list with no sign anything went wrong. So the filter
// name is READ BACK out of the URL rather than typed here a second time.
it('filters from the query string the Leads History action builds', function () {
    $admin = callReviewHcUser(RoleName::HcAdmin->value);

    [$lead, $theirs, $someoneElse] = TenantContext::run(
        Tenant::factory()->create()->id,
        fn (): array => [
            Lead::factory()->forCampaign(Campaign::factory()->create())->create(['phone' => '9991234567']),
            Call::factory()->create(['direction' => CallDirection::Outbound, 'to_number' => '9991234567']),
            Call::factory()->create(['direction' => CallDirection::Outbound, 'to_number' => '9997654321']),
        ],
    );

    $this->actingAs($admin);
    TenantContext::applyWebRequest(null, crossTenant: true);

    $url = ListCalls::getUrl(['tableFilters' => ['number' => ['value' => $lead->phone]]]);

    Livewire::test(ListLeads::class)->assertTableActionHasUrl('callHistory', $url, record: $lead);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $filter = array_key_first($query['tableFilters']);

    Livewire::test(ListCalls::class)
        ->filterTable($filter, $query['tableFilters'][$filter])
        ->assertCanSeeTableRecords([$theirs])
        ->assertCanNotSeeTableRecords([$someoneElse]);
});

// inbound-audio slice 7 (D6): "how many Hindi callers" is a filter on a link, not a label.
it('narrows the call list to one department, and shows it with widened under it', function () {
    $admin = callReviewHcUser(RoleName::HcAdmin->value);

    [$widened, $notWidened, $noDepartment, $hindi] = TenantContext::run(
        Tenant::factory()->create()->id,
        function (): array {
            $hindi = Department::factory()->create(['name' => 'Hindi']);

            return [
                Call::factory()->create(['department_id' => $hindi->id, 'department_widened' => true]),
                Call::factory()->create(['department_id' => $hindi->id, 'department_widened' => false]),
                Call::factory()->create(),
                $hindi,
            ];
        },
    );

    $this->actingAs($admin);
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(ListCalls::class)
        ->toggleAllTableColumns()
        ->filterTable('department_id', $hindi->id)
        ->assertCanSeeTableRecords([$widened, $notWidened])
        ->assertCanNotSeeTableRecords([$noDepartment])
        ->assertTableColumnStateSet('department.name', 'Hindi', $widened)
        ->assertSeeText('Widened');
});
