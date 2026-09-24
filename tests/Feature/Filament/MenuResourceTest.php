<?php

use App\Enums\MenuAction;
use App\Enums\RoleName;
use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Filament\Resources\PhoneNumbers\Pages\EditPhoneNumber;
use App\Models\Department;
use App\Models\Menu;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * inbound-audio.md slice 6 — the menu-building screen (AU-19) and the one field on the
 * phone-number screen that is not the team leader's.
 *
 * A wrong campaign mislabels a report; a wrong menu traps every caller on that number.
 * That is the whole reason these two screens draw their line in different places, and it
 * is what these tests pin.
 *
 * Mounting the Edit page is deliberate: M3 shipped a typed cast with no Livewire support
 * and the first Edit mount crashed in the browser, not in a test. A repeater over a JSON
 * column is the same shape of risk.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * Head office, in the only posture they are ever in — no client selected, cross-client on.
 *
 * 🔴 NOT PINNED TO A CLIENT, and that is the middleware's own rule: SetCurrentTenant
 * gives a global user `applyWebRequest(null, crossTenant: true)` and forgets any client
 * in the session, precisely so their global-team roles stay visible. Pinning them in a
 * test would prove something the app never does — and would fail, because a role check
 * resolves to whichever client is on screen.
 */
function menuHeadOffice(): Tenant
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::HcAdmin->value, 'web');
    $user->assignRole(RoleName::HcAdmin->value);

    test()->actingAs($user->fresh());
    TenantContext::applyWebRequest(null, true);

    return $tenant;
}

it('lets head office build a menu with keys, stamped to the client (AU-19)', function () {
    $tenant = menuHeadOffice();

    Livewire::test(CreateMenu::class)
        ->fillForm([
            'tenant_id' => $tenant->id,
            'name' => 'Main menu',
            'greeting_upload' => null,
            'options' => [
                ['key' => '1', 'label' => 'Sales', 'action' => MenuAction::TalkToAgent->value],
            ],
        ])
        // A menu with no greeting plays silence at the caller and then puts them through,
        // which reads as a dropped call. Refused here rather than handled quietly.
        ->call('create')
        ->assertHasFormErrors(['greeting_upload']);

    expect(Menu::query()->count())->toBe(0);
});

it('refuses two keys with the same character (AU-27)', function () {
    $tenant = menuHeadOffice();
    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->withGreeting()->create());

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->fillForm([
            'tenant_id' => $tenant->id,
            'name' => 'Main menu',
            'options' => [
                ['key' => '1', 'label' => 'Sales', 'action' => MenuAction::TalkToAgent->value],
                ['key' => '1', 'label' => 'Support', 'action' => MenuAction::TalkToAgent->value],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors();
});

it('saves keys onto the menu, and the edit page mounts with them', function () {
    $tenant = menuHeadOffice();
    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->withGreeting()->create());

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->fillForm([
            'tenant_id' => $tenant->id,
            'name' => 'Main menu',
            'options' => [
                ['key' => '1', 'label' => 'Sales', 'action' => MenuAction::TalkToAgent->value],
                ['key' => '9', 'label' => 'Take me off your list', 'action' => MenuAction::RemoveFromList->value],
                // Slice 8 (review S175, handoff question 4): no upload, no tick box.
                ['key' => '3', 'label' => 'Leave a message', 'action' => MenuAction::LeaveMessage->value],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $menu->refresh();

    expect($menu->name)->toBe('Main menu')
        ->and($menu->optionFor('1')['label'])->toBe('Sales')
        ->and($menu->optionFor('9')['action'])->toBe(MenuAction::RemoveFromList->value)
        ->and($menu->optionFor('3'))->toMatchArray(['action' => 'voicemail', 'sound_path' => null])
        ->and($menu->optionFor('5'))->toBeNull();

    // The mount is the point: a repeater over a JSON column that cannot round-trip
    // crashes here, in the browser, and nowhere else.
    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])->assertOk();
});

it('keeps a team leader out of the menus screen entirely (AU-19)', function () {
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    // Deliberately stricter than the phone-number screen beside it, which IS theirs.
    expect(MenuResource::canViewAny())->toBeFalse()
        ->and(MenuResource::canCreate())->toBeFalse();
});

it('lets head office point a number at a menu (AU-18)', function () {
    $tenant = menuHeadOffice();
    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->withGreeting()->create());
    $number = TenantContext::run($tenant->id, fn (): PhoneNumber => PhoneNumber::factory()->create(['number' => '+919876543210']));

    Livewire::test(EditPhoneNumber::class, ['record' => $number->getKey()])
        ->fillForm(['number' => $number->number, 'menu_id' => $menu->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($number->fresh()->menu_id)->toBe($menu->id);
});

it('will not let a team leader change which menu answers a number (AU-19)', function () {
    $tenant = Tenant::factory()->create();
    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->withGreeting()->create());
    $number = TenantContext::run($tenant->id, fn (): PhoneNumber => PhoneNumber::factory()->create(['number' => '+919876543211', 'menu_id' => null]));

    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $this->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    // What matters is the SAVED ROW, not how the box looks: filling the field and saving
    // must leave the number's menu untouched.
    Livewire::test(EditPhoneNumber::class, ['record' => $number->getKey()])
        ->fillForm(['number' => $number->number, 'menu_id' => $menu->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($number->fresh()->menu_id)->toBeNull();
});

it('opens the menus list page for head office, bulk delete and all (AU-19)', function () {
    $tenant = menuHeadOffice();
    TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->withGreeting()->create());

    // The list page renders a tick-the-boxes-and-delete button, which asks the policy
    // `deleteAny`. The panel runs strictAuthorization(), so a MISSING method is a 500 on
    // the whole page rather than a hidden button — and only mounting the page finds it.
    // Found on staging in S170; every other bulk-delete table gets it from a shared trait.
    Livewire::test(ListMenus::class)->assertOk();
});

// --- slice 7: the "ring a department" key ---

it('saves a department key pointing at the client\'s own switched-on department', function () {
    $tenant = menuHeadOffice();
    [$menu, $hindi] = TenantContext::run($tenant->id, fn (): array => [
        Menu::factory()->withGreeting()->create(),
        Department::factory()->create(['name' => 'Hindi']),
    ]);

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->fillForm([
            'name' => 'Main menu',
            'options' => [
                ['key' => '2', 'label' => 'Hindi', 'action' => MenuAction::RingDepartment->value, 'department_id' => (string) $hindi->id],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // Stored as a number: the departments screen finds the menus using a department by
    // matching this value inside the JSON, and "3" is not 3 there.
    expect($menu->fresh()->optionFor('2')['department_id'])->toBe($hindi->id)
        ->and(TenantContext::run($tenant->id, fn () => $hindi->menusUsingIt()->pluck('id')->all()))->toBe([$menu->id]);

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])->assertOk();
});

it('refuses a department key pointing at a switched-off department or another client\'s', function (string $which) {
    $tenant = menuHeadOffice();
    $other = Tenant::factory()->create();
    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->withGreeting()->create());
    $department = $which === 'switched off'
        ? TenantContext::run($tenant->id, fn (): Department => Department::factory()->inactive()->create())
        : TenantContext::run($other->id, fn (): Department => Department::factory()->create());

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->fillForm([
            'name' => 'Main menu',
            'options' => [
                ['key' => '2', 'label' => 'Hindi', 'action' => MenuAction::RingDepartment->value, 'department_id' => $department->id],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors();

    expect($menu->fresh()->options)->toBe([]);
})->with(['switched off', 'another client\'s']);
