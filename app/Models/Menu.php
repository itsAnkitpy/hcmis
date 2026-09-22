<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\MenuAction;
use App\Enums\TenantMedia;
use App\Tenancy\BelongsToTenant;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One spoken menu — the receptionist a caller meets before any desk rings
 * (inbound-audio slice 6, AU-17 … AU-28).
 *
 * BUILT ONCE, POINTED AT FROM MANY NUMBERS (AU-18). A client may run a sales menu on
 * one number and a support menu on another; a number with no menu behaves exactly as
 * it did before this slice.
 *
 * THE KEYS LIVE IN `options`, ONE FIELD (slice 6, "Alternate choices"). Nothing points
 * at a key except the menu that owns it, and `campaigns.custom_fields` is the same
 * shape already in service. Each entry:
 *
 *   key                    one character, 0-9 * or # (AU-27)
 *   label                  what the caller chose, saved on the call (AU-25)
 *   action                 a MenuAction value
 *   sound_path             the converted file, or null
 *   sound_rights_confirmed the AU-14 tick for that file
 *
 * 🔴 DELETING A MENU OR A KEY DELETES ITS SOUNDS, and that is not tidiness. The media
 * route no longer asks who owns a file — it asks whether the file is still there —
 * because a menu file has no column on the client to be checked against. Deleting the
 * file IS the revocation. Leave one behind and it stays fetchable by anyone holding
 * its address, and those addresses never expire (AUQ-4).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $greeting_path
 * @property bool $greeting_rights_confirmed
 * @property array<int, array<string, mixed>> $options
 */
class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        // Head office builds menus with NO client in context (MenuResource), so which
        // client a menu is for arrives on the form rather than from the tenant wall.
        'tenant_id',
        'name',
        'greeting_path',
        'greeting_rights_confirmed',
        'options',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'greeting_rights_confirmed' => 'boolean',
            'options' => 'array',
        ];
    }

    /**
     * @return HasMany<PhoneNumber, $this>
     */
    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class);
    }

    /** The greeting's signed address, or null while the menu has no greeting yet. */
    public function greetingUrl(): ?string
    {
        return TenantMedia::MenuGreeting->addressFor($this->tenant_id, $this->greeting_path);
    }

    /**
     * The key this caller pressed, or null when the menu does not offer it (AU-24).
     *
     * @return array<string, mixed>|null
     */
    public function optionFor(string $digit): ?array
    {
        foreach ($this->options ?? [] as $option) {
            if (($option['key'] ?? null) === $digit) {
                return $option;
            }
        }

        return null;
    }

    /**
     * One key's own sound, or null when it has none — which is the ordinary case for
     * "talk to an agent", and the quieter version of "take me off your list" (AU-28).
     *
     * @param  array<string, mixed>  $option
     */
    public function soundUrlFor(array $option): ?string
    {
        return TenantMedia::MenuOption->addressFor($this->tenant_id, $option['sound_path'] ?? null);
    }

    /**
     * Every file this menu currently holds. The delete sweeps below work off this, so
     * a sound added in a later slice is covered by adding it here alone.
     *
     * @return array<int, string>
     */
    public function soundPaths(): array
    {
        $paths = [$this->greeting_path];

        foreach ($this->options ?? [] as $option) {
            $paths[] = $option['sound_path'] ?? null;
        }

        return array_values(array_filter($paths, static fn (?string $path): bool => filled($path)));
    }

    /**
     * Write a converted file back onto this menu — the greeting when no key is named,
     * otherwise that key's own sound.
     *
     * The file it replaces is deleted by the sweep below, not here: one place decides
     * when a menu file leaves the disk, and that place also covers the removals no
     * conversion job ever sees (a key deleted on the edit form).
     *
     * 🔴 THE KEY IS MATCHED, NOT THE POSITION. Two uploads on one save become two
     * queued jobs, and a job that wrote by array index would put its file on whichever
     * key had moved into that slot by the time it ran.
     */
    public function storeSoundPath(?string $optionKey, string $path): void
    {
        if ($optionKey === null) {
            $this->update(['greeting_path' => $path]);

            return;
        }

        $options = $this->options ?? [];

        foreach ($options as $index => $option) {
            if (($option['key'] ?? null) === $optionKey) {
                $options[$index]['sound_path'] = $path;
            }
        }

        $this->update(['options' => $options]);
    }

    /**
     * Take a file out of service. Used by both sweeps below and by nothing else —
     * replacing a sound is the conversion job's business, which deletes the file it
     * replaced on the same pass.
     */
    private static function forget(string $path): void
    {
        Storage::disk(config('telephony.media.disk'))->delete($path);
    }

    /**
     * The files this menu held BEFORE the save that is finishing now.
     *
     * `saved` fires before Eloquent re-syncs its copy of the row (Model::finishSave),
     * so the pre-save values are still readable at that moment. Postgres hands a JSON
     * column back as a string, and `getOriginal` does not run the cast, hence the decode.
     *
     * @return array<int, string>
     */
    private function soundPathsBeforeSave(): array
    {
        $options = $this->getOriginal('options');

        if (! is_array($options)) {
            $options = json_decode((string) $options, true) ?: [];
        }

        $paths = [$this->getOriginal('greeting_path')];

        foreach ($options as $option) {
            $paths[] = $option['sound_path'] ?? null;
        }

        return array_values(array_filter($paths, static fn (?string $path): bool => filled($path)));
    }

    protected static function booted(): void
    {
        // 🔴 ONE SWEEP COVERS EVERY WAY A MENU FILE STOPS BEING USED: a key removed, a
        // sound replaced by the conversion job, a greeting replaced. The media route no
        // longer checks who owns a file, so deleting it IS how a sound is taken out of
        // service — and its address never expires (AUQ-4).
        static::saved(function (self $menu): void {
            $kept = $menu->soundPaths();

            foreach ($menu->soundPathsBeforeSave() as $path) {
                if (! in_array($path, $kept, true)) {
                    self::forget($path);
                }
            }
        });

        static::deleting(function (self $menu): void {
            foreach ($menu->soundPaths() as $path) {
                self::forget($path);
            }
        });
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['name', 'greeting_path', 'greeting_rights_confirmed', 'options'];
    }

    protected function activityLogName(): string
    {
        return 'menu';
    }

    /**
     * The actions a menu key may take, for the edit form's dropdown.
     *
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        return collect(MenuAction::cases())
            ->mapWithKeys(static fn (MenuAction $action): array => [$action->value => $action->label()])
            ->all();
    }
}
