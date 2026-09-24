<?php

use App\Enums\ClosedHours;
use App\Enums\RoleName;
use App\Enums\TenantMedia;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Jobs\ConvertTenantMediaJob;
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
    config()->set('telephony.media.disk', 'local');
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

    Queue::assertPushed(ConvertTenantMediaJob::class);
});

it('queues nothing when the save carried no new upload', function () {
    editClient(editableClient(['name' => 'Before']))
        ->fillForm(['name' => 'After'])
        ->call('save');

    Queue::assertNotPushed(ConvertTenantMediaJob::class);
});

// --- slice 4: the second sound, and the choice that cannot be saved without it ---

it('refuses "closed means a message" when the client has no message to play', function () {
    // A caller would otherwise be picked up, held in silence and hung up on — worse than
    // the busy tone they would have got.
    editClient(editableClient())
        ->fillForm(['closed_hours' => ClosedHours::Message->value])
        ->call('save')
        ->assertHasFormErrors(['closed_hours']);
});

it('allows the choice and its file in the same save', function () {
    editClient(editableClient())
        ->fillForm([
            'closed_hours' => ClosedHours::Message->value,
            'closed_message_upload' => UploadedFile::fake()->create('closed.mp3', 200, 'audio/mpeg'),
            'closed_message_rights_confirmed' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Queue::assertPushed(
        ConvertTenantMediaJob::class,
        fn (ConvertTenantMediaJob $job): bool => $job->kind === TenantMedia::ClosedMessage,
    );
});

it('allows the choice when the client already has a message from an earlier save', function () {
    $tenant = editableClient([
        'closed_message_path' => 'closed-message/1/'.str_repeat('a', 64).'.wav',
    ]);

    editClient($tenant)
        ->fillForm(['closed_hours' => ClosedHours::Message->value])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('keeps "no pick-up" saveable with no message at all', function () {
    editClient(editableClient())
        ->fillForm(['closed_hours' => ClosedHours::NoPickup->value])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('demands the rights tick for a closed message too, in its own words (AU-14)', function () {
    editClient(editableClient())
        ->fillForm([
            'closed_message_upload' => UploadedFile::fake()->create('closed.mp3', 200, 'audio/mpeg'),
            'closed_message_rights_confirmed' => false,
        ])
        ->call('save')
        ->assertHasFormErrors(['closed_message_rights_confirmed']);
});

it('refuses a closed message that is neither MP3 nor WAV', function () {
    editClient(editableClient())
        ->fillForm([
            'closed_message_upload' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            'closed_message_rights_confirmed' => true,
        ])
        ->call('save')
        ->assertHasFormErrors(['closed_message_upload']);
});

it('records who ticked the closed message\'s rights box, and when (AU-14)', function () {
    // 🔴 activitylog v5 keeps the before→after diff in `attribute_changes`, NOT in
    // `properties` — an assertion on the old place passes against nothing (S166).
    $tenant = editableClient();

    editClient($tenant)
        ->fillForm([
            'closed_message_upload' => UploadedFile::fake()->create('closed.mp3', 200, 'audio/mpeg'),
            'closed_message_rights_confirmed' => true,
        ])
        ->call('save');

    // Ownerless, like every other client-row log entry — read it cross-client.
    $entry = TenantContext::cross(fn () => ActivityLog::query()
        ->where('log_name', 'tenant')
        ->where('subject_id', $tenant->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first());
    $changes = $entry?->attribute_changes ?? [];

    expect($entry?->causer_id)->toBe($this->admin->id)
        ->and($changes['attributes']['closed_message_rights_confirmed'] ?? null)->toBeTrue()
        ->and($changes['old']['closed_message_rights_confirmed'] ?? null)->toBeFalse();
});

// --- slice 8: the message pad's switch, which cannot be saved on without a greeting ---

it('refuses to switch voicemail on when the client has no greeting (AU-34)', function () {
    editClient(editableClient())
        ->fillForm(['voicemail_enabled' => true])
        ->call('save')
        ->assertHasFormErrors(['voicemail_enabled']);
});

it('allows the voicemail switch and its greeting in the same save', function () {
    $tenant = editableClient();

    editClient($tenant)
        ->fillForm([
            'voicemail_enabled' => true,
            'voicemail_greeting_upload' => UploadedFile::fake()->create('greeting.mp3', 200, 'audio/mpeg'),
            'voicemail_greeting_rights_confirmed' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Queue::assertPushed(
        ConvertTenantMediaJob::class,
        fn (ConvertTenantMediaJob $job): bool => $job->kind === TenantMedia::VoicemailGreeting,
    );
    expect($tenant->fresh()->voicemail_enabled)->toBeTrue();
});

it('allows the voicemail switch when the client already has a greeting', function () {
    $tenant = editableClient([
        'voicemail_greeting_path' => 'voicemail-greeting/1/'.str_repeat('a', 64).'.wav',
    ]);

    editClient($tenant)
        ->fillForm(['voicemail_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->fresh()->voicemail_enabled)->toBeTrue();
});

it('records who switched voicemail on, and when', function () {
    $tenant = editableClient([
        'voicemail_greeting_path' => 'voicemail-greeting/1/'.str_repeat('a', 64).'.wav',
    ]);

    editClient($tenant)
        ->fillForm(['voicemail_enabled' => true])
        ->call('save');

    $entry = TenantContext::cross(fn () => ActivityLog::query()
        ->where('log_name', 'tenant')
        ->where('subject_id', $tenant->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first());
    $changes = $entry?->attribute_changes ?? [];

    expect($entry?->causer_id)->toBe($this->admin->id)
        ->and($changes['attributes']['voicemail_enabled'] ?? null)->toBeTrue()
        ->and($changes['old']['voicemail_enabled'] ?? null)->toBeFalse();
});

it('offers no greeting to the call flow until the switch is on and the file has converted', function () {
    $greeting = 'voicemail-greeting/1/'.str_repeat('a', 64).'.wav';

    expect(editableClient(['voicemail_greeting_path' => $greeting])->voicemailGreetingUrl())->toBeNull()
        ->and(editableClient(['voicemail_enabled' => true])->voicemailGreetingUrl())->toBeNull()
        ->and(editableClient(['voicemail_enabled' => true, 'voicemail_greeting_path' => $greeting])->voicemailGreetingUrl())
        ->toContain('/voicemail-greeting/');
});
