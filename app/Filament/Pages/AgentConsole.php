<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\User;
use App\Support\PhoneNumber;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

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

        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'phone' => $lead->phone,
            'campaign' => $lead->campaign?->name,
            'status' => $lead->status->label(),
            'lastDisposition' => $lead->lastDisposition?->label,
        ];
    }
}
