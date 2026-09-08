<?php

use App\Enums\RoleName;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\ActivityLog;
use App\Models\User;
use App\Telephony\AgentPhoneWriter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantContext::forget();
    createAsteriskPhoneTables();

    foreach (RoleName::globals() as $role) {
        Role::findOrCreate($role->value, 'web');
    }

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($this->admin);

    $this->writer = app(AgentPhoneWriter::class);
    $this->agent = User::factory()->create(['email_verified_at' => now()]);
});

afterEach(function () {
    TenantContext::forget();
});

/** The Edit page for a given user. */
function editPageFor(User $user): Testable
{
    return Livewire::test(EditUser::class, ['record' => $user->getRouteKey()]);
}

/** The audit entries recorded against a user, newest first. */
function auditEventsFor(User $user): array
{
    return TenantContext::cross(fn (): array => ActivityLog::query()
        ->where('subject_id', $user->getKey())
        ->latest('id')
        ->pluck('event')
        ->all());
}

// --- PP-19: retiring destroys the key and keeps everything else ---

it('destroys the key, keeps the number, and records that it happened', function () {
    $extension = $this->writer->provisionFor($this->agent);

    editPageFor($this->agent)->callAction('retire_phone');

    expect($this->agent->fresh()->sip_extension)->toBe($extension)
        ->and(DB::table('asterisk.ps_auths')->where('id', 'auth'.$extension)->exists())->toBeFalse()
        ->and(DB::table('asterisk.ps_endpoints')->where('id', $extension)->exists())->toBeTrue()
        ->and(auditEventsFor($this->agent))->toContain('phone_retired');
});

it('offers neither button to someone who has no phone', function () {
    editPageFor($this->agent)
        ->assertActionHidden('retire_phone')
        ->assertActionHidden('reissue_phone');
});

it('offers retire while the phone works and re-issue only once it is retired', function () {
    $this->writer->provisionFor($this->agent);

    editPageFor($this->agent)
        ->assertActionVisible('retire_phone')
        ->assertActionHidden('reissue_phone');

    $this->writer->retireFor($this->agent->fresh());

    editPageFor($this->agent)
        ->assertActionHidden('retire_phone')
        ->assertActionVisible('reissue_phone');
});

// --- PP-19 reversibility: a mistake costs one click ---

it('gives a fresh key on the same number when re-issued, and records that too', function () {
    $extension = $this->writer->provisionFor($this->agent);
    $oldKey = DB::table('asterisk.ps_auths')->where('id', 'auth'.$extension)->value('password');

    $this->writer->retireFor($this->agent->fresh());
    editPageFor($this->agent)->callAction('reissue_phone');

    $newKey = DB::table('asterisk.ps_auths')->where('id', 'auth'.$extension)->value('password');

    expect($this->agent->fresh()->sip_extension)->toBe($extension)
        ->and($newKey)->not->toBeEmpty()
        ->and($newKey)->not->toBe($oldKey)
        ->and(auditEventsFor($this->agent))->toContain('phone_reissued');
});

// --- PP-20: the warning says three things, and links rather than rebuilds ---

/**
 * Asserted on the action's own description rather than the rendered page: Filament
 * builds a modal's body in the browser, so nothing of it reaches the server-rendered
 * HTML and assertSee() can never find it. This is the same object the browser draws.
 */
it('warns what survives, what the number does, and where to get the calls first', function () {
    $extension = $this->writer->provisionFor($this->agent);

    $warning = (string) editPageFor($this->agent)
        ->instance()
        ->getAction('retire_phone')
        ->getModalDescription();

    expect($warning)
        ->toContain('Every call they made or took stays exactly as it is')
        ->toContain("number {$extension} stays retired to them")
        ->toContain(route('filament.admin.pages.call-export-report'))
        ->toContain('Reports → Call export');
});
