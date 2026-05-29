<?php

use App\Enums\RoleName;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Mail\UserInviteMailable;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Actions\SendUserInvite;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

it('dispatches an invite mail when SendUserInvite is called', function () {
    Mail::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    SendUserInvite::run($user);

    Mail::assertSent(UserInviteMailable::class, function (UserInviteMailable $mail) use ($user) {
        return $mail->hasTo($user->email)
            && $mail->invitee->is($user)
            && str_starts_with($mail->acceptUrl, url('/invite/'));
    });
});

it('shows the accept-invite form when the signed URL is valid', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.test',
        'email_verified_at' => null,
    ]);

    $url = SendUserInvite::signedAcceptUrl($user);

    $this->get($url)
        ->assertOk()
        ->assertSee('Set your password')
        ->assertSee($user->name);
});

it('rejects a tampered email parameter', function () {
    $user = User::factory()->create([
        'email' => 'real@example.test',
        'email_verified_at' => null,
    ]);

    $validUrl = SendUserInvite::signedAcceptUrl($user);
    // Swap the email out — signature still matches (no, it won't), but the
    // controller does its own check anyway. Build an attacker URL by
    // re-signing with a different email.
    $tampered = URL::temporarySignedRoute('invite.accept', now()->addHours(72), [
        'user' => $user->getKey(),
        'email' => 'attacker@example.test',
    ]);

    $this->get($tampered)
        ->assertForbidden();
});

it('sets the password, marks verified, and logs the user in on successful submit', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.test',
        'email_verified_at' => null,
        'password' => Hash::make('unusable'),
    ]);

    $url = SendUserInvite::signedAcceptUrl($user);

    $response = $this->post($url, [
        'password' => 'a-real-password-1',
        'password_confirmation' => 'a-real-password-1',
    ]);

    $response->assertRedirect('/admin');

    $fresh = $user->fresh();
    expect($fresh->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-real-password-1', $fresh->password))->toBeTrue()
        ->and(Auth::id())->toBe($fresh->getKey());
});

it('rejects mismatched password confirmation', function () {
    $user = User::factory()->create(['email_verified_at' => null]);

    $url = SendUserInvite::signedAcceptUrl($user);

    $this->post($url, [
        'password' => 'one',
        'password_confirmation' => 'two',
    ])->assertSessionHasErrors('password');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('redirects an already-verified user to login instead of showing the form', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $url = SendUserInvite::signedAcceptUrl($user);

    $this->get($url)
        ->assertRedirect(route('filament.admin.auth.login'));
});

it('rejects an expired signed URL', function () {
    $user = User::factory()->create(['email_verified_at' => null]);

    // Generate a URL that expired in the past.
    $expired = URL::temporarySignedRoute('invite.accept', now()->subMinute(), [
        'user' => $user->getKey(),
        'email' => $user->email,
    ]);

    $this->get($expired)->assertForbidden();
});

it('fires an invite when the global Users Create action runs', function () {
    Mail::fake();
    TenantContext::forget();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::SuperAdmin->value, 'web');
    $admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Brand New',
            'email' => 'brand-new@example.test',
            'global_role' => RoleName::SuperAdmin->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Mail::assertSent(UserInviteMailable::class, fn (UserInviteMailable $m) => $m->hasTo('brand-new@example.test'));
});

it('fires an invite from the per-tenant Add new user action', function () {
    Mail::fake();
    TenantContext::forget();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::SuperAdmin->value, 'web');
    $admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($admin);

    $tenant = Tenant::factory()->create();

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('add_new', data: [
            'name' => 'Tenant Person',
            'email' => 'tenant-person@example.test',
            'role_name' => RoleName::Agent->value,
        ])
        ->assertHasNoTableActionErrors();

    Mail::assertSent(UserInviteMailable::class, function (UserInviteMailable $m) use ($tenant) {
        return $m->hasTo('tenant-person@example.test')
            && $m->assignedTenant?->is($tenant);
    });
});

it('resends an invite via the Users list action', function () {
    Mail::fake();
    TenantContext::forget();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::SuperAdmin->value, 'web');
    $admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($admin);

    $unverified = User::factory()->create([
        'email' => 'unverified@example.test',
        'email_verified_at' => null,
    ]);

    Livewire::test(ListUsers::class)
        ->callTableAction('resend_invite', $unverified)
        ->assertHasNoTableActionErrors();

    Mail::assertSent(UserInviteMailable::class, fn (UserInviteMailable $m) => $m->hasTo('unverified@example.test'));
});

it('trySend returns true when the invite mail goes out', function () {
    Mail::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    expect(SendUserInvite::trySend($user))->toBeTrue();
    Mail::assertSent(UserInviteMailable::class);
});

it('trySend swallows a mailer failure and returns false instead of throwing', function () {
    $user = User::factory()->create(['email_verified_at' => null]);

    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp unreachable'));

    expect(SendUserInvite::trySend($user))->toBeFalse();
});

it('still creates the user when the invite mail fails (create is not rolled back)', function () {
    TenantContext::forget();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::SuperAdmin->value, 'web');
    $admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($admin);

    // The send happens inside Filament's create transaction; a throw here would
    // previously roll the new user back. trySend must keep the create intact.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Resilient',
            'email' => 'resilient@example.test',
            'global_role' => RoleName::SuperAdmin->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'resilient@example.test')->exists())->toBeTrue();
});
