<?php

namespace App\Models;

use App\Actions\SeedCampaignDispositions;
use App\Audit\LogsModelActivity;
use App\Enums\CampaignCategory;
use App\Enums\CampaignTemplate;
use App\Enums\DialMode;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A calling drive a client runs (FR-LC01). Tenant-owned: every campaign carries
 * a tenant_id and is filtered by RLS + the app-layer scope. Leads belong to a
 * campaign; the campaign's template drives its starter disposition set (M4.E).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property CampaignTemplate $template
 * @property DialMode $dial_mode
 * @property bool $is_dialing
 * @property CampaignCategory $category
 * @property string|null $caller_id
 * @property string $dial_start_time
 * @property string $dial_end_time
 * @property int|null $max_attempts
 * @property int $retry_gap_minutes
 * @property bool $is_active
 * @property array<int, array<string, mixed>> $custom_fields
 */
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    /** CP-4: the per-client bucket an inbound caller with no list entry lands in. */
    public const INBOUND_NAME = 'Inbound';

    protected $fillable = [
        'name',
        'template',
        'dial_mode',
        'is_dialing',
        'category',
        'caller_id',
        'dial_start_time',
        'dial_end_time',
        'max_attempts',
        'retry_gap_minutes',
        'is_active',
        'custom_fields',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => CampaignTemplate::class,
            'dial_mode' => DialMode::class,
            'is_dialing' => 'boolean',
            'category' => CampaignCategory::class,
            'max_attempts' => 'integer',
            'retry_gap_minutes' => 'integer',
            // 🔴 dial_start_time / dial_end_time are deliberately NOT cast (S118).
            // They are wall-clock times with no date, so casting them to datetime
            // makes Filament convert them against the panel's reading zone — the
            // bug that turned 09:30 into 04:00 on the business-hours form. Postgres
            // hands them back as 'HH:MM:SS' strings, which is what they are.
            'is_active' => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    /**
     * CP-4 — the campaign an inbound caller nobody has on a list belongs to.
     *
     * `leads.campaign_id` is NOT NULL, so a customer created from an inbound call
     * has to land somewhere. A8: on their floor these callers already sit under
     * "inbound", and which campaign that means varies client to client — which is
     * exactly what a per-client campaign gives. Found-or-created on first use, so
     * no seeder and no migration on a live table.
     *
     * `CustomerCareCallback` as the template deliberately: it is the closest
     * existing shape, and reusing it means SeedCampaignDispositions hands the new
     * campaign a real disposition set with no new enum case and no config change.
     *
     * Tenant-scoped both ways — the lookup is filtered by TenantScope and the
     * create is stamped by BelongsToTenant, so one client can never be handed
     * another client's inbound bucket.
     */
    public static function inboundFallback(): self
    {
        $campaign = self::query()->firstOrNew(['name' => self::INBOUND_NAME]);

        if ($campaign->exists) {
            return $campaign;
        }

        $campaign->fill([
            'template' => CampaignTemplate::CustomerCareCallback,
            'is_active' => true,
        ])->save();

        SeedCampaignDispositions::run($campaign);

        return $campaign;
    }

    /**
     * The campaigns the dialer should be working RIGHT NOW (DIAL-1 A2): active,
     * progressive, switched on, and inside their own calling window. One place, so
     * the stop switch and the calling window can never disagree — "is_dialing off"
     * and "past the stop time" are the same answer to the same question, which is
     * half of DP-10 before any dialing exists.
     *
     * 🔴 The window is compared against the CLIENT's clock, not the app's. `app.timezone`
     * is UTC, but the panel reads in TenantContext::reportTimezone() and both TimePickers
     * are pinned so nothing is converted on save (S118) — so the stored digits ARE the
     * client's wall clock. Against a UTC now(), a 10:00–21:00 window would dial from
     * 15:30 to 02:30 India time: the exact thing G2's promotional clamp exists to stop,
     * with a legal-looking window still showing on the form.
     *
     * The end is strict: a window that stops at 21:00 places no call AT 21:00:00. No
     * wrap-around case to handle — the form refuses a window that ends before it starts
     * (F5), and if that check is ever dropped this scope matches nothing rather than
     * dialing at the wrong hour.
     *
     * Tenant-walled for free by BelongsToTenant + RLS, exactly like Lead::callable.
     *
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    public function scopeDialable(Builder $query): Builder
    {
        $now = Carbon::now(TenantContext::reportTimezone())->format('H:i:s');

        return $query
            ->switchedOn()
            ->where('dial_start_time', '<=', $now)
            ->where('dial_end_time', '>', $now);
    }

    /**
     * Everything dialable() asks EXCEPT the clock (DIAL-1 A3). Split out because the two
     * halves are asked from different places: the tick has no client in scope, so it reads
     * "who has a campaign switched on" across every client at once, then opens each of
     * those clients in turn to ask the window question in that client's own clock (F7).
     * One query on a quiet night instead of one per client per second.
     *
     * Split rather than repeated, for the reason A1 and A2 both exist: two spellings of
     * "this campaign is switched on" is how a stop switch ends up meaning one thing to the
     * dialer and another to the screen.
     *
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    public function scopeSwitchedOn(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('dial_mode', DialMode::Progressive->value)
            ->where('is_dialing', true);
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return HasMany<Disposition, $this>
     */
    public function dispositions(): HasMany
    {
        return $this->hasMany(Disposition::class);
    }

    /**
     * @return HasMany<Script, $this>
     */
    public function scripts(): HasMany
    {
        return $this->hasMany(Script::class);
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['name', 'template', 'dial_mode', 'is_dialing', 'category', 'caller_id', 'dial_start_time', 'dial_end_time', 'max_attempts', 'is_active', 'custom_fields'];
    }

    protected function activityLogName(): string
    {
        return 'campaign';
    }
}
