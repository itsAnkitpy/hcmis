import { AgentPhone } from './telephony/agent-phone';

/**
 * The Agent Console's browser-side state machine (B4 D3 — browser-driven off
 * phone events; no server push).
 *
 * States: 'offline' until the phone registers, then 'ready'. CP2a adds the call
 * leg: an inbound call -> 'ringing' (caller shown), Answer -> 'onCall', either
 * side hangs up -> back to 'ready'. (CP3 turns that last hop into wrap-up.)
 *
 * CP2b: on 'incoming' the caller's number is matched to a lead via the page's
 * lookupLead() over $wire (the lead card, or a bare-number fallback), and mute
 * toggles the mic mid-call (pure browser, reflected in `muted`).
 *
 * Registered as an Alpine component named "agentConsole" so the Blade view can
 * mount it with x-data="agentConsole(config)". Alpine ships with Filament — we
 * never import our own copy.
 */
const agentConsole = (config) => ({
    state: 'offline',
    error: null,
    callerNumber: null,
    lead: null,
    leadResolved: false,
    muted: false,
    phone: null,

    init() {
        this.phone = new AgentPhone(config).attachRemoteAudio(this.$refs.remoteAudio);

        this.phone.on('registered', () => {
            this.state = 'ready';
            this.error = null;
        });
        this.phone.on('unregistered', () => {
            this.state = 'offline';
        });
        this.phone.on('registrationFailed', (cause) => {
            this.state = 'offline';
            this.error = cause;
        });

        this.phone.on('incoming', async (number) => {
            this.callerNumber = number;
            this.lead = null;
            this.leadResolved = false;
            this.muted = false;
            this.state = 'ringing';

            // Anonymous caller (no number) — nothing to match; show the bare
            // "no matching lead" state once, no server round-trip.
            if (! number) {
                this.leadResolved = true;
                return;
            }

            try {
                // $wire reaches the page's lookupLead() — the match runs in the
                // agent's tenant context (B4 D4). A non-match resolves to null.
                this.lead = await this.$wire.lookupLead(number);
            } catch (e) {
                this.lead = null;
            } finally {
                this.leadResolved = true;
            }
        });
        this.phone.on('answered', () => {
            this.state = 'onCall';
        });
        this.phone.on('ended', () => {
            this.callerNumber = null;
            this.lead = null;
            this.leadResolved = false;
            this.muted = false;
            this.state = 'ready';
        });

        this.phone.start();
    },

    answer() {
        this.phone.answer();
    },

    hangup() {
        this.phone.hangup();
    },

    /** Toggle the mic mid-call (browser-local); `muted` drives the button. */
    toggleMute() {
        if (this.muted) {
            this.phone.unmute();
        } else {
            this.phone.mute();
        }

        this.muted = ! this.muted;
    },

    destroy() {
        this.phone?.stop();
    },
});

const register = () => window.Alpine.data('agentConsole', agentConsole);

// The page is a full load (the panel is not in SPA mode), so this module runs
// before Filament starts Alpine — register on alpine:init. Guard the rare case
// where Alpine is already present.
if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
