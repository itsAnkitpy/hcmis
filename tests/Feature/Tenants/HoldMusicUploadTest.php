<?php

use App\Enums\RoleName;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Jobs\ConvertHoldMusicJob;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * inbound-audio slice 3, step 1 (AU-9, AU-11, AU-14) — the upload field on the client
 * edit page: what it accepts, what it refuses, and the rights tick box it will not
 * save without.
 */
beforeEach(function () {
    TenantContext::forget();
    config()->set('telephony.hold_music.disk', 'local');
    Storage::fake('local');
    Queue::fake();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::SuperAdmin->value, 'web');
    $admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($admin);
    $this->admin = $admin;
});

afterEach(fn () => TenantContext::forget());

/**
 * A client the Edit form will actually save. The Dispositions repeater already on that
 * page demands at least one row, so a bare factory client fails validation for a reason
 * that has nothing to do with hold music.
 *
 * @param  array<string, mixed>  $attributes
 */
function editableClient(array $attributes = []): Tenant
{
    return Tenant::factory()->create(array_merge([
        'settings' => [
            'dispositions' => [
                ['code' => 'SALE', 'label' => 'Sale', 'is_contact' => true, 'is_sale' => true],
            ],
        ],
    ], $attributes));
}

function editClient(Tenant $tenant): Testable
{
    return Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()]);
}

// --- AU-11: MP3 or WAV, up to 10 MB, a plain error for anything else ---

it('refuses a file that is neither MP3 nor WAV', function () {
    editClient(editableClient())
        ->fillForm([
            'hold_music_upload' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            'hold_music_rights_confirmed' => true,
        ])
        ->call('save')
        ->assertHasFormErrors(['hold_music_upload']);
});

it('refuses a file over 10 MB', function () {
    editClient(editableClient())
        ->fillForm([
            'hold_music_upload' => UploadedFile::fake()->create('epic.mp3', 11000, 'audio/mpeg'),
            'hold_music_rights_confirmed' => true,
        ])
        ->call('save')
        ->assertHasFormErrors(['hold_music_upload']);
});

it('accepts an MP3 inside the size limit', function () {
    editClient(editableClient())
        ->fillForm([
            'hold_music_upload' => UploadedFile::fake()->create('tune.mp3', 200, 'audio/mpeg'),
            'hold_music_rights_confirmed' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('accepts a WAV inside the size limit', function () {
    editClient(editableClient())
        ->fillForm([
            'hold_music_upload' => UploadedFile::fake()->create('tune.wav', 200, 'audio/wav'),
            'hold_music_rights_confirmed' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});

// --- AU-14: the rights tick box ---

it('will not save an upload without the rights tick box', function () {
    editClient(editableClient())
        ->fillForm([
            'hold_music_upload' => UploadedFile::fake()->create('tune.mp3', 200, 'audio/mpeg'),
            'hold_music_rights_confirmed' => false,
        ])
        ->call('save')
        ->assertHasFormErrors(['hold_music_rights_confirmed']);
});

it('does not demand the tick box when no music is being uploaded', function () {
    // Otherwise editing anything else on this page — the ring time, a holiday — would
    // ask again for a confirmation that was already given.
    editClient(editableClient(['name' => 'Before']))
        ->fillForm(['name' => 'After'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('records who ticked the rights box and when (AU-14)', function () {
    $tenant = editableClient(['hold_music_rights_confirmed' => false]);

    editClient($tenant)
        ->fillForm([
            'hold_music_upload' => UploadedFile::fake()->create('tune.mp3', 200, 'audio/mpeg'),
            'hold_music_rights_confirmed' => true,
        ])
        ->call('save');

    // The client row is not client-owned, so its log entries are ownerless — read them
    // the way every other cross-client read is done.
    $entry = TenantContext::cross(fn () => ActivityLog::query()
        ->where('log_name', 'tenant')
        ->where('subject_id', $tenant->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first());

    // activitylog v5 keeps the before-to-after diff in its own column, not in properties.
    $changes = $entry?->attribute_changes?->toArray() ?? [];

    expect($tenant->fresh()->hold_music_rights_confirmed)->toBeTrue()
        ->and($entry?->causer_id)->toBe($this->admin->id)
        ->and($changes['attributes']['hold_music_rights_confirmed'] ?? null)->toBeTrue()
        ->and($changes['old']['hold_music_rights_confirmed'] ?? null)->toBeFalse()
        ->and($entry?->created_at)->not->toBeNull();
});

// --- step 2: the conversion is queued, never run in the request ---

it('queues the conversion rather than converting while the page waits', function () {
    editClient(editableClient())
        ->fillForm([
            'hold_music_upload' => UploadedFile::fake()->create('tune.mp3', 200, 'audio/mpeg'),
            'hold_music_rights_confirmed' => true,
        ])
        ->call('save');

    Queue::assertPushed(ConvertHoldMusicJob::class);
});

it('queues nothing when the save carried no new upload', function () {
    editClient(editableClient(['name' => 'Before']))
        ->fillForm(['name' => 'After'])
        ->call('save');

    Queue::assertNotPushed(ConvertHoldMusicJob::class);
});
