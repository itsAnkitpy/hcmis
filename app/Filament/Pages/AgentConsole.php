<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\AdvanceLeadStatus;
use App\Audit\Audit;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Telephony\TelephonyProvider;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

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
     * @return array{extension: ?string, password: ?string, wsUrl: ?string, sipDomain: string}
     */
    public function getPhoneConfig(): array
    {
        return [
            'extension' => config('telephony.agent.extension'),
            'password' => config('telephony.agent.password'),
            'wsUrl' => config('telephony.agent.ws_url'),
            'sipDomain' => config('telephony.agent.sip_domain'),
        ];
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
     * Dial the served lead (M2 origination, agent-first). The lead is re-resolved
     * SERVER-SIDE here (never a browser-supplied id), its ids are stashed #[Locked]
     * exactly like inbound's match, and the AGENT leg is originated carrying the
     * customer's number as the leg's tag detail — the flow reads it back and dials
     * the customer (CP-O0 transport). Returns the lead shape for the screen, or
     * null when there is nothing callable to dial.
     *
     * @return array{id: int, name: ?string, phone: string, campaign: ?string, status: string, lastDisposition: ?string}|null
     */
    public function dial(): ?array
    {
        $lead = $this->nextCallableLead();

        if ($lead === null) {
            return null;
        }

        $this->matchedLeadId = $lead->id;
        $this->matchedCampaignId = $lead->campaign_id;

        app(TelephonyProvider::class)->placeCall(
            config('telephony.agent.endpoint'),
            'agent',
            tagDetail: $lead->phone,
        );

        return $this->presentLead($lead);
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
            ->orderBy('attempts')
            ->orderBy('id')
            ->first();
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
     */
    public function saveWrapUp(int $dispositionId): void
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

        $lead->update([
            'last_disposition_id' => $disposition->id,
            'attempts' => $lead->attempts + 1,
            'status' => (new AdvanceLeadStatus)($lead->status, $disposition->is_contact),
        ]);

        Audit::callWrappedUp($lead, $disposition);

        $this->resetMatch();
    }

    /**
     * The no-match wrap-up (decision Q6): close the call out writing nothing to any
     * lead, but log the miss so B3 can measure how often callers arrive unknown.
     */
    public function completeUnmatched(): void
    {
        Gate::authorize('record-call-outcome');

        Audit::callWrappedUp(null);

        $this->resetMatch();
    }

    private function resetMatch(): void
    {
        $this->matchedLeadId = null;
        $this->matchedCampaignId = null;
    }
}
