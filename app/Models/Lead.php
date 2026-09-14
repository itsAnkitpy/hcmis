<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\CallbackStatus;
use App\Enums\LeadStatus;
use App\Support\PhoneNumber;
use App\Tenancy\BelongsToTenant;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A contact record an agent works (FR-LC01–04). Tenant-owned and bound to one
 * campaign. Its position in the fixed funnel is `status` (D-M4-3); its last
 * call outcome is the configurable `lastDisposition` row.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $campaign_id
 * @property string|null $name
 * @property string $phone
 * @property string|null $email
 * @property string|null $city
 * @property string|null $region
 * @property LeadStatus $status
 * @property int|null $last_disposition_id
 * @property int $attempts
 * @property Carbon|null $claimed_at
 * @property array<string, mixed> $custom_fields
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    /**
     * How long a claim holds a lead before the serving query treats it as expired
     * (DIAL-1 DP-3). Long enough to cover a dial that is still ringing, short
     * enough that a crashed dialer or a closed browser tab frees the lead without
     * a release job — the expiry is a WHERE clause, not a worker.
     *
     * A console holding a lead through a longer conversation re-stamps it on the
     * heartbeat it already sends every ~15s, so a real call never outlives it.
     */
    public const CLAIM_TTL_SECONDS = 90;

    protected $fillable = [
        'campaign_id',
        'name',
        'phone',
        'email',
        'city',
        'region',
        'status',
        'last_disposition_id',
        'attempts',
        'claimed_at',
        'custom_fields',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'custom_fields' => 'array',
        ];
    }

    /**
     * Every lead phone is stored in ONE spelling — the DncEntry mutator pattern
     * (M6 D-M6-5), which leads never got. Import normalized on write; hand-entry
     * did not, so `999-123 4567` and `9991234567` could sit side by side as two
     * leads for one person, and screen-pop's exact match missed the formatted one
     * entirely (it normalizes the INCOMING number only).
     *
     * Placed on the model, not on a form or an importer, because that is the one
     * door every write path already goes through — Filament create/edit, the
     * importer's Lead::create, and the factory. There are no raw SQL inserts.
     *
     * This is also what makes the unique (tenant_id, phone) rule meaningful: to
     * the database those two spellings are different strings, so the constraint
     * alone would never have caught them.
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => PhoneNumber::normalize($value),
        );
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The last call outcome recorded against this lead (D-M4-3).
     *
     * @return BelongsTo<Disposition, $this>
     */
    public function lastDisposition(): BelongsTo
    {
        return $this->belongsTo(Disposition::class, 'last_disposition_id');
    }

    /**
     * Scheduled "call me later" rows for this lead (M4). A pending one parks the
     * lead out of the normal preview — it returns via the agent's due-list.
     *
     * @return HasMany<Callback, $this>
     */
    public function callbacks(): HasMany
    {
        return $this->hasMany(Callback::class);
    }

    /**
     * Every call ever placed to or from this lead (B3). Read by DP-14's retry gap,
     * which is the reason it exists as a relation rather than an ad-hoc join.
     *
     * @return HasMany<Call, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /**
     * The one serving rule: the next lead worth working on a campaign (DIAL-1
     * DP-1). Not Closed, fewest attempts first then oldest, never one parked by a
     * pending callback, never one another caller is already holding.
     *
     * It lived as a private method on the agent console, so a background program
     * could not ask the same question. Both callers — the console and the coming
     * dialer — now read the identical rule, which is the point: two definitions of
     * "next lead" is how a floor and a dialer end up dialing different people.
     *
     * $excludeIds is the caller's own skip list (the console's per-session passes).
     * Tenant-walled for free by BelongsToTenant + RLS.
     *
     * @param  array<int, int>  $excludeIds
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeCallable(Builder $query, int $campaignId, array $excludeIds = []): Builder
    {
        return $query
            ->where('campaign_id', $campaignId)
            ->where('status', '!=', LeadStatus::Closed->value)
            ->when($excludeIds !== [], fn (Builder $query) => $query->whereNotIn('id', $excludeIds))
            ->unclaimed()
            // DIAL-1 DP-6: a number that has been tried its campaign's limit of times
            // stops being served. Read live off the campaign rather than copied onto the
            // lead, so raising the cap puts every capped-out lead straight back in the
            // pool with no backfill.
            //
            // A null max_attempts is no cap — which is every campaign today — and needs
            // no clause of its own: `attempts >= NULL` is NULL, never true, so the inner
            // EXISTS finds nothing and the lead stays servable. An explicit whereNotNull
            // was here and removed once a break test proved no change could make it fail.
            ->whereDoesntHave('campaign', fn (Builder $query) => $query
                ->whereColumn('leads.attempts', '>=', 'campaigns.max_attempts'))
            // A lead with a pending callback is parked (PR2): it surfaces only via
            // the agent's due-list when due, never the normal preview. Once dialed
            // (callback -> done), it returns to the pool.
            ->whereDoesntHave('callbacks', fn ($query) => $query->where('status', CallbackStatus::Pending))
            ->orderBy('attempts')
            ->orderBy('id');
    }

    /**
     * Free to take: nobody holds it, or whoever did has gone quiet for longer than
     * CLAIM_TTL_SECONDS. One definition of "expired", shared by the serving rule and
     * by claim() itself, so a lead can never be served by one test and refused by the
     * other.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeUnclaimed(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('claimed_at')
            ->orWhere('claimed_at', '<', now()->subSeconds(self::CLAIM_TTL_SECONDS)));
    }

    /**
     * Leads nobody has rung in the last $minutes (DIAL-1 DP-14).
     *
     * 🔴 Deliberately NOT folded into callable(). That rule is shared with the agent
     * console (AgentConsole::nextCallableLead), and an agent choosing to ring somebody
     * back after forty minutes is not the dialer machine-gunning them. Chained at the
     * dialer's call site alone, so the console's queue is provably untouched — one grep
     * for this name finds every caller, which a boolean argument on callable() would not
     * give.
     *
     * EVERY call counts, an agent's own hand-dialled one included: from the customer's
     * side it is the same company ringing twice in half an hour. `was_dialled` could
     * narrow it to the dialer's own and deliberately does not.
     *
     * That reaches INBOUND as well, and only the half of it that should. A call the
     * CUSTOMER made which an agent then wrapped up is filed against their lead (the
     * console matches them on phone number), so speaking to them at 10:00 holds the
     * dialer off until 12:00 — right, because we have just spoken to them. A missed
     * inbound call is not: those rows are written with no lead attached at all, so
     * somebody who rang us and gave up is still reachable on the very next tick, which
     * is the behaviour a floor would want.
     *
     * Read off `created_at`: `started_at` is trunk-era listener enrichment and is null
     * in v1, so it is the only moment on a `calls` row that is always populated.
     * `calls.lead_id` is indexed, so this costs an EXISTS on an indexed column.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeNotDialedRecently(Builder $query, int $minutes): Builder
    {
        return $query->whereDoesntHave('calls', fn (Builder $query) => $query
            ->where('calls.created_at', '>=', now()->subMinutes($minutes)));
    }

    /**
     * Take this lead, or find out somebody else already did (DIAL-1 DP-3).
     *
     * The conditional-update shape AgentRouter::reserveFreeAgent uses for desks:
     * update only the row that still matches, then check exactly one row changed.
     * The database decides the winner, so two agents pressing Dial in the same
     * second cannot both be handed this lead — which they can TODAY, because
     * `attempts` is only bumped at wrap-up and the console's skip list is
     * per-session.
     *
     * Released by setting `claimed_at` null at wrap-up. A claim nobody releases
     * expires on its own (scopeUnclaimed), so there is no timeout worker.
     */
    public function claim(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->unclaimed()
            ->update(['claimed_at' => now()]);

        return $claimed === 1;
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['campaign_id', 'name', 'phone', 'email', 'city', 'region', 'status', 'last_disposition_id', 'attempts', 'custom_fields'];
    }

    protected function activityLogName(): string
    {
        return 'lead';
    }
}
