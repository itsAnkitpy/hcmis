<?php

use App\Enums\RoleName;
use App\Filament\Resources\PhoneNumbers\Pages\CreatePhoneNumber;
use App\Filament\Resources\PhoneNumbers\Pages\EditPhoneNumber;
use App\Models\Campaign;
use App\Models\Menu;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * The number -> client screen (B2.3a ND-5). It is the BPO team lead's screen, not
 * an admin-only one — they already add numbers and assign them to campaigns in
 * their current system — so the role gate and the campaign guard are the two things
 * worth pinning.
 */
function phoneNumberTeamLeader(): Tenant
{
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    return $tenant;
}

// --- create through the screen ---

it('lets a team leader add a number and stamps it to their client', function () {
    $tenant = phoneNumberTeamLeader();

    Livewire::test(CreatePhoneNumber::class)
        ->fillForm(['number' => '+919876543210', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $number = PhoneNumber::query()->first();

    expect($number)->not->toBeNull()
        ->and($number->number)->toBe('+919876543210')
        ->and($number->tenant_id)->toBe($tenant->id)
        ->and($number->is_active)->toBeTrue();
});

it('assigns a campaign as the normal path on the form (ND-5)', function () {
    $tenant = phoneNumberTeamLeader();
    $campaign = Campaign::factory()->create(['tenant_id' => $tenant->id]);

    Livewire::test(CreatePhoneNumber::class)
        ->fillForm(['number' => '+919876543210', 'campaign_id' => $campaign->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PhoneNumber::query()->first()->campaign_id)->toBe($campaign->id);
});

// --- ND-5: the consistency guard ---

it('refuses a campaign that belongs to a different client (the ND-5 guard)', function () {
    $other = Tenant::factory()->create();
    $otherCampaign = TenantContext::run($other->id, fn () => Campaign::factory()->create());

    phoneNumberTeamLeader();

    Livewire::test(CreatePhoneNumber::class)
        ->fillForm(['number' => '+919876543210', 'campaign_id' => $otherCampaign->id])
        ->call('create')
        ->assertHasFormErrors(['campaign_id']);

    expect(PhoneNumber::query()->count())->toBe(0);
});

// --- ND-2: one number, one owner, said in a sentence rather than a crash ---

it('refuses a number another client already owns, with a message not a database error', function () {
    $other = Tenant::factory()->create();
    TenantContext::run($other->id, fn () => PhoneNumber::factory()->create(['number' => '+919876543210']));

    phoneNumberTeamLeader();

    Livewire::test(CreatePhoneNumber::class)
        ->fillForm(['number' => '+919876543210'])
        ->call('create')
        ->assertHasFormErrors(['number']);
});

it('refuses a number typed without the country code, which would never be found', function () {
    phoneNumberTeamLeader();

    Livewire::test(CreatePhoneNumber::class)
        ->fillForm(['number' => '9876543210'])
        ->call('create')
        ->assertHasFormErrors(['number']);
});

// --- the role gate (ND-5: team lead's screen, not admin-only) ---

it('lets a team leader open the numbers list and its create page', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($tl->fresh())->get('/admin/phone-numbers')->assertSuccessful();
    $this->actingAs($tl->fresh())->get('/admin/phone-numbers/create')->assertSuccessful();
});

it('keeps agents out of the numbers screen entirely', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent->fresh())->get('/admin/phone-numbers')->assertForbidden();
});

it('lets read-only QC look but not add', function () {
    $tenant = Tenant::factory()->create();
    $qc = clientUserWithRole($tenant, RoleName::Qc->value);

    $this->actingAs($qc->fresh())->get('/admin/phone-numbers')->assertSuccessful();
    $this->actingAs($qc->fresh())->get('/admin/phone-numbers/create')->assertForbidden();
});

it('lets head office save a number that already has a campaign, and still refuses a foreign one', function () {
    $tenant = Tenant::factory()->create();
    $campaign = TenantContext::run($tenant->id, fn () => Campaign::factory()->create());
    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->withGreeting()->create());
    $number = TenantContext::run($tenant->id, fn (): PhoneNumber => PhoneNumber::factory()->create([
        'number' => '+919876543210',
        'campaign_id' => $campaign->id,
    ]));
    $foreign = Tenant::factory()->create();
    $foreignCampaign = TenantContext::run($foreign->id, fn () => Campaign::factory()->create());

    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::HcAdmin->value, 'web');
    $user->assignRole(RoleName::HcAdmin->value);
    test()->actingAs($user->fresh());
    TenantContext::applyWebRequest(null, true);

    // 🔴 Head office is NEVER pinned to a client, so a guard that asks the REQUEST's
    // client refused every campaign — the number's own included — and blocked the one
    // field only head office may set. Found on staging, S170; the menu rule beside it
    // was already written this way.
    Livewire::test(EditPhoneNumber::class, ['record' => $number->getKey()])
        ->fillForm(['number' => $number->number, 'campaign_id' => $campaign->id, 'menu_id' => $menu->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($number->fresh()->menu_id)->toBe($menu->id);

    // The ND-5 guard itself is untouched: another client's campaign is still refused.
    Livewire::test(EditPhoneNumber::class, ['record' => $number->getKey()])
        ->fillForm(['number' => $number->number, 'campaign_id' => $foreignCampaign->id])
        ->call('save')
        ->assertHasFormErrors(['campaign_id']);
});
