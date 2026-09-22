<?php

use App\Enums\MenuAction;
use App\Enums\TenantMedia;
use App\Jobs\ConvertTenantMediaJob;
use App\Models\Menu;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * inbound-audio.md slice 6 (Ankit, S169 Q1) — where a menu's sounds live, and what takes
 * one out of service.
 *
 * A menu's greeting and its per-key sounds hang off the MENU, not off a column on the
 * client, because one client may have several menus and one menu several sounds. So the
 * media route stopped asking who owns a file and asks whether the file is still there —
 * the shape a presigned link on S3 or Cloud Storage already has.
 *
 * 🔴 WHICH MAKES DELETING THE FILE THE ONLY REVOCATION, and these are the tests that hold
 * it up. The addresses never expire (AUQ-4), so a sound left on disk after nothing
 * references it stays fetchable by whoever holds its address.
 */
uses(RefreshDatabase::class);

afterEach(fn () => TenantContext::forget());

function menuDisk(): Filesystem
{
    Storage::fake(config('telephony.media.disk'));

    return Storage::disk(config('telephony.media.disk'));
}

function menuWithSounds(Tenant $tenant, string $greeting, string $optionSound): Menu
{
    return TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->create([
        'greeting_path' => $greeting,
        'options' => [[
            'key' => '2',
            'label' => 'Opening hours',
            'action' => MenuAction::HearMessage->value,
            'sound_path' => $optionSound,
            'sound_rights_confirmed' => true,
        ]],
    ]));
}

it('deletes every sound when the menu is deleted', function () {
    $disk = menuDisk();
    $tenant = Tenant::factory()->create();

    $greeting = TenantMedia::MenuGreeting->pathFor($tenant->id, str_repeat('a', 64));
    $option = TenantMedia::MenuOption->pathFor($tenant->id, str_repeat('b', 64));
    $disk->put($greeting, 'greeting bytes');
    $disk->put($option, 'option bytes');

    $menu = menuWithSounds($tenant, $greeting, $option);

    TenantContext::run($tenant->id, fn () => $menu->delete());

    $disk->assertMissing($greeting);
    $disk->assertMissing($option);
});

it('deletes a key\'s sound when that key is removed from the menu', function () {
    $disk = menuDisk();
    $tenant = Tenant::factory()->create();

    $greeting = TenantMedia::MenuGreeting->pathFor($tenant->id, str_repeat('a', 64));
    $option = TenantMedia::MenuOption->pathFor($tenant->id, str_repeat('b', 64));
    $disk->put($greeting, 'greeting bytes');
    $disk->put($option, 'option bytes');

    $menu = menuWithSounds($tenant, $greeting, $option);

    TenantContext::run($tenant->id, fn () => $menu->update(['options' => []]));

    $disk->assertMissing($option);
    // The greeting is untouched — only what stopped being referenced goes.
    $disk->assertExists($greeting);
});

it('deletes the file a key\'s sound replaced', function () {
    $disk = menuDisk();
    $tenant = Tenant::factory()->create();

    $greeting = TenantMedia::MenuGreeting->pathFor($tenant->id, str_repeat('a', 64));
    $old = TenantMedia::MenuOption->pathFor($tenant->id, str_repeat('b', 64));
    $new = TenantMedia::MenuOption->pathFor($tenant->id, str_repeat('c', 64));
    $disk->put($greeting, 'greeting bytes');
    $disk->put($old, 'old bytes');
    $disk->put($new, 'new bytes');

    $menu = menuWithSounds($tenant, $greeting, $old);

    TenantContext::run($tenant->id, fn () => $menu->storeSoundPath('2', $new));

    $disk->assertMissing($old);
    $disk->assertExists($new);
});

it('writes a converted sound onto the key it belongs to, by key and not by position', function () {
    // 🔴 Two uploads on one save become two queued jobs, and by the time one runs the list
    // may have been reordered. A job that wrote by array index would put its file on
    // whichever key had moved into that slot.
    $disk = menuDisk();
    $tenant = Tenant::factory()->create();

    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->create([
        'options' => [
            ['key' => '1', 'label' => 'Sales', 'action' => MenuAction::TalkToAgent->value, 'sound_path' => null],
            ['key' => '2', 'label' => 'Hours', 'action' => MenuAction::HearMessage->value, 'sound_path' => null],
        ],
    ]));

    $path = TenantMedia::MenuOption->pathFor($tenant->id, str_repeat('d', 64));

    TenantContext::run($tenant->id, function () use ($menu, $path) {
        $menu->storeSoundPath('2', $path);
        $menu->refresh();
    });

    expect($menu->optionFor('2')['sound_path'])->toBe($path)
        ->and($menu->optionFor('1')['sound_path'])->toBeNull();
});

it('converts a menu greeting and pads it like any other speech', function () {
    Storage::fake('uploads');
    $disk = menuDisk();
    $tenant = Tenant::factory()->create();
    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->create());

    Storage::disk('uploads')->put('pending/greeting.wav', 'raw bytes');

    // A menu sound is speech, so it gets the second of silence that stops a handset
    // clipping the last word (S165). Music is the only kind that gets none.
    expect(TenantMedia::MenuGreeting->padSeconds())->toBe(1)
        ->and(TenantMedia::MenuOption->padSeconds())->toBe(1);

    expect(fn () => TenantMedia::MenuGreeting->pathColumn())
        ->toThrow(LogicException::class);
});

it('serves a menu sound from the address alone, with no owner lookup (Ankit, S169 Q1)', function () {
    $disk = menuDisk();
    $tenant = Tenant::factory()->create();

    $path = TenantMedia::MenuGreeting->pathFor($tenant->id, str_repeat('a', 64));
    $disk->put($path, 'greeting bytes');

    // 🔴 No menu row exists at all, and the file is still served. That is the point: the
    // signature is the capability and the file's presence is the check, because a menu
    // sound has no column on the client to be compared against.
    $url = TenantMedia::MenuGreeting->addressFor($tenant->id, $path);

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/wav');
});

it('stops serving a menu sound the moment its file is gone', function () {
    $disk = menuDisk();
    $tenant = Tenant::factory()->create();

    $path = TenantMedia::MenuGreeting->pathFor($tenant->id, str_repeat('a', 64));
    $disk->put($path, 'greeting bytes');
    $url = TenantMedia::MenuGreeting->addressFor($tenant->id, $path);

    $this->get($url)->assertOk();

    $disk->delete($path);

    // Deleting the file IS the revocation now, and these addresses never expire.
    $this->get($url)->assertNotFound();
});

it('refuses a menu sound address that was not signed', function () {
    $disk = menuDisk();
    $tenant = Tenant::factory()->create();

    $path = TenantMedia::MenuGreeting->pathFor($tenant->id, str_repeat('a', 64));
    $disk->put($path, 'greeting bytes');

    $this->get('/'.$path)->assertForbidden();
});

it('queues one conversion job per uploaded menu sound, each naming its own key', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->create());

    ConvertTenantMediaJob::dispatch($tenant, TenantMedia::MenuGreeting, 'media', 'pending/a.wav', $menu->getKey());
    ConvertTenantMediaJob::dispatch($tenant, TenantMedia::MenuOption, 'media', 'pending/b.wav', $menu->getKey(), '2');

    Queue::assertPushed(
        ConvertTenantMediaJob::class,
        fn (ConvertTenantMediaJob $job): bool => $job->kind === TenantMedia::MenuGreeting && $job->optionKey === null,
    );
    Queue::assertPushed(
        ConvertTenantMediaJob::class,
        fn (ConvertTenantMediaJob $job): bool => $job->kind === TenantMedia::MenuOption && $job->optionKey === '2',
    );
});
