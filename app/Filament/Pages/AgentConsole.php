<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\AdvanceLeadStatus;
use App\Actions\SetAgentPresence;
use App\Audit\Audit;
use App\Enums\CallbackStatus;
use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Enums\LeadStatus;
use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Enums\ScriptType;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Filament\Support\CampaignCustomFields;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\BreakCategory;
use App\Models\Call;
use App\Models\Callback;
use App\Models\CallHandoff;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\DncEntry;
use App\Models\Lead;
// The model, aliased: App\Support\PhoneNumber (the number formatter) already owns the
// bare name in this file.
use App\Models\PhoneNumber as PhoneNumberRecord;
use App\Models\Script;
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
     * CH-2 — how many past calls the ring-time panel shows. Three, because an agent
     * reading a long table while a phone rings is reading nothing; the full history is
     * a supervisor's tool and lives on Call Review (CH-3).
     */
    private const HISTORY_CALLS = 3;

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
     *
     * BK-2: the board upsert and the stint close/open share one transaction, so the
     * board and its memory can never disagree. That transaction now lives in
     * SetAgentPresence — the single door, moved out in slice 5 so a supervisor forcing
     * a frozen screen offline (LB-15b) goes through the same one instead of a copy.
     * This method's job is what it always was: work out WHO (auth, never the browser)
     * and WHAT, then walk through the door.
     *
     * $breakCategoryId is the picker's break type (BK-3, browser-supplied): resolved
     * server-side to an ACTIVE category in the agent's own client — RLS walls foreign
     * ids, the is_active check drops deactivated ones — and anything unresolvable
     * degrades to an untyped break (null), never an error (BK-3's fallback).
     */
    #[Renderless]
    public function setPresence(string $status, ?int $breakCategoryId = null): void
    {
        $presence = PresenceStatus::tryFrom($status);

        abort_if($presence === null, 422, 'Unknown presence status.');

        $category = $this->resolveBreakCategory($presence, $breakCategoryId);

        SetAgentPresence::run((int) auth()->id(), $presence, $category);
    }

    /**
     * The break types the agent can pick from (BK-3's picker): the client's ACTIVE
     * categories in display order, each carrying its limit so the browser can run
     * the countdown client-side (from the break's start + this limit — no polling).
     * RLS + BelongsToTenant wall the list to the agent's own client. An empty list
     * means the client deactivated every type — the browser then skips the picker
     * and takes a plain untyped break (BK-3's fallback).
     *
     * @return array<int, array{id: int, label: string, limitMinutes: int|null}>
     */
    public function breakCategoryOptions(): array
    {
        return BreakCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (BreakCategory $category): array => [
                'id' => $category->id,
                'label' => $category->label,
                'limitMinutes' => $category->time_limit_minutes,
            ])
            ->all();
    }

    /**
     * BK-7 resume-on-return: the break to pick back up when the console loads, or
     * null. The industry pattern (Amazon Connect / Genesys): the screen asks the
     * server "what am I?" on load — it never assumes Ready. Resumable means BOTH
     * halves agree the break is still live: the agent's own board row reads OnBreak
     * through effectiveStatus() (fresh heartbeat — a stale row stays dead, the BK-6
     * doctrine: a new login after a dead session is a new stay), AND their open
     * break stint exists to anchor the clock. The stint supplies the ORIGINAL start
     * and the limit SNAPSHOT (BK-2 — the rule as it stood when the break began),
     * so the countdown resumes exactly where it left off. Untyped breaks resume
     * with a null category. Self-scoped by auth; tenant wall as everywhere.
     *
     * @return array{startedAtMs: int, category: array{id: int, label: string, limitMinutes: int|null}|null}|null
     */
    public function resumableBreak(): ?array
    {
        $presence = AgentPresence::query()->where('user_id', auth()->id())->first();

        if ($presence?->effectiveStatus() !== PresenceStatus::OnBreak) {
            return null;
        }

        $stint = AgentStatusHistory::query()
            ->open()
            ->where('user_id', auth()->id())
            ->where('status', PresenceStatus::OnBreak)
            ->latest('started_at')
            ->first();

        if ($stint === null) {
            return null;
        }

        return [
            'startedAtMs' => $stint->started_at->getTimestampMs(),
            'category' => $stint->break_category_id === null ? null : [
                'id' => $stint->break_category_id,
                'label' => $stint->breakCategory?->label ?? 'Break',
                'limitMinutes' => $stint->limit_minutes,
            ],
        ];
    }

    /**
     * Resolve the browser's break-type id to a real, ACTIVE category in the agent's
     * own client (BK-3). Only consulted for a break; a cross-client, deactivated or
     * nonsense id resolves to null — an untyped break, never a 4xx (the fallback BK-3
     * chose over trapping the agent).
     */
    private function resolveBreakCategory(PresenceStatus $status, ?int $breakCategoryId): ?BreakCategory
    {
        if ($status !== PresenceStatus::OnBreak || $breakCategoryId === null) {
            return null;
        }

        return BreakCategory::query()
            ->whereKey($breakCategoryId)
            ->where('is_active', true)
            ->first();
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

        // DIAL-1 DP-3a: the same tick re-stamps the claim on the lead this screen is
        // holding. A claim expires in 90 seconds (Lead::CLAIM_TTL_SECONDS) — right for
        // a dial that is still ringing, far too short for a conversation, so without
        // this a ten-minute call would be served to a second agent at second 91. The
        // browser already sends this every ~15s, so it costs no new timer and no new
        // round trip, and a closed laptop stops sending it — which is exactly when the
        // lead SHOULD be released.
        if ($this->matchedLeadId !== null) {
            Lead::whereKey($this->matchedLeadId)->update(['claimed_at' => now()]);
        }
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
     * @return array{id: int, name: ?string, phone: string, email: ?string, city: ?string, campaign: ?string, status: string, lastDisposition: ?string, customFields: array<string, mixed>}|null
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
     * CH-2 — "have we dealt with this number before, and how did it go?", answered
     * beside the live call: any callback we still owe this person, then the last three
     * calls to or from their number.
     *
     * 🔴 Reads the SERVER-HELD number, never one the browser sends. `callPartyNumber`
     * is already set on every call path — the normalized caller-ID on an inbound ring
     * (lookupLead) and the normalized dialled number on every outbound dial (served
     * lead, callback and ad-hoc all route through originateAgentLeg) — and cleared
     * between calls by resetMatch, so this panel can no more inherit the previous
     * caller's history than the lead match can (decision C, extended). A number posted
     * from the browser would be a lookup taking dictation from the client.
     *
     * Keyed on the number rather than the matched lead (CH-1), which is the whole point
     * of the slice: an ad-hoc dial to a stranger leaves a calls row today, so the SECOND
     * call to that number has a history even though nobody ever created a customer for
     * them. Walled to the agent's client by RLS, like every other read here.
     *
     * Callbacks are lead-keyed and stay that way (CH-4): `callbacks.lead_id` is NOT NULL,
     * so a number nobody has saved has no callback to show — by construction, not by
     * omission. No match, no callback block at all.
     *
     * The browser calls this over $wire once a number is in hand, and reads the RETURN
     * VALUE into Alpine state — never Blade, for the reason the panel's comment gives.
     * A call with no number yet (page load, or an anonymous caller) short-circuits
     * before touching the database. Times ride out as
     * UTC ISO strings so the browser prints them in the AGENT's own clock, the same way
     * the callback lists already do.
     *
     * CF-2: it also answers with `fields` — the client's OWN boxes for this call's campaign
     * (CF-1's chain). This method rather than a new one, because it already fires at exactly
     * the two right moments (an inbound ring and every outbound dial), already reads the
     * server-held number the chain needs, and already returns a value rather than relying on
     * a redraw — the one mechanism proven to survive the S120b bug in this component. The
     * accepted cost is the name: this is now "what we know about this number", not just its
     * history. Renaming it touches more than living with it does.
     *
     * @return array{calls: array<int, array{whenIso: string, direction: string, talked: string, outcome: ?string, agent: ?string}>, callbacks: array<int, array{scheduledAtIso: string, notes: ?string}>, fields: array<int, array{key: string, label: string, type: string, required: bool, options: array<int, string>}>}
     */
    public function callHistory(): array
    {
        if ($this->callPartyNumber === null) {
            return ['calls' => [], 'callbacks' => [], 'fields' => [], 'scripts' => []];
        }

        $calls = Call::query()
            ->forCustomerNumber($this->callPartyNumber)
            ->with('agent')
            ->limit(self::HISTORY_CALLS)
            ->get()
            ->map(fn (Call $call): array => [
                'whenIso' => $call->created_at->toIso8601String(),
                'direction' => $call->direction->label(),
                'talked' => Call::asClock($call->talkedSeconds()),
                'outcome' => $call->outcome?->label(),
                'agent' => $call->agent?->name,
                // CP-5: what the last agent wrote. The single most useful thing on this
                // panel — the outcome says the call was a callback, the note says why.
                'notes' => $call->notes,
            ])
            ->all();

        $callbacks = $this->matchedLeadId === null ? [] : Callback::query()
            ->where('lead_id', $this->matchedLeadId)
            ->where('status', CallbackStatus::Pending)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Callback $callback): array => [
                'scheduledAtIso' => $callback->scheduled_at->toIso8601String(),
                'notes' => $callback->notes,
            ])
            ->all();

        // CF-1's chain, resolved ONCE. The client's boxes and the words the agent says
        // belong to the same campaign by definition, and the fallback branch is a query.
        $campaignId = $this->campaignForThisCall();

        return [
            'calls' => $calls,
            'callbacks' => $callbacks,
            // CF-2: the definitions only. The VALUES for a known caller ride out on
            // presentLead(), the same way name, email and city already do.
            'fields' => CampaignCustomFields::definitionsForBrowser($campaignId),
            // N2: the words the agent says on this call.
            'scripts' => $this->scriptsForCampaign($campaignId),
        ];
    }

    /**
     * CP-3 — "save what we just learned about this caller", from the live-call screen.
     *
     * A2 put this beside the call rather than in wrap-up: the form is in front of the
     * agent for the whole call, and who hangs up decides whether they ever get back to
     * it. A3 makes every field optional — the Default CRM never blocks a wrap-up.
     *
     * 🔴 The phone is the SERVER-HELD `callPartyNumber`, never a number the browser
     * sends, for the same reason callHistory reads it: a customer write that takes its
     * key from the client is a write that can be pointed at anyone. It is also why no
     * lead id appears in the signature — a caller we already know is updated through
     * the match the server itself made at ring or dial time.
     *
     * An upsert in behaviour rather than in SQL, and it looks the row up BY NUMBER
     * rather than by `matchedLeadId`. The number is the unique key per client, so it
     * is the right key here; keying on the held match instead would create a second
     * row and hit `leads_tenant_id_phone_unique` any time a call path reached this
     * form without having matched first — which is exactly what an ad-hoc dial did
     * until it learned to match.
     *
     * A new row needs a campaign (`leads.campaign_id` is NOT NULL) and an inbound
     * stranger has none — CP-4/A8 files them under the client's own "Inbound"
     * campaign, found-or-created on first use.
     *
     * Only filled values are written, so a blank box leaves what is already stored
     * alone. That means this form cannot CLEAR a field; clearing belongs on the
     * customer page, not on a live call.
     *
     * Saving mid-call also hands the wrap-up a disposition set it did not have: the
     * picker reads the matched campaign, and until now an ad-hoc call had no match and
     * therefore no outcome to record.
     *
     * Returns the same shape lookupLead does, so the browser swaps it straight into the
     * lead card. NOT renderless — and read as a RETURN VALUE rather than a render, for
     * the reason the history panel's comment in the blade sets out.
     *
     * @return array{id: int, name: ?string, phone: string, email: ?string, city: ?string, campaign: ?string, status: string, lastDisposition: ?string, customFields: array<string, mixed>}
     */
    public function saveCustomer(?string $name = null, ?string $email = null, ?string $city = null, array $customFields = []): array
    {
        Gate::authorize('save-customer');

        abort_if($this->callPartyNumber === null, 422, 'There is no caller to save.');

        $fields = validator(
            ['name' => $name, 'email' => $email, 'city' => $city],
            [
                'name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'city' => ['nullable', 'string', 'max:255'],
            ],
        )->validate();

        $fields = array_filter($fields, fn (?string $value): bool => filled($value));

        $custom = $this->validatedCustomFields($customFields);

        // CF-3: the guard has to count the client's boxes too. An agent who filled only
        // Policy Number was refused here for no reason — all three standard boxes empty
        // read as "nothing typed" when in fact the only box that mattered was full.
        abort_if($fields === [] && $custom === [], 422, 'Fill in at least one detail before saving.');

        $lead = Lead::query()->where('phone', $this->callPartyNumber)->first();

        if ($lead === null) {
            $lead = Lead::create([
                ...$fields,
                'phone' => $this->callPartyNumber,
                // F6: was always the generic Inbound bucket, which disagreed with the call
                // row `recordCall()` writes for the same caller (:1323) — Ravi rings Acme's
                // insurance line and we filed the CALL under Insurance Renewals and the
                // PERSON under Inbound. Same chain on both sides now.
                // The found-or-create belongs HERE and not in the chain: `leads.campaign_id`
                // is NOT NULL so this one caller needs the row to exist, while the read path
                // must not write. Reached only when the client has no bucket yet.
                'campaign_id' => $this->campaignForThisCall() ?? Campaign::inboundFallback()->id,
                'status' => LeadStatus::New,
                'custom_fields' => $custom,
            ]);
        } else {
            // 🔴 CF-3: MERGE, never replace. Meera has POL-4471 stored and the agent only
            // changes her Plan; her policy number must survive whatever the browser did or
            // did not send. One rendering failure, one box added to the campaign an hour
            // ago, one type we draw badly — and a replace loses a stored value silently.
            //
            // It also matches the three boxes sitting next to it, which already drop blanks
            // (the array_filter above), so blanking a box never wipes what is stored. Two
            // different rules on one form is how agents stop trusting a screen.
            //
            // ponytail: the cost is that this form cannot CLEAR a box — correcting a value
            // means typing the right one, and clearing entirely happens on the Leads screen.
            // Already true of name, email and city. Upgrade path if a client asks: an
            // explicit clear control per box.
            $lead->update([
                ...$fields,
                'custom_fields' => array_merge($lead->custom_fields ?? [], $custom),
            ]);
        }

        $this->matchedLeadId = $lead->id;
        $this->matchedCampaignId = $lead->campaign_id;

        return $this->presentLead($lead->load(['campaign', 'lastDisposition']));
    }

    /**
     * The client's own boxes as the console is allowed to store them (CF-3), shared by
     * the mid-call save and the wrap-up save (CF-6) so the two can never diverge on what
     * they accept.
     *
     * CF-1: validated against the SAME campaign the boxes were drawn from, so a value can
     * only be stored against the definitions the agent was actually shown.
     *
     * 🔴 And this one is not a preference. The browser sends box NAMES beside the values,
     * so without an allow-list a tampered browser pushes arbitrary keys into a customer's
     * stored JSON. The allow-list IS the rule set: `validate()` answers with the validated
     * attributes only, so a name the campaign never defined has no rule, is never returned,
     * and never reaches the write. Silently, the posture LeadsImport already takes with a
     * column nobody defined.
     *
     * An explicit array_intersect_key on the same keys was written here first and then
     * removed: it changed nothing, and the tests stay green either way because they assert
     * what was STORED rather than how it got filtered.
     *
     * Blanks are dropped, so an empty box never wipes what is already stored — the same
     * rule the three standard boxes beside it follow.
     *
     * @param  array<string, mixed>  $customFields
     * @return array<string, mixed>
     */
    private function validatedCustomFields(array $customFields): array
    {
        $definitions = CampaignCustomFields::definitionsForBrowser($this->campaignForThisCall());

        return array_filter(
            validator($customFields, CampaignCustomFields::rules($definitions))->validate(),
            fn (mixed $value): bool => filled($value),
        );
    }

    /**
     * Claim the call's ticket at ring-time (B2.4b TH-2/TH-3): read the most-recent
     * handoff note the listener left for THIS agent (on the call_handoffs drawer) and
     * hold its ticket as the call's correlation id, so the wrap-up stamps it on the
     * `calls` row and the inbound recording attaches by matching ids (closing the
     * inbound recording-attach gap). The browser calls this over $wire on EVERY inbound
     * ring — including the anonymous early-return branch — decoupled from lookupLead so
     * an anonymous caller still attaches (Finding A).
     *
     * Keyed by the logged-in agent in the request's own tenant context, so it only ever
     * reads its own client's notes (RLS + BelongsToTenant wall it, like lookupLead). The
     * most-recent note (by id) is unambiguous because the listener prunes the agent's
     * prior note on each new ring (TH-6); the read is plain (non-consuming) and the note
     * lingers harmlessly until the next call sweeps it.
     *
     * Option A — the claim OWNS callCorrelationId: a single assignment resets-and-sets it
     * (the ticket, or null on a miss), so a miss degrades gracefully (the row is written
     * null, the recording stays orphaned on disk, never the WRONG recording attached) and
     * the lead lookup can never clobber a just-claimed ticket (callCorrelationId was pulled
     * out of resetMatch). Renderless: it fires mid-ring and writes only server state, so it
     * must not morph the live console.
     */
    #[Renderless]
    public function claimHandoffTicket(): ?string
    {
        $note = CallHandoff::query()
            ->where('agent_user_id', auth()->id())
            ->latest('id')
            ->first();

        $this->callCorrelationId = $note?->ticket;

        // inbound-audio AU-25. Handed BACK rather than set as a property, because this
        // method is renderless on purpose — it fires mid-ring and must not morph the live
        // console, so a property would never reach the screen. Null on every call that
        // met no menu, and the card hides the line entirely for those.
        return $note?->menu_choice;
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
     * @return array{id: int, name: ?string, phone: string, email: ?string, city: ?string, campaign: ?string, status: string, lastDisposition: ?string, customFields: array<string, mixed>}|null
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
     *  - 'nophone'          : this agent holds no phone of their own (SEC-1 PP-12).
     *
     * @return array{outcome: 'dialed', lead: array{id: int, name: ?string, phone: string, email: ?string, city: ?string, campaign: ?string, status: string, lastDisposition: ?string, customFields: array<string, mixed>}}|array{outcome: 'blocked', phone: string}|array{outcome: 'none'}|array{outcome: 'nophone'}
     */
    public function dial(): array
    {
        $agentEndpoint = $this->ownEndpoint();

        if ($agentEndpoint === null) {
            return ['outcome' => 'nophone'];
        }

        $lead = $this->claimNextCallableLead();

        if ($lead === null) {
            return ['outcome' => 'none'];
        }

        if (DncEntry::blocks($lead->phone)) {
            return $this->blockServedLead($lead);
        }

        $this->matchedLeadId = $lead->id;
        $this->matchedCampaignId = $lead->campaign_id;

        $this->originateAgentLeg($lead->phone, $agentEndpoint);

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
     * 🔴 The phone guard comes FIRST, before the callback is consumed: refusing after
     * the flip to done would drop a due callback off the list for a call that never
     * happened.
     *
     * @return array{outcome: 'dialed', lead: array{id: int, name: ?string, phone: string, email: ?string, city: ?string, campaign: ?string, status: string, lastDisposition: ?string, customFields: array<string, mixed>}}|array{outcome: 'blocked', phone: string}|array{outcome: 'none'}|array{outcome: 'nophone'}
     */
    public function dialCallback(int $callbackId): array
    {
        $agentEndpoint = $this->ownEndpoint();

        if ($agentEndpoint === null) {
            return ['outcome' => 'nophone'];
        }

        $callback = Callback::query()
            ->with('lead')
            ->where('owner_agent_id', auth()->id())
            ->where('status', CallbackStatus::Pending)
            ->find($callbackId);

        if ($callback === null || $callback->lead === null) {
            return ['outcome' => 'none'];
        }

        $lead = $callback->lead;

        if (DncEntry::blocks($lead->phone)) {
            return $this->blockCallback($callback, $lead);
        }

        // Consume the callback: dialing it drops it off the due-list.
        $callback->update(['status' => CallbackStatus::Done]);

        $this->matchedLeadId = $lead->id;
        $this->matchedCampaignId = $lead->campaign_id;

        $this->originateAgentLeg($lead->phone, $agentEndpoint);

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
     * @return array{outcome: 'dialed'|'blocked', phone: string}|array{outcome: 'invalid'}|array{outcome: 'nophone'}
     */
    public function dialAdhoc(string $number): array
    {
        Gate::authorize('dial-adhoc');

        $agentEndpoint = $this->ownEndpoint();

        if ($agentEndpoint === null) {
            return ['outcome' => 'nophone'];
        }

        $phone = PhoneNumber::normalize($number);

        if ($phone === null) {
            return ['outcome' => 'invalid'];
        }

        // Clear any held match first, so this dial can never inherit the previous
        // call's customer (decision C). Re-matched below, once the number is known.
        $this->resetMatch();

        if (DncEntry::blocks($phone)) {
            Audit::dncBlocked(null, $phone);

            return ['outcome' => 'blocked', 'phone' => $phone];
        }

        $this->originateAgentLeg($phone, $agentEndpoint);

        // 🔴 A typed number is NOT automatically a stranger, and used to be treated
        // as one. CP-3 lets an agent save a customer in the middle of an ad-hoc call,
        // so the SECOND dial to that number is a dial to somebody we hold — and
        // without this the card showed a bare number, the customer form came back
        // empty, and the wrap-up offered no outcome to record. Matched on the server
        // for the same reason lookupLead is: the browser gets a lead to show, never a
        // lead id to send back.
        $lead = Lead::query()
            ->with(['campaign', 'lastDisposition'])
            ->where('phone', $phone)
            ->first();

        if ($lead === null) {
            return ['outcome' => 'dialed', 'phone' => $phone];
        }

        $this->matchedLeadId = $lead->id;
        $this->matchedCampaignId = $lead->campaign_id;

        return ['outcome' => 'dialed', 'phone' => $phone, 'lead' => $this->presentLead($lead)];
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
     *
     * The rule itself now lives on the model (Lead::callable, DIAL-1 DP-1) because
     * the dialer has to ask the same question and cannot call a private method on a
     * Livewire page. What stays here is what is genuinely the console's: its selected
     * campaign, its per-session skip list, and the eager loads the lead card needs.
     *
     * @param  array<int, int>  $alsoExclude  leads THIS call has already passed on
     */
    private function nextCallableLead(array $alsoExclude = []): ?Lead
    {
        if ($this->selectedCampaignId === null) {
            return null;
        }

        return Lead::callable($this->selectedCampaignId, [...$this->skippedLeadIds, ...$alsoExclude])
            ->with(['campaign', 'lastDisposition'])
            ->first();
    }

    /**
     * The next callable lead this agent actually HOLDS — serve, claim, and if
     * somebody beat us to it, move to the next one (DIAL-1 DP-3a).
     *
     * Claiming happens here, at dial, and deliberately not in servedLead(): the
     * preview polls, so claiming on display would let an agent who is only looking
     * at the screen sit on a lead nobody can work.
     *
     * A lost claim adds that lead to this call's own exclude list rather than
     * trusting the next serve to skip it. Same result while the two queries agree,
     * but termination is then structural: each pass drops one lead, so the worst
     * case walks the campaign once. Leaving it to the serving rule spins on a live
     * CPU for as long as the claim lasts the day those two ever disagree.
     */
    private function claimNextCallableLead(): ?Lead
    {
        $lost = [];

        while (($lead = $this->nextCallableLead($lost)) !== null) {
            if ($lead->claim()) {
                return $lead;
            }

            $lost[] = $lead->id;
        }

        return null;
    }

    /**
     * This agent's OWN dial endpoint, or null when they hold no phone (SEC-1 PP-12).
     *
     * Every dial entry point asks this before it changes anything, because the answer
     * decides whether a call can happen at all. It used to be unaskable: the directory
     * fell back to one shared extension, so a phoneless agent's outbound leg rang
     * agent 1003's desk instead of theirs. Now it is null, and a null refusal the
     * agent can read beats a TypeError in the listener.
     */
    private function ownEndpoint(): ?string
    {
        return app(AgentDirectory::class)->endpointFor((int) auth()->id());
    }

    /**
     * Originate the AGENT leg first (agent-first), carrying the customer number as
     * the leg's tag detail so the flow reads it back and dials the customer
     * (CP-O0 transport). Shared by the served-lead dial and the ad-hoc dial — the
     * only difference between them is where the number comes from.
     *
     * The endpoint arrives already resolved, as a plain string: its caller had to ask
     * for it anyway to decide whether to dial at all, and taking it here rather than
     * looking it up again is what keeps a null out of `placeCall()`.
     */
    private function originateAgentLeg(string $customerNumber, string $agentEndpoint): void
    {
        // The single outbound dial path (served lead, callback, ad-hoc all route
        // here) — so it's the one place that stamps the B3 call as Outbound,
        // records the dialled number, and mints the call's tracking number (D3)
        // for the wrap-up row + the recording attach.
        $this->callDirection = CallDirection::Outbound;
        $this->callPartyNumber = $customerNumber;
        $this->callCorrelationId = (string) Str::uuid();

        // Call Stats CS-4: say which client this call is for. An outbound call used to
        // belong to nobody — the listener only ever learned the client from an INBOUND
        // call's dialled number — so the live call counts would have quietly dropped
        // every outbound call in progress. This console is already sitting in one client,
        // so it simply says which, as one more ordered value on the leg's label. Nothing
        // is looked up: working it out afterwards from the person who dialled would be a
        // guess, because someone can belong to more than one client.
        //
        // Our own global staff dial with no client in scope; they send nothing and their
        // calls stay uncounted, which is honest rather than guessed.
        $tagDetails = [$customerNumber, $this->callCorrelationId, (string) auth()->id()];
        $tenantId = TenantContext::id();

        if ($tenantId !== null) {
            $tagDetails[] = (string) $tenantId;
        }

        app(TelephonyProvider::class)->placeCall(
            // Ring the LOGGED-IN agent's OWN phone (B2.2b Fold A directory), not the one
            // fixed endpoint — so a 2nd agent's outbound leg rings their own desk (closes
            // the §7 outbound-per-agent gap). SEC-1 slice 4: it comes from their own
            // `users.sip_extension`, and an agent without one is refused at the door
            // above rather than silently ringing somebody else's phone. With the agent
            // id threaded below, an outbound call is fully transferable too.
            $agentEndpoint,
            'agent',
            // B2.4a (TD-4 fold): thread the dialing agent's user id onto the agent leg
            // (the 4th ordered tag value) so the handler retains WHO is serving and an
            // OUTBOUND call can be transferred too — the transfer signal finds the
            // handler by it. Backwards-compatible: the flow's >= 2 arg guard tolerates
            // the extra values, and CP-B2.4a demos the inbound path either way.
            tagDetails: $tagDetails,
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
     * Conference a free agent into the live call (B2.4b CD-3/CD-6). The browser calls
     * this over $wire when the agent clicks Conference mid-call: it POSTs the same
     * control signal as a transfer, only named 'conference', carrying the agent's own
     * user id (the correlator — they are on exactly one call) and the server-derived
     * tenant id. The listener finds this agent's live call, reserves a free agent, and
     * rings them while the caller keeps talking; on answer it ADDS them without dropping
     * the current agent — caller + A + B all talking. The ENTIRE difference from
     * transferCall is the signal name.
     *
     * Renderless: it fires mid-call and the agent STAYS on the (now 3-way) call, so it
     * must not morph the live console — the screen drives its own non-blocking "ringing
     * to join…" indicator and no-answer timeout (CD-6).
     */
    #[Renderless]
    public function conferenceCall(): void
    {
        $tenantId = TenantContext::id();

        // No tenant context (a global-staff demo session, not a tenant agent) -> nothing
        // to reserve against; the reserve is tenant-scoped (TD-6), so there is no call.
        if ($tenantId === null) {
            return;
        }

        app(TelephonyProvider::class)->signal('conference', [
            'agentUserId' => (string) auth()->id(),
            'tenantId' => (string) $tenantId,
        ]);
    }

    /**
     * Park the live caller (hold.md H-10): the caller leaves the conversation, hears
     * music, and the agent keeps their own line. The browser calls this over $wire when
     * the agent presses Hold; it POSTs the same kind of control signal as Transfer and
     * Conference, only named 'hold', carrying the agent's own user id — they are on
     * exactly one call, which is all the always-on program needs to find it.
     *
     * No company on this one, unlike Transfer and Conference: nothing is reserved, so
     * there is no board to read. Requiring one would refuse a hold to our own global
     * staff on an outbound call, who have no client in scope and are entitled to it.
     *
     * Renderless: it fires mid-call, so it must not morph the live console. The screen
     * flips its own button and is not told whether the hold landed — the agent HEARS it,
     * because the caller goes quiet the instant it does.
     */
    #[Renderless]
    public function holdCall(): void
    {
        app(TelephonyProvider::class)->signal('hold', ['agentUserId' => (string) auth()->id()]);
    }

    /** The other half of the toggle (H-10): put the caller back into the conversation. */
    #[Renderless]
    public function resumeCall(): void
    {
        app(TelephonyProvider::class)->signal('resume', ['agentUserId' => (string) auth()->id()]);
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
     * CP-3 adds email and city: the live-call form prefills from this shape, so a
     * caller we already know sees what we already hold rather than a blank box that
     * invites the agent to ask them the same question twice.
     *
     * @return array{id: int, name: ?string, phone: string, email: ?string, city: ?string, campaign: ?string, status: string, lastDisposition: ?string, customFields: array<string, mixed>}
     */
    private function presentLead(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'city' => $lead->city,
            'campaign' => $lead->campaign?->name,
            'status' => $lead->status->label(),
            'lastDisposition' => $lead->lastDisposition?->label,
            // CF-8: what is already stored in the client's own boxes, so they prefill for a
            // known caller — the same reason name, email and city do (CP-3). The DEFINITIONS
            // of those boxes come separately, on callHistory() (CF-2): definitions belong to
            // the campaign and change per call, values belong to the person.
            'customFields' => $lead->custom_fields ?? [],
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
     *
     * CP-5: $callNotes is the agent's note about THIS CALL and is deliberately not
     * the same argument as $notes, which is the callback's own "why to ring back"
     * line and only exists on a CALLBACK outcome. One is about the conversation
     * that happened, the other about the one still owed.
     *
     * CF-6: $customFields carries the client's own boxes from the wrap-up screen, so a
     * value the agent typed mid-call and never pressed "Save customer" for is still
     * filed. Merged onto the customer inside the same transaction as the call row —
     * and BEFORE CF-4's must-fill check, so the agent is never refused while staring
     * at the value they just typed.
     *
     * 🔴 CF-4 returns its refusal as a STRING rather than throwing. F7 left a
     * `ponytail:` note asking for "a reply shape the browser can read", and this is it:
     * null on success, the reason on a refusal. A 422 would reach the browser as a
     * Livewire error and the agent would see a fixed line that names no box. Reading a
     * RETURN VALUE is also what every other panel on this screen already does, for the
     * S120b reason — nothing here is ever rendered server-side.
     *
     * A blank must-fill box is ordinary, expected, and the agent's to fix, so it is not
     * an abort. The `abort_if`s left in this method are the tamper and impossible-state
     * cases, which is what they are for.
     *
     * @param  array<string, mixed>  $customFields
     * @return string|null null when saved; the reason to show the agent when refused.
     */
    public function saveWrapUp(int $dispositionId, ?string $scheduledAt = null, ?string $notes = null, bool $poolCallback = false, ?string $callNotes = null, array $customFields = []): ?string
    {
        Gate::authorize('record-call-outcome');

        $lead = Lead::find($this->matchedLeadId);

        // No server-held match (a no-match call, or a tampered/cross-tenant id RLS
        // hid) — nothing to record against a lead; fall back to ready.
        if ($lead === null) {
            $this->resetMatch();

            return null;
        }

        abort_unless(
            array_key_exists($dispositionId, LeadForm::dispositionOptions($lead->campaign_id)),
            403,
        );

        $disposition = Disposition::findOrFail($dispositionId);

        $isCallback = $disposition->code === Disposition::CALLBACK_CODE;
        $callbackAt = $isCallback ? $this->validateCallbackSchedule($scheduledAt) : null;

        $custom = $this->validatedCustomFields($customFields);

        // CF-4: a must-fill box blocks only when the agent actually REACHED a person.
        // Nobody needs a policy number off a voicemail, and every mandatory field is a
        // direct charge on handle time multiplied by call volume — so the blocking
        // surface stays as small as it can be. Gating the requirement on state rather
        // than shipping a bypass is the researched shape: Zendesk gates on ticket status
        // ("required to solve"), Amazon Connect on a field condition.
        //
        // CP-10 narrows it a second time, INSIDE this gate: a box can name the outcomes
        // it is required for, so "Customer informed" asks for nothing. This gate stays
        // put and stays outermost — DF-4 keeps non-contact outcomes out of the checklist
        // entirely, so a box can never name one and this is a wall, not a duplicate.
        //
        // 🔴 Checked BEFORE the transaction, on the values the wrap-up MERGED IN MEMORY
        // (stored ∪ just sent), not on what is stored. A number the agent typed mid-call
        // and never pressed "Save customer" for must satisfy this — otherwise CF-6's
        // whole point is lost and the agent is refused while staring at the value.
        // Refusing here writes nothing at all: no calls row, no lead update. The typed
        // values are still on the agent's screen (F7), so nothing is lost by not writing.
        if ($disposition->is_contact) {
            $missing = $this->missingRequiredBoxes($lead, $disposition, $custom);

            if ($missing !== []) {
                return implode(', ', $missing).' must be filled before you can finish this call.';
            }
        }

        DB::transaction(function () use ($lead, $disposition, $isCallback, $callbackAt, $notes, $poolCallback, $callNotes, $custom): void {
            // B3 D2: the calls row is the PRIMARY write; the lead update + the
            // call.wrapped_up audit are now side-effects of it, same transaction.
            $this->recordCall($lead, $disposition, $callNotes);

            $lead->update([
                'last_disposition_id' => $disposition->id,
                'attempts' => $lead->attempts + 1,
                // DIAL-1 DP-3: the call is over, so the lead goes back in the pool.
                // Its claim would expire on its own; releasing it here is what makes
                // a re-dial immediate rather than 90 seconds away.
                'claimed_at' => null,
                'status' => (new AdvanceLeadStatus)($lead->status, $disposition->is_contact),
                // CF-6: MERGE, for the same reason saveCustomer merges — a box this
                // screen never drew, or one added to the campaign an hour ago, must not
                // be wiped by a save it was not part of. No second write: the lead row
                // was already being updated here.
                'custom_fields' => array_merge($lead->custom_fields ?? [], $custom),
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

        return null;
    }

    /**
     * CF-4 + CP-10 — the client's must-fill boxes still blank for this customer AND
     * required for the outcome just picked, by LABEL.
     *
     * Labels, not keys: the agent is told "Policy Number", which is what the box on their
     * screen says, never `policy_number`.
     *
     * Read against the merge of what is stored and what this wrap-up is carrying, so a
     * value typed mid-call satisfies the requirement even though "Save customer" was
     * never pressed. Same campaign the boxes were drawn from (CF-1), so the agent can
     * only be blocked by a box they were actually shown.
     *
     * 🔴 DF-1 — an empty `requiredOn` means required on EVERY contact outcome, which is
     * what a bare must-fill tick has always meant. Every campaign configured before CP-10
     * has one, so this branch is the reason nobody's floor changes on deploy day. It also
     * means the list can only ever make the rule smaller: nothing here can newly block a
     * call that passes today.
     *
     * 🔴 DF-2 — compared against the disposition the SERVER loaded, never a browser value.
     * A `requiredOn` id is a raw number inside JSON and is walled by nothing on its own;
     * this comparison is what keeps another tenant's id from ever matching. An id for a
     * deleted outcome matches nothing and so blocks nothing (T5) — deleting an in-use
     * outcome already nulls it on every historical call, so this is the smallest part of
     * that damage, and a delete guard belongs on DispositionResource, not here.
     *
     * @param  array<string, mixed>  $custom
     * @return array<int, string>
     */
    private function missingRequiredBoxes(Lead $lead, Disposition $disposition, array $custom): array
    {
        $values = array_merge($lead->custom_fields ?? [], $custom);

        return collect(CampaignCustomFields::definitionsForBrowser($this->campaignForThisCall()))
            ->filter(fn (array $definition): bool => $definition['required']
                && ($definition['requiredOn'] === [] || in_array($disposition->id, $definition['requiredOn'], true))
                && blank($values[$definition['key']] ?? null))
            ->pluck('label')
            ->all();
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
    public function completeUnmatched(?string $callNotes = null): void
    {
        Gate::authorize('record-call-outcome');

        // B3 D2/D5: an ad-hoc / no-match call is still a real call — write a
        // lead-less row (no disposition → outcome null), with the audit miss as a
        // side-effect, one transaction. A DNC-blocked dial never reaches here, so
        // it correctly writes no row.
        DB::transaction(function () use ($callNotes): void {
            $this->recordCall(null, null, $callNotes);

            Audit::callWrappedUp(null);
        });

        $this->resetMatch();
    }

    /**
     * The four moments the listener left for THIS call, or null if there is no note
     * (call-timing.md CT-3). The listener owns every clock on a call but cannot write
     * the row (D2's single-writer rule), so it stamps its moments onto the handoff note
     * and this read copies them across at wrap-up — one clock, so the arithmetic
     * between them can never disagree.
     *
     * 🔴 Matched on the TICKET this console is holding, not on "my newest note" (CT-11).
     * A note lingers between calls: an agent whose phone rang out is still holding that
     * caller's arrival time, and a newest-note read would stamp an inbound wait onto
     * their next outbound row. Matched on the agent too, so on a conference each of the
     * two agents reads their own part rather than their colleague's.
     *
     * An outbound call mints its own ticket at the Dial click and the listener files a
     * note under it too (CT-16), so it gets a ring, a pickup and a hang-up — but never an
     * arrival, because nobody waited: we placed the call. CT-8 ("outbound carries no
     * wait") therefore still holds by construction, with no direction check anywhere.
     *
     * A miss is normal and harmless (TH-2): the note write is best-effort, so a database
     * hiccup mid-call costs this row its timing and nothing else.
     */
    private function handoffMoments(): ?CallHandoff
    {
        if ($this->callCorrelationId === null) {
            return null;
        }

        return CallHandoff::query()
            ->where('ticket', $this->callCorrelationId)
            ->where('agent_user_id', auth()->id())
            ->first();
    }

    /**
     * The recording already filed on another row of THIS call, if there is one (CT-15).
     *
     * 🔴 The ordering this exists for, and it is the ordinary one on every transfer:
     * Priya transfers the caller to Rahul and clicks Done thirty seconds later, so her
     * row exists. Rahul talks for three more minutes. The caller hangs up, the audio
     * merges a second or two later, and the filing job runs — while Rahul is still
     * typing. HIS ROW DOES NOT EXIST YET. The job files onto Priya's row, succeeds, and
     * never runs again, because it only retries when it finds no row at all. So CT-13's
     * "update every matching row" updates every row that exists at that instant, and
     * Rahul's is minutes away.
     *
     * Back-filling here closes it from the other end: whichever row is written second
     * picks the audio up from the first. Every ordering is then covered — job first,
     * both rows first, or neither row yet (the job's own retry handles that one).
     *
     * Indexed on correlation_id, so this is one cheap lookup on the wrap-up path.
     */
    private function siblingRecording(): ?Call
    {
        if ($this->callCorrelationId === null) {
            return null;
        }

        return Call::query()
            ->where('correlation_id', $this->callCorrelationId)
            ->whereNotNull('recording_path')
            ->first();
    }

    /**
     * Write the B3 calls-table row — the single writer (D2). Shared by the matched
     * wrap-up and the no-match/ad-hoc path. `agent_id` is the wrapping agent (web
     * auth); tenant_id is auto-stamped by BelongsToTenant.
     *
     * The five moments (CT-2): four copied off the listener's note, and the fifth is
     * this row's own `created_at` — the Done click, which we were already storing
     * without noticing. Any of them may be null, and a null STAYS null (CT-6): if the
     * hang-up has not landed by the time Done is clicked, `ended_at` is blank rather
     * than falling back to now(), because that fallback silently reinstates the
     * wrap-up-inflated "duration" this slice exists to remove. A dash on one row is
     * honest; a plausible wrong number is not (B3 D4).
     */
    private function recordCall(?Lead $lead, ?Disposition $disposition, ?string $callNotes = null): void
    {
        // CP-5: validated HERE rather than in the two callers, because this is the one
        // place every call row is written — matched wrap-up and no-match Done alike.
        $callNotes = validator(
            ['notes' => $callNotes],
            ['notes' => ['nullable', 'string', 'max:2000']],
        )->validate()['notes'];

        $moments = $this->handoffMoments();
        $sibling = $this->siblingRecording();

        // 🔴 WHICH WAY ROUND THIS CALL WENT (DIAL-1 F15). `callDirection` says Outbound
        // only when THIS agent dialled (originateAgentLeg). A call the progressive dialer
        // placed arrives here as a ring and a screen pop — identical to an inbound caller
        // from the console's side — so it left the default alone and every answered dial
        // was filed as a call the customer made to US. The listener knows who placed it
        // and now says so on the handoff note, which is ticket-matched to THIS call.
        //
        // Direction is not cosmetic: every reader picks the customer's side of a call off
        // it (Call::forCustomer, the export's `customer`, the calls list), and it is what
        // an outbound-volume or abandoned-rate report counts. The listener's own abandon
        // row already writes Outbound on a dialled call — filing the answered ones as
        // inbound would leave the two halves of one campaign disagreeing.
        $agentDialledIt = $this->callDirection === CallDirection::Outbound;
        $isOutbound = $agentDialledIt || (bool) $moments?->was_dialled;
        $direction = $isOutbound ? CallDirection::Outbound : CallDirection::Inbound;

        // CE-6: on inbound, OUR number is the one they rang, and the listener now says
        // which. Before this it was simply null, so an answered inbound call exported a
        // blank in the column the client reads as "which line did this come in on".
        //
        // 🔴 This branches on who DIALLED, not on the direction just derived, and the two
        // are no longer the same question. A dialled call is outbound but its number lives
        // on the note like an inbound one's does — it presents the campaign's own caller
        // ID (DQ-5), and the config default would file every campaign under the system
        // number. Only this agent's own dial has no note by design, and only it reads the
        // config. Written as `$isOutbound` this loses the campaign number on a dialled
        // call; written as `?? config(...)` it hands an inbound call with no note our
        // outbound caller ID as the line they supposedly rang. Both were measured. A blank
        // stays a blank (CE-4).
        $ourNumber = $agentDialledIt
            ? config('telephony.outbound.caller_id')
            : $moments?->dialled_number;

        Call::create([
            'direction' => $direction,
            // F25: copied off the note like the moments below, because the note is pruned
            // on this agent's next ring and DP-12a's report reads it long after.
            'was_dialled' => (bool) $moments?->was_dialled,
            // AU-25, copied off the note for the same reason the moments below are: the
            // note is pruned on this agent's next ring, and the calls list and the export
            // read this long after.
            'menu_choice' => $moments?->menu_choice,
            'from_number' => $isOutbound ? $ourNumber : $this->callPartyNumber,
            'to_number' => $isOutbound ? $this->callPartyNumber : $ourNumber,
            'lead_id' => $lead?->id,
            // CE-6: the matched lead's campaign still wins — it is the more specific
            // fact, and this only fills the gap where there is no lead at all. That gap
            // is most inbound calls, which is why Campaign was the biggest hole in the
            // export's menu.
            'campaign_id' => $lead?->campaign_id ?? $this->campaignForDialledNumber($moments),
            'agent_id' => auth()->id(),
            'disposition_id' => $disposition?->id,
            'outcome' => $this->outcomeFor($disposition),
            // CP-5: what the agent typed during the call. Blank stays null, never ''.
            'notes' => filled($callNotes) ? trim($callNotes) : null,
            'correlation_id' => $this->callCorrelationId,
            'started_at' => $moments?->arrived_at,
            // The note's own created_at IS the ring moment — it is written immediately
            // before the agent's phone is rung, so the carrier needed no column for it.
            'ringing_at' => $moments?->created_at,
            'answered_at' => $moments?->answered_at,
            'ended_at' => $moments?->ended_at,
            // CE-11: which side put the phone down, carried on the same note as the
            // moments and copied across the same way.
            'ended_by' => $moments?->ended_by,
            // hold.md H-7: how long this caller was held, carried on the same note. The
            // fallback to zero is the whole distinction the column exists for — a note
            // means the always-on program handled this call, so "no hold on the note"
            // means it was never held, which is a measured 0. NO note means we know
            // nothing about this call's holds at all, and that reads as a blank. Zero is
            // a measurement, blank is the absence of one.
            'hold_seconds' => $moments === null ? null : (int) $moments->hold_seconds,
            // The other half of a passed-on call already has the audio (CT-15).
            'recording_disk' => $sibling?->recording_disk,
            'recording_path' => $sibling?->recording_path,
        ]);
    }

    /**
     * The campaign that owns the number this caller rang (CE-6) — the fallback when no
     * lead matched, which is the ordinary case for a first-time inbound caller.
     *
     * The lookup itself lives on the model, because the missed-call writer in
     * CallToAgentFlow needs exactly the same one; the reasoning for why it is
     * client-scoped rather than company-blind lives with it.
     *
     * `phone_numbers.campaign_id` is nullable, so a number nobody has assigned to a
     * campaign still exports a blank. CE-6 narrows this hole; it does not close it.
     */
    private function campaignForDialledNumber(?CallHandoff $moments): ?int
    {
        return PhoneNumberRecord::campaignIdFor($moments?->dialled_number);
    }

    /**
     * The campaign this call belongs to, for every purpose that has to agree with every
     * other (CF-1) — which custom boxes the console prints, and which campaign a customer
     * saved mid-call is filed under.
     *
     * 🔴 THE RULE: the boxes printed always come from the campaign the save will write to.
     * Anything else silently loses data. The export prints the columns a CAMPAIGN defines
     * (CE-12a, `CallExportRows::customFields()`) and reads the values off the LEAD, so a
     * policy number typed against Insurance Renewals but stored on a person filed under
     * the generic Inbound bucket never leaves the building. The agent typed it; it vanished.
     *
     * The order, and why each step:
     *
     * 1. The matched customer's own campaign — the most specific fact we hold, and the one
     *    `recordCall()` already files the call under (:1323). Keeping the same order here is
     *    what stops the call row and the customer row disagreeing (F6).
     * 2. The number they rang. Which line an inbound call arrived on is what tells a contact
     *    centre which campaign it belongs to — the ordinary DNIS rule, not a local invention.
     * 3. The client's generic Inbound bucket. `leads.campaign_id` is NOT NULL, so a customer
     *    created from a call on an unassigned number still has to land somewhere.
     *
     * NOT `selectedCampaignId`: that is the OUTBOUND queue the agent picked earlier in their
     * shift. On an inbound call it is stale and unrelated — it would print the courses
     * campaign's boxes to a caller on the insurance line.
     *
     * 🔴 Step 3 READS the bucket, it does not create it, and returns null when it does not
     * exist yet. `callHistory()` calls this on every ring to decide which boxes to print,
     * and a screen pop must not write — creating a campaign and seeding it a disposition
     * set is not something an incoming call should do. The one caller that genuinely needs
     * the row to exist, `saveCustomer()`, adds the found-or-create itself. Both land on the
     * same campaign either way: if the bucket exists, step 3 finds it; if it does not, there
     * are no boxes to print anyway, because a campaign that does not exist defines none.
     *
     * `??` short-circuits, so steps 2 and 3 stay off the path entirely for a matched caller.
     *
     * **Named limit (CF-1):** a customer can be filed under exactly one campaign, so someone
     * who deals with the client across two product lines gets the boxes of whichever one
     * they are filed under, not the one they rang about. That is the single `campaign_id`
     * column, not this ordering — flipping the order here would write values the export can
     * never print. The upgrade is a lead-to-campaign join; do not build it on a guess.
     */
    /**
     * N2 — the call scripts the agent reads on THIS call: opening, objection, closing.
     *
     * Scoped the same way dispositions are (LeadForm::dispositionOptions): a row with a
     * null campaign_id is tenant-wide, a row with one belongs to that campaign only.
     *
     * 🔴 The campaign row WINS and hides the tenant-wide one of the same type. An agent
     * reads ONE opening out loud; showing them two and asking them to choose is a
     * decision made mid-sentence with a customer listening. The query puts campaign rows
     * first, so first-of-group is the campaign's whenever it has one.
     *
     * Bounded at three, because ScriptType is — so this is never a growing list and the
     * screen can spend a fixed panel on it. Answered in enum order (the order a call
     * uses them), and a type nobody has written is simply absent rather than an empty
     * tab. Content is plain text: the admin form is a Textarea, not a rich editor.
     *
     * `scripts` carries no unique index, so a client CAN file two openings for one
     * campaign. `orderBy('id')` makes which one wins stable rather than whatever
     * Postgres hands back — the oldest. Not worth a migration until someone does it.
     *
     * @return array<int, array{type: string, label: string, content: string}>
     */
    private function scriptsForCampaign(?int $campaignId): array
    {
        $byType = Script::query()
            ->when(
                filled($campaignId),
                fn ($query) => $query->where(function ($q) use ($campaignId): void {
                    $q->whereNull('campaign_id')->orWhere('campaign_id', $campaignId);
                }),
                fn ($query) => $query->whereNull('campaign_id'),
            )
            // FALSE sorts before TRUE, so the campaign's own rows lead each group.
            ->orderByRaw('campaign_id IS NULL')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Script $script): string => $script->type->value);

        return collect(ScriptType::cases())
            ->map(fn (ScriptType $type): array => [
                'type' => $type->value,
                'label' => $type->label(),
                'content' => (string) $byType->get($type->value)?->first()?->content,
            ])
            ->filter(fn (array $script): bool => filled($script['content']))
            ->values()
            ->all();
    }

    private function campaignForThisCall(): ?int
    {
        return $this->matchedCampaignId
            ?? $this->campaignForDialledNumber($this->handoffMoments())
            ?? Campaign::query()->where('name', Campaign::INBOUND_NAME)->value('id');
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
        // callCorrelationId is deliberately NOT reset here (B2.4b Option A): the inbound
        // ring-time claim (claimHandoffTicket) and the outbound dial (originateAgentLeg)
        // each establish it fresh per call, so resetMatch clearing it would let the lead
        // lookup wipe a just-claimed inbound ticket (resetMatch runs at the top of
        // lookupLead). The claim's single reset-and-set assignment covers the inbound miss.
    }
}
