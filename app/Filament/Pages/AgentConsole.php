<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\RoleName;
use App\Models\User;
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
}
