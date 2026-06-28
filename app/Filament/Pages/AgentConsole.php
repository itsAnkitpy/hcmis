<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\AdvanceLeadStatus;
use App\Audit\Audit;
use App\Enums\CallbackStatus;
use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Enums\LeadStatus;
use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Models\AgentPresence;
use App\Models\Call;
use App\Models\Callback;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Telephony\AgentDirectory;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;

/**
 * Agent Console (B4 D1) — the agent's one screen: a browser SIP phone plus the
 * work screen, both in the panel they already log into. CP1 stands the page up
 * and registers it as a phone (D1 + D2 + D7); ringing / answer / wrap-up build
 * on top in CP2–CP3.
 *
 * Gated to the agent role (their first and only surface — agents have no
 * operational resources, D-M4-5) plus global staff for demo/testing.
 */
class AgentConsole extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.agent-console';

    /**
     * The matched lead + its campaign, held SERVER-SIDE across the call (B4 CP3
     * decision C). #[Locked] so the browser cannot tamper them: the wrap-up write
     * keys off these, never a browser-supplied id. Set in lookupLead() on a match,
     * cleared on no-match and after every wrap-up.
     */
    #[Locked]
    public ?int $matchedLeadId = null;

    #[Locked]
    public ?int $matchedCampaignId = null;

    /**
     * The call's direction + the other party's number, held SERVER-SIDE across the
     * call so the B3 wrap-up can stamp the `calls` row (D2). Both are set on the
     * server, never from the browser (#[Locked]): direction flips to Outbound only
     * in originateAgentLeg (the one shared dial path) and defaults to Inbound, so
     * anything that arrives — including an anonymous caller with no lookupLead — is
     * correctly inbound. callPartyNumber is the customer's number (dialled or
     * calling); null for an anonymous inbound caller. Both reset after every wrap-up.
     */
    #[Locked]
    public CallDirection $callDirection = CallDirection::Inbound;

    #[Locked]
    public ?string $callPartyNumber = null;

    /**
     * The call's tracking number (B3 D3) — a UUID we own, generated at dial and
     * held SERVER-SIDE across the call (#[Locked], the matchedLeadId pattern). It
     * rides the originate as an ordered tag arg so the listener threads it to the
     * recording (CP-B3-2); the wrap-up stamps the SAME id onto the `calls` row as
     * correlation_id, so the queued RecordingReady listener can find the row and
     * attach the recording by UUID — no fuzzy time/number matching, provider-agnostic.
     * Set only on the outbound dial path (originateAgentLeg); null for inbound v1
     * (the customer originates — the SIP-header spike is trunk-era, O1). Reset per call.
     */
    #[Locked]
    public ?string $callCorrelationId = null;

    /**
     * The campaign the agent is working (outbound preview — O4). A plain agent
     * choice among their own client's campaigns; the serving query runs in tenant
     * context, so RLS walls it regardless of what id the browser sends.
     */
    public ?int $selectedCampaignId = null;

    /**
     * Leads the agent skipped this session (O4 Skip — advance the served cursor
     * with no write). Excluded from serving so the next callable lead is shown.
     * Browser-visible by design: at worst the agent skips their own leads.
     *
     * @var array<int, int>
     */
    public array $skippedLeadIds = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->operatesGlobally() || $user->hasRole(RoleName::Agent->value);
    }

    /**
     * The browser phone's registration settings (B4 D7 config map). Passed to
     * the Alpine state machine; the lab SIP secret is a throwaway and prod must
     * not register the browser this way (see config/telephony.php agent note).
     *
     * B2.2b Fold A: resolved PER LOGGED-IN AGENT via the directory, so a 2nd agent
     * registers as their OWN phone (1004) instead of the one global extension every
     * agent used to get. Users not in the directory fall back to the single-agent
     * config, so the one-agent lab path is unbroken.
     *
     * @return array{extension: ?string, password: ?string, wsUrl: ?string, sipDomain: string}
     */
    public function getPhoneConfig(): array
    {
        return app(AgentDirectory::class)->browserIdentityFor((int) auth()->id());
    }

    /**
     * Write the agent's spot on the who's-free board (B2.2 PD-3) — the screen is the
     * single writer. Upserts the ONE overwritten row per agent (PD-6): a status change
     * replaces it in place, never appends. Keyed to auth()->id() in the request's tenant
     * context, so an agent can only ever write their OWN row in their OWN client
     * (BelongsToTenant stamps tenant_id on create; the unique (tenant_id, user_id) backs
     * the upsert). The browser calls this over $wire on each state transition. Renderless:
     * it fires mid-call (entering on-call / wrap-up), so it must not morph the live console.
     */
    #[Renderless]
    public function setPresence(string $status): void
    {
        $presence = PresenceStatus::tryFrom($status);

        abort_if($presence === null, 422, 'Unknown presence status.');

        AgentPresence::query()->updateOrCreate(
            ['user_id' => auth()->id()],
            ['status' => $presence, 'last_seen_at' => now()],
        );
    }

    /**
     * The screen quietly saying "still here" (B2.2 PD-4) on its ~15s timer. Refreshes
     * the heartbeat stamp WITHOUT touching status, so a long call keeps the agent shown
     * alive on the board (a stale stamp reads as Offline — AgentPresence::effectiveStatus).
     * Touches the existing row only: it keeps a live agent alive, but never resurrects a
     * logged-out one (no row ⇒ harmless no-op). Own-row + tenant-scoped by auth()->id().
     * Renderless — a heartbeat must never disturb a live call.
     */
    #[Renderless]
    public function heartbeat(): void
    {
        AgentPresence::query()
            ->where('user_id', auth()->id())
            ->update(['last_seen_at' => now()]);
    }

    /**
     * Match the ringing caller's number to a lead in the agent's own client and
     * return the small display shape the screen shows (B4 D4). The browser calls
     * this over $wire when a call comes in.
     *
     * It runs in the web request's tenant context, so BelongsToTenant + RLS wall
     * the lookup to the agent's client for free — the same number in another
     * client never matches (the tenant wall S26 re-learned). The incoming number
     * is normalized exactly like stored lead phones (PhoneNumber, M6 D-M6-5), so
     * a formatted caller-ID still matches the bare stored value.
     *
     * No match — unknown or anonymous number — returns null: the screen shows the
     * bare number and says "no matching lead" (D4/D6 honest edge); wrap-up still
     * works.
     *
     * @return array{id: int, name: ?string, phone: string, campaign: ?string, status: string, lastDisposition: ?string}|null
     */
    public function lookupLead(string $number): ?array
    {
        // A fresh ring always resets the server-held match first, so a no-match
        // can never inherit the previous caller's lead (decision C).
        $this->resetMatch();

        $phone = PhoneNumber::normalize($number);

        if ($phone === null) {
            return null;
        }

        // Inbound: the caller's number rides the B3 row (direction stays Inbound,
        // reset above). A matched-or-not call still records the calling number.
        $this->callPartyNumber = $phone;

        $lead = Lead::query()
            ->with(['campaign', 'lastDisposition'])
            ->where('phone', $phone)
            ->first();

        if ($lead === null) {
            return null;
        }

        $this->matchedLeadId = $lead->id;
        $this->matchedCampaignId = $lead->campaign_id;

        return $this->presentLead($lead);
    }

    /**
     * The campaigns the agent can work (O4 — one selected campaign at a time).
     * Active campaigns in the agent's own client; RLS + BelongsToTenant wall the
     * list to their tenant.
     *
     * @return array<int, string>
     */
    public function campaignOptions(): array
    {
        return Campaign::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The next callable lead the console presents for the selected campaign
     * (M1 preview, D2): not Closed, fewest attempts first then oldest, skipping
     * any the agent passed this session. Presentation only — the id is stashed at
     * dial, never here (the inverse of inbound's ring-time lookup).
     *
     * @return array{id: int, name: ?string, phone: string, campaign: ?string, status: string, lastDisposition: ?string}|null
     */
    public function servedLead(): ?array
    {
        $lead = $this->nextCallableLead();

        return $lead === null ? null : $this->presentLead($lead);
    }

    /**
     * The agent's due callbacks (M4): their OWN (sticky), still pending, and now
     * due (scheduled_at has passed). Cross-tenant walled by RLS, cross-agent by the
     * owner filter. Each is dialed like a served lead via dialCallback(). Soonest
     * first. Spans campaigns — a callback is owned by the agent, not the campaign
     * they happen to have selected.
     *
     * Due-detection is server-side in the app timezone (UTC). The schedule rides
     * out as a UTC ISO string (scheduledAtIso) so the browser renders it in the
     * AGENT's own clock — the picker is browser-local too, so capture and display
     * agree without flipping the app's global timezone.
     *
     * @return array<int, array{id: int, leadName: ?string, phone: string, campaign: ?string, scheduledAtIso: string, notes: ?string}>
     */
    public function dueCallbacks(): array
    {
        return Callback::query()
            ->with(['lead', 'campaign'])
            ->where('owner_agent_id', auth()->id())
            ->where('status', CallbackStatus::Pending)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Callback $callback): array => $this->presentCallback($callback))
            ->all();
    }

    /**
     * The POOLED due callbacks (B2.0): unowned (owner_agent_id null), still
     * pending, and now due — the "anyone can take this" notes any free agent in
     * the client may grab. Cross-tenant walled by RLS (the same wall dueCallbacks
     * leans on); the unowned filter is what makes them shared rather than sticky.
     * The existing (tenant_id, owner_agent_id, status, scheduled_at) index serves
     * this owner-IS-NULL lookup, so no new index is needed. Soonest first.
     *
     * A grab (claimCallback) stamps the owner, so a grabbed callback leaves this
     * list and appears in the grabber's own dueCallbacks() — same shape, so the
     * screen renders both panels identically.
     *
     * @return array<int, array{id: int, leadName: ?string, phone: string, campaign: ?string, scheduledAtIso: string, notes: ?string}>
     */
    public function pooledCallbacks(): array
    {
        return Callback::query()
            ->with(['lead', 'campaign'])
            ->whereNull('owner_agent_id')
            ->where('status', CallbackStatus::Pending)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Callback $callback): array => $this->presentCallback($callback))
            ->all();
    }

    /**
     * Grab a pooled (unowned) callback so it becomes the calling agent's own
     * (B2.0 PC-2). The whole point is the small race: two free agents may click
     * Grab on the same row at the same instant, and exactly one must win.
     *
     * It's a single atomic conditional update — "set owner = me WHERE id = X AND
     * owner is still null AND still pending" — so the database, not app code,
     * resolves the race: the winner's update touches one row, the loser's touches
     * zero. RLS walls the update to the agent's own client, so a cross-tenant id
     * also touches zero rows and reads as 'taken'. (This is database-row
     * concurrency, separate from the call-handling concurrency of B2.1.)
     *
     * On a win the callback now sits in the agent's own due-list and dials +
     * completes through the existing dialCallback path, untouched. On a loss the
     * screen refreshes the pooled list and shows an "already taken" notice.
     *
     * A win also logs a `callback.grabbed` audit line (who grabbed it, when) —
     * recorded explicitly because the atomic query-level update skips the model
     * events the create/update auto-logs ride on. Only a real claim (one row
     * touched) is audited; a lost or cross-tenant race writes nothing.
     *
     * @return array{outcome: 'claimed'|'taken'}
     */
    public function claimCallback(int $callbackId): array
    {
        $claimed = Callback::query()
            ->whereKey($callbackId)
            ->whereNull('owner_agent_id')
            ->where('status', CallbackStatus::Pending)
            ->update(['owner_agent_id' => auth()->id()]);

        if ($claimed === 1) {
            Audit::callbackGrabbed(Callback::findOrFail($callbackId));
        }

        return ['outcome' => $claimed === 1 ? 'claimed' : 'taken'];
    }

    /**
     * Dial the served lead (M2 origination, agent-first). The lead is re-resolved
     * SERVER-SIDE here (never a browser-supplied id); its number is checked against
     * the client's Do-Not-Call list FIRST (O1 — listed numbers never ring). On a
     * clean number the ids are stashed #[Locked] exactly like inbound's match and
     * the AGENT leg is originated carrying the customer's number as the leg's tag
     * detail (the flow reads it back and dials the customer — CP-O0 transport).
     *
     * Returns a small discriminated result the screen branches on:
     *  - 'dialed'  + lead   : the call was placed (show the call card).
     *  - 'blocked' + phone  : on the do-not-call list, not dialed (show the notice).
     *  - 'none'             : nothing callable in the campaign (back to ready).
     *
     * @return array{outcome: 'dialed', lead: array{id: int, name: ?string, phone: string, campaign: ?string, status: string, lastDisposition: ?string}}|array{outcome: 'blocked', phone: string}|array{outcome: 'none'}
     */
    public function dial(): array
    {
        $lead = $this->nextCallableLead();

        if ($lead === null) {
            return ['outcome' => 'none'];
        }

        if ($this->isDncListed($lead->phone)) {
            return $this->blockServedLead($lead);
        }

        $this->matchedLeadId = $lead->id;
        $this->matchedCampaignId = $lead->campaign_id;

        $this->originateAgentLeg($lead->phone);

        return ['outcome' => 'dialed', 'lead' => $this->presentLead($lead)];
    }

    /**
     * Skip the served lead without dialing (O4): remember it for this session so
     * serving moves on. No write, no telephony — just advances the cursor.
     */
    public function skip(): void
    {
        $lead = $this->nextCallableLead();

        if ($lead !== null) {
            $this->skippedLeadIds[] = $lead->id;
        }
    }

    /**
     * Dial a specific due callback (M4) instead of the next campaign lead. The
     * callback is re-resolved server-side and must be the agent's OWN and still
     * pending (the cross-agent wall; RLS is the cross-tenant wall) — a stale id
     * resolves to nothing. Its lead's number gets the same Do-Not-Call guard a
     * served lead gets (O1). Dialing CONSUMES the callback — it flips to done so it
     * leaves the due-list; a reschedule becomes a fresh callback at the next
     * wrap-up. On a clean number the lead ids are stashed #[Locked] and the agent
     * leg is originated, exactly like a served-lead dial.
     *
     * @return array{outcome: 'dialed', lead: array{id: int, name: ?string, phone: string, campaign: ?string, status: string, lastDisposition: ?string}}|array{outcome: 'blocked', phone: string}|array{outcome: 'none'}
     */
    public function dialCallback(int $callbackId): array
    {
        $callback = Callback::query()
            ->with('lead')
            ->where('owner_agent_id', auth()->id())
            ->where('status', CallbackStatus::Pending)
            ->find($callbackId);

        if ($callback === null || $callback->lead === null) {
            return ['outcome' => 'none'];
        }

        $lead = $callback->lead;

        if ($this->isDncListed($lead->phone)) {
            return $this->blockCallback($callback, $lead);
        }

        // Consume the callback: dialing it drops it off the due-list.
        $callback->update(['status' => CallbackStatus::Done]);

        $this->matchedLeadId = $lead->id;
        $this->matchedCampaignId = $lead->campaign_id;

        $this->originateAgentLeg($lead->phone);

        return ['outcome' => 'dialed', 'lead' => $this->presentLead($lead)];
    }

    /**
     * Dial a number the agent typed by hand (M1 D3 — ad-hoc). Gated by the
     * dial-adhoc ability. The number is normalized the same way stored phones are
     * (PhoneNumber, the lookupLead pattern — no length rule, so lab extensions and
     * real numbers both dial), checked against the do-not-call list FIRST (O1 — the
     * same guard a served lead gets), then the agent leg is originated carrying it.
     *
     * An ad-hoc call has NO lead, so NOTHING is stashed (resetMatch) — its wrap-up
     * rides the no-match path (completeUnmatched), which writes a lead-less calls
     * row (B3 D5). A do-not-call number is blocked with only an audit line (no lead
     * to close) — and so writes no calls row.
     *
     * @return array{outcome: 'dialed'|'blocked', phone: string}|array{outcome: 'invalid'}
     */
    public function dialAdhoc(string $number): array
    {
        Gate::authorize('dial-adhoc');

        $phone = PhoneNumber::normalize($number);

        if ($phone === null) {
            return ['outcome' => 'invalid'];
        }

        // No lead behind a typed number — clear any held match so a later wrap-up
        // takes the no-match path and writes nothing.
        $this->resetMatch();

        if ($this->isDncListed($phone)) {
            Audit::dncBlocked(null, $phone);

            return ['outcome' => 'blocked', 'phone' => $phone];
        }

        $this->originateAgentLeg($phone);

        return ['outcome' => 'dialed', 'phone' => $phone];
    }

    /**
     * Whether the agent may type a one-off number (D3). Drives the blade: the
     * ad-hoc input only renders for a permitted agent. The server-side gate on
     * dialAdhoc() is the real wall — this just hides UI they cannot use.
     */
    public function canDialAdhoc(): bool
    {
        return Gate::allows('dial-adhoc');
    }

    /**
     * Switching campaigns starts the served cursor fresh and drops any held match
     * (Livewire fires this when selectedCampaignId changes).
     */
    public function updatedSelectedCampaignId(): void
    {
        $this->skippedLeadIds = [];
        $this->resetMatch();
    }

    /**
     * The serving query, shared by servedLead() (presentation) and dial()
     * (origination) so both always agree on "the next lead". Runs in tenant
     * context, so the tenant wall is automatic.
     */
    private function nextCallableLead(): ?Lead
    {
        if ($this->selectedCampaignId === null) {
            return null;
        }

        return Lead::query()
            ->with(['campaign', 'lastDisposition'])
            ->where('campaign_id', $this->selectedCampaignId)
            ->where('status', '!=', LeadStatus::Closed->value)
            ->whereNotIn('id', $this->skippedLeadIds)
            // A lead with a pending callback is parked (PR2): it surfaces only via
            // the agent's due-list when due, never the normal preview. Once dialed
            // (callback -> done), it returns to the pool.
            ->whereDoesntHave('callbacks', fn ($query) => $query->where('status', CallbackStatus::Pending))
            ->orderBy('attempts')
            ->orderBy('id')
            ->first();
    }

    /**
     * Originate the AGENT leg first (agent-first), carrying the customer number as
     * the leg's tag detail so the flow reads it back and dials the customer
     * (CP-O0 transport). Shared by the served-lead dial and the ad-hoc dial — the
     * only difference between them is where the number comes from.
     */
    private function originateAgentLeg(string $customerNumber): void
    {
        // The single outbound dial path (served lead, callback, ad-hoc all route
        // here) — so it's the one place that stamps the B3 call as Outbound,
        // records the dialled number, and mints the call's tracking number (D3)
        // for the wrap-up row + the recording attach.
        $this->callDirection = CallDirection::Outbound;
        $this->callPartyNumber = $customerNumber;
        $this->callCorrelationId = (string) Str::uuid();

        app(TelephonyProvider::class)->placeCall(
            // Ring the LOGGED-IN agent's OWN phone (B2.2b Fold A directory), not the one
            // fixed endpoint — so a 2nd agent's outbound leg rings 1004, not 1003 (closes
            // the §7 outbound-per-agent gap). Falls back to the single-agent config for a
            // user not in the directory, so the one-agent lab path stays unbroken. With
            // the agent id now threaded below, an outbound call is fully transferable too.
            app(AgentDirectory::class)->endpointFor((int) auth()->id()),
            'agent',
            // B2.4a (TD-4 fold): thread the dialing agent's user id onto the agent leg
            // (the 4th ordered tag value) so the handler retains WHO is serving and an
            // OUTBOUND call can be transferred too — the transfer signal finds the
            // B2.4a (TD-4 fold): thread the dialing agent's user id onto the agent leg
            // (the 4th ordered tag value) so the handler retains WHO is serving and an
            // OUTBOUND call can be transferred too — the transfer signal finds the
            // handler by it. Backwards-compatible: the flow's >= 2 arg guard tolerates
            // the extra value, and CP-B2.4a demos the inbound path either way.
            tagDetails: [$customerNumber, $this->callCorrelationId, (string) auth()->id()],
        );
    }

    /**
     * Cold-transfer the live call to a free agent (B2.4a TD-4). The browser calls this
     * over $wire when the agent clicks Transfer mid-call: it POSTs a control signal to
     * the running listener (web->provider-direct, the placeCall precedent) carrying the
     * agent's own user id (the correlator — they are on exactly one call) and the
     * server-derived tenant id. The listener finds this agent's live call, reserves a
     * free agent, and rings them while the caller stays put — no database table, no poll.
     *
     * Renderless: it fires mid-call, so it must not morph the live console — the screen
     * drives its own "Transferring…" state and no-answer timeout (TD-5).
     */
    #[Renderless]
    public function transferCall(): void
    {
        $tenantId = TenantContext::id();

        // No tenant context (a global-staff demo session, not a tenant agent) -> nothing
        // to reserve against; the reserve is tenant-scoped (TD-6), so there is no call.
        if ($tenantId === null) {
            return;
        }

        app(TelephonyProvider::class)->signal('transfer', [
            'agentUserId' => (string) auth()->id(),
            'tenantId' => (string) $tenantId,
        ]);
    }

    /**
     * Whether a (normalized) number is on the agent's own client's Do-Not-Call
     * list (O1). Runs in the web request's tenant context, so BelongsToTenant +
     * RLS wall the check to this client — another client's list never blocks here.
     * Presence is the block: expiry is stored but not enforced (M6 D-M6-8), so the
     * safe compliance default is to block on any match.
     */
    private function isDncListed(string $phone): bool
    {
        return DncEntry::query()->where('phone', $phone)->exists();
    }

    /**
     * Handle a served lead whose number is on the do-not-call list: never dial it,
     * drop it out of rotation by closing it (a compliance hard-stop — the one
     * place a wrap-up auto-Closes, since the lead can never legally be called), and
     * leave an audit trail. Deliberately does NOT bump attempts or mark a contact —
     * no call was placed, so faking either would lie to the campaign reports. DNC is
     * a cross-cutting flag, not a funnel disposition (LeadStatus docblock), so no
     * disposition is written. Clears the held match so wrap-up has nothing to key on.
     *
     * @return array{outcome: 'blocked', phone: string}
     */
    private function blockServedLead(Lead $lead): array
    {
        $lead->update(['status' => LeadStatus::Closed]);

        Audit::dncBlocked($lead);

        $this->resetMatch();

        return ['outcome' => 'blocked', 'phone' => $lead->phone];
    }

    /**
     * A due callback whose number is now on the do-not-call list: never dial it,
     * consume the callback (done, so it leaves the due-list), and close the lead
     * out of rotation — the same compliance hard-stop a served-lead block applies,
     * audited. The callback flip + lead close share one transaction.
     *
     * @return array{outcome: 'blocked', phone: string}
     */
    private function blockCallback(Callback $callback, Lead $lead): array
    {
        DB::transaction(function () use ($callback, $lead): void {
            $callback->update(['status' => CallbackStatus::Done]);
            $lead->update(['status' => LeadStatus::Closed]);
            Audit::dncBlocked($lead);
        });

        $this->resetMatch();

        return ['outcome' => 'blocked', 'phone' => $lead->phone];
    }

    /**
     * The small display shape the screen shows for a lead — shared by the inbound
     * caller match (lookupLead) and the outbound served lead (servedLead/dial), so
     * the lead card reads identically whichever way the call started.
     *
     * @return array{id: int, name: ?string, phone: string, campaign: ?string, status: string, lastDisposition: ?string}
     */
    private function presentLead(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'phone' => $lead->phone,
            'campaign' => $lead->campaign?->name,
            'status' => $lead->status->label(),
            'lastDisposition' => $lead->lastDisposition?->label,
        ];
    }

    /**
     * The small display shape a callback row shows — shared by the agent's own
     * due-list (dueCallbacks) and the pooled list (pooledCallbacks), so both
     * panels render identically (the presentLead pattern, for callbacks).
     *
     * @return array{id: int, leadName: ?string, phone: string, campaign: ?string, scheduledAtIso: string, notes: ?string}
     */
    private function presentCallback(Callback $callback): array
    {
        return [
            'id' => $callback->id,
            'leadName' => $callback->lead?->name,
            'phone' => $callback->lead?->phone ?? '',
            'campaign' => $callback->campaign?->name,
            'scheduledAtIso' => $callback->scheduled_at->toIso8601String(),
            'notes' => $callback->notes,
        ];
    }

    /**
     * The dispositions the agent can pick in wrap-up: the matched lead's campaign
     * set plus the tenant-wide ones (reuses the C1/M4.D query, RLS-scoped). Driven
     * by the server-held campaign id, never a browser value.
     *
     * @return array<int, string>
     */
    public function dispositions(): array
    {
        return LeadForm::dispositionOptions($this->matchedCampaignId);
    }

    /**
     * The disposition ids in the matched lead's set that mean "schedule a callback"
     * (carry the CALLBACK code, M4). The browser uses these to reveal the date/time
     * + notes fields only when such an outcome is picked. Scoped exactly like
     * dispositionOptions (this campaign + tenant-wide), RLS-walled; the server
     * re-derives from the saved id on write, so this is a UI hint, never the wall.
     *
     * @return array<int, int>
     */
    public function callbackDispositionIds(): array
    {
        return Disposition::query()
            ->where('code', Disposition::CALLBACK_CODE)
            ->where(function ($query): void {
                $query->whereNull('campaign_id')->orWhere('campaign_id', $this->matchedCampaignId);
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Record the outcome of the call the agent just handled (B4 CP3 decision B+C).
     * Narrow by construction: gated by the dedicated record-call-outcome ability
     * (NOT Leads CRUD — agents still have none, D-M4-5); the lead is re-fetched
     * from the SERVER-HELD id in the agent's tenant context (RLS walls any
     * cross-tenant id to null); the picked disposition is re-validated against
     * that lead's own campaign set before a single column is written.
     *
     * Writes the last disposition, bumps attempts (one wrap-up = one answered
     * call), and nudges the funnel forward (C2-lite). The $lead->update() also
     * auto-logs a lead.updated diff; callWrappedUp() adds the call-stream event.
     *
     * When the picked disposition is the CALLBACK outcome (M4), a callback row is
     * created ON TOP of the normal write — picking Callback is additive, not an
     * alternative: the agent did reach the customer (CALLBACK is a contact), and
     * the row schedules the follow-up. The two writes share one transaction so a
     * lead is never advanced without its callback. scheduled_at is validated
     * (present + future) before either write.
     *
     * $poolCallback is the B2.0 capture choice (PC-1): false (default) keeps the
     * sticky v1 shape — owned by the wrapping agent; true creates it UNOWNED
     * (owner null) so it lands in the pooled list any free agent can grab. It only
     * applies to a CALLBACK outcome; it's ignored otherwise.
     */
    public function saveWrapUp(int $dispositionId, ?string $scheduledAt = null, ?string $notes = null, bool $poolCallback = false): void
    {
        Gate::authorize('record-call-outcome');

        $lead = Lead::find($this->matchedLeadId);

        // No server-held match (a no-match call, or a tampered/cross-tenant id RLS
        // hid) — nothing to record against a lead; fall back to ready.
        if ($lead === null) {
            $this->resetMatch();

            return;
        }

        abort_unless(
            array_key_exists($dispositionId, LeadForm::dispositionOptions($lead->campaign_id)),
            403,
        );

        $disposition = Disposition::findOrFail($dispositionId);

        $isCallback = $disposition->code === Disposition::CALLBACK_CODE;
        $callbackAt = $isCallback ? $this->validateCallbackSchedule($scheduledAt) : null;

        DB::transaction(function () use ($lead, $disposition, $isCallback, $callbackAt, $notes, $poolCallback): void {
            // B3 D2: the calls row is the PRIMARY write; the lead update + the
            // call.wrapped_up audit are now side-effects of it, same transaction.
            $this->recordCall($lead, $disposition);

            $lead->update([
                'last_disposition_id' => $disposition->id,
                'attempts' => $lead->attempts + 1,
                'status' => (new AdvanceLeadStatus)($lead->status, $disposition->is_contact),
            ]);

            Audit::callWrappedUp($lead, $disposition);

            // B2.0 PC-1: pooled = unowned (any agent grabs it); sticky = owned by
            // the agent wrapping up. The nullable owner column carries both shapes.
            if ($isCallback) {
                Callback::create([
                    'lead_id' => $lead->id,
                    'campaign_id' => $lead->campaign_id,
                    'scheduled_at' => $callbackAt,
                    'owner_agent_id' => $poolCallback ? null : auth()->id(),
                    'status' => CallbackStatus::Pending,
                    'notes' => filled($notes) ? $notes : null,
                ]);
            }
        });

        $this->resetMatch();
    }

    /**
     * Validate the callback schedule the browser sent: a date/time must be present
     * and in the future (a callback in the past is meaningless). The browser also
     * guards this, but the server is the wall. Parsed in the app timezone (UTC),
     * the same clock the due-list compares against.
     */
    private function validateCallbackSchedule(?string $scheduledAt): Carbon
    {
        abort_if($scheduledAt === null || trim($scheduledAt) === '', 422, 'A callback needs a date and time.');

        $when = rescue(fn (): Carbon => Carbon::parse($scheduledAt), null, false);

        abort_if($when === null, 422, 'That callback time is not valid.');
        abort_if($when->isPast(), 422, 'A callback must be scheduled in the future.');

        return $when;
    }

    /**
     * The no-match / ad-hoc wrap-up: write a lead-less calls row (B3 D2/D5) and log
     * the miss (so reporting can measure how often callers arrive unknown). No lead
     * is touched. outcome is null (no disposition picked) — the trunk-era watcher
     * fills the real line-result later. A DNC-blocked dial never reaches here.
     */
    public function completeUnmatched(): void
    {
        Gate::authorize('record-call-outcome');

        // B3 D2/D5: an ad-hoc / no-match call is still a real call — write a
        // lead-less row (no disposition → outcome null), with the audit miss as a
        // side-effect, one transaction. A DNC-blocked dial never reaches here, so
        // it correctly writes no row.
        DB::transaction(function (): void {
            $this->recordCall(null, null);

            Audit::callWrappedUp(null);
        });

        $this->resetMatch();
    }

    /**
     * Write the B3 calls-table row — the single writer (D2). Shared by the matched
     * wrap-up and the no-match/ad-hoc path. `agent_id` is the wrapping agent (web
     * auth); tenant_id is auto-stamped by BelongsToTenant. `ended_at` is COARSE in
     * v1 (= now, D4); precise timing + the recording arrive later via the listener.
     */
    private function recordCall(?Lead $lead, ?Disposition $disposition): void
    {
        $isOutbound = $this->callDirection === CallDirection::Outbound;
        $ourNumber = $isOutbound ? config('telephony.outbound.caller_id') : null;

        Call::create([
            'direction' => $this->callDirection,
            'from_number' => $isOutbound ? $ourNumber : $this->callPartyNumber,
            'to_number' => $isOutbound ? $this->callPartyNumber : $ourNumber,
            'lead_id' => $lead?->id,
            'campaign_id' => $lead?->campaign_id,
            'agent_id' => auth()->id(),
            'disposition_id' => $disposition?->id,
            'outcome' => $this->outcomeFor($disposition),
            'correlation_id' => $this->callCorrelationId,
            'ended_at' => now(),
        ]);
    }

    /**
     * The v1 (provisional) outcome — see the CallOutcome staging note. A contact
     * disposition means a human was reached (answered); a non-contact one (No
     * answer / Voicemail) means no_answer; no disposition (ad-hoc) leaves it null
     * for the trunk-era watcher to fill with the real line-result. The browser
     * `answered` flag is deliberately NOT used (it tracks the auto-answered agent
     * leg and is always true at wrap-up).
     */
    private function outcomeFor(?Disposition $disposition): ?CallOutcome
    {
        if ($disposition === null) {
            return null;
        }

        return $disposition->is_contact ? CallOutcome::Answered : CallOutcome::NoAnswer;
    }

    private function resetMatch(): void
    {
        $this->matchedLeadId = null;
        $this->matchedCampaignId = null;
        $this->callDirection = CallDirection::Inbound;
        $this->callPartyNumber = null;
        $this->callCorrelationId = null;
    }
}
