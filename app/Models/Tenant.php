<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\ClosedHours;
use App\Enums\TenantMedia;
use App\Enums\TenantStatus;
use App\Tenancy\InvalidTenantTransitionException;
use App\Tenancy\Observers\TenantObserver;
use App\Tenancy\Settings\TenantSettings;
use App\Tenancy\Settings\TenantSettingsCast;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * A client account operated by HighlandConnect. This is the tenant itself —
 * it is NOT tenant-owned, so it does not use the BelongsToTenant trait.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property Carbon|null $suspended_at
 * @property Carbon|null $archived_at
 * @property string|null $status_reason
 * @property int|null $ring_seconds
 * @property int|null $max_hold_seconds
 * @property string|null $timezone
 * @property string|null $hold_music_path
 * @property bool $hold_music_rights_confirmed
 * @property string|null $closed_message_path
 * @property bool $closed_message_rights_confirmed
 * @property TenantSettings $settings
 */
#[ObservedBy([TenantObserver::class])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'suspended_at',
        'archived_at',
        'status_reason',
        'ring_seconds',
        'max_hold_seconds',
        'timezone',
        'hold_music_path',
        'hold_music_rights_confirmed',
        'closed_message_path',
        'closed_message_rights_confirmed',
        'waiting_message_path',
        'waiting_message_rights_confirmed',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
            'ring_seconds' => 'integer',
            'max_hold_seconds' => 'integer',
            'hold_music_rights_confirmed' => 'boolean',
            'closed_message_rights_confirmed' => 'boolean',
            'settings' => TenantSettingsCast::class,
        ];
    }

    /**
     * How long ONE agent's phone rings for this client before we give up on them
     * and hand the caller back to the waiting room (B2.3b-i QD-7). Falls back to
     * the config default when the client has set nothing.
     */
    public function ringSeconds(): int
    {
        return $this->ring_seconds ?? (int) config('telephony.queue.ring_seconds');
    }

    /**
     * How long a caller may hold for this client before we stop waiting, end the
     * call, and write them to the missed-call list (B2.3b-i QD-7). Falls back to
     * the config default when the client has set nothing.
     */
    public function maxHoldSeconds(): int
    {
        return $this->max_hold_seconds ?? (int) config('telephony.queue.max_hold_seconds');
    }

    /**
     * The name the voice box knows this client's waiting-area music by (AU-13), or null
     * when the client has uploaded none — and null is what makes the caller hear the
     * stock music, because no class name is sent at all (inbound-audio slice 3, Q4).
     *
     * Named after the id, never the slug: a client can be renamed, its id cannot, and a
     * renamed class would orphan the row the voice box already holds. Asterisk's
     * `musiconhold.name` is varchar(80) (read off staging 2026-09-21); this is far short
     * of it.
     *
     * Passing the name for a client with no music would still be SAFE — S165 proved an
     * unknown class falls back to the default — but it would make the voice box go to
     * the database and miss on every hold start. Null keeps today's path, where `default`
     * is already in memory.
     */
    public function holdMusicClass(): ?string
    {
        return filled($this->hold_music_path) ? 'tenant-'.$this->id : null;
    }

    /**
     * The signed address the voice box fetches one of this client's sounds from, or null
     * when they have not uploaded that one (AUQ-4). ONE place builds every sound's
     * address, because two would eventually disagree and the signature would stop
     * matching.
     *
     * No expiry on purpose — see TenantMediaController for why. The file name IS its
     * SHA-256, so the address changes on every upload and the voice box's year-long
     * cached copy is discarded by the change of address alone.
     */
    public function mediaUrl(TenantMedia $kind): ?string
    {
        return $kind->addressFor($this->id, $this->{$kind->pathColumn()});
    }

    /** The waiting-area music's address (slice 3). Read by HoldMusicWriter and the form. */
    public function holdMusicUrl(): ?string
    {
        return $this->mediaUrl(TenantMedia::HoldMusic);
    }

    /**
     * The closed announcement's address (slice 4), or null when the client has uploaded
     * none — which is why "closed means a message" cannot be saved without one.
     */
    public function closedMessageUrl(): ?string
    {
        return $this->mediaUrl(TenantMedia::ClosedMessage);
    }

    /**
     * The waiting-area announcement's address (slice 5), or null when the client has
     * uploaded none — and null is what makes a waiting caller hear music only (AU-15).
     */
    public function waitingMessageUrl(): ?string
    {
        return $this->mediaUrl(TenantMedia::WaitingMessage);
    }

    /**
     * The zone this client's reports are read in (call-export.md CE-10). Falls back to
     * the system default when the client has set nothing, exactly as the two queue
     * settings above do — the fallback lives here in PHP, not as a database default, so
     * "a client with no zone set" stays a real and testable state.
     *
     * Used for BOTH halves of a date: the moment printed in a cell, and where the day
     * itself is cut (CE-10a). A file that prints India time but starts at UTC midnight
     * contradicts its own heading.
     */
    public function reportTimezone(): string
    {
        return $this->timezone ?? (string) config('app.report_timezone');
    }

    /**
     * The office hours sign (inbound-audio.md slice 1): is this client closed to callers at
     * this moment? Read on the client's own clock — the same zone the Reporting section
     * says business hours are written on.
     *
     * Never closed while the switch is off (AU-1). Otherwise open only inside a shift: a
     * weekday's opening time up to its closing time, where a closing time at or before the
     * opening time runs into the next morning (AU-6), so equal times mean 24 hours (S164).
     * A holiday stops that day's shift from starting; a shift that began the day before
     * runs on into it (AUQ-2, S164). That is why yesterday's shift is checked too.
     */
    public function isClosedAt(CarbonInterface $moment): bool
    {
        if ($this->settings->closedHours === ClosedHours::Off) {
            return false;
        }

        $now = CarbonImmutable::instance($moment)->setTimezone($this->reportTimezone());

        foreach ([$now->subDay(), $now] as $day) {
            $hours = $this->settings->hours[strtolower($day->englishDayOfWeek)] ?? null;

            if ($hours === null || in_array($day->toDateString(), $this->settings->holidays, true)) {
                continue;
            }

            $opens = $day->setTimeFromTimeString($hours['open']);
            $closes = $day->setTimeFromTimeString($hours['close']);

            if ($closes <= $opens) {
                $closes = $closes->addDay();
            }

            if ($now >= $opens && $now < $closes) {
                return false;
            }
        }

        return true;
    }

    /**
     * The ONLY lifecycle gate (M3 §5.1). Every code path that changes a tenant's
     * status — Filament actions, console commands, observers, a future API —
     * calls this. Centralising it keeps the meaning of "suspended" and
     * "archived" from drifting and makes the audit trail honest.
     */
    public function transitionTo(TenantStatus $next, ?string $reason = null): self
    {
        $current = $this->status;

        if ($current === $next) {
            return $this;
        }

        if (! $current->canTransitionTo($next)) {
            throw new InvalidTenantTransitionException($current, $next);
        }

        return DB::transaction(function () use ($next, $reason): self {
            $this->status = $next;
            $this->status_reason = $reason;

            $this->suspended_at = $next === TenantStatus::Suspended ? now() : null;
            $this->archived_at = $next === TenantStatus::Archived ? now() : $this->archived_at;

            $this->save();

            return $this;
        });
    }

    public function isOperable(): bool
    {
        return $this->status->isOperable();
    }

    /**
     * The spatie roles scoped to this tenant's team (M3 §5.6). Powers the
     * RolesRelationManager on the Tenant edit page so each tenant's role
     * surface is visibly scoped to its own team id — not the global team 0.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'team_id');
    }

    /**
     * Inverse of User::tenants(). Powers the UsersRelationManager on the
     * Tenant edit page so HC admins can add / remove / re-role agents for
     * this client without leaving the tenant context.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_tenant')->withTimestamps();
    }

    /**
     * The lifecycle + identity columns. `settings` is intentionally excluded:
     * it is a large nested object and its edits are lower-value for the audit
     * trail; the compliance-relevant Tenant events are the status transitions
     * (suspend / archive) captured here (D-M7-2).
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['name', 'slug', 'status', 'suspended_at', 'archived_at', 'status_reason', 'ring_seconds', 'max_hold_seconds', 'timezone', 'hold_music_path', 'hold_music_rights_confirmed', 'closed_message_path', 'closed_message_rights_confirmed', 'waiting_message_path', 'waiting_message_rights_confirmed'];
    }

    protected function activityLogName(): string
    {
        return 'tenant';
    }
}
