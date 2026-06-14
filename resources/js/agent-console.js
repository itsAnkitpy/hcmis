import { AgentPhone } from './telephony/agent-phone';

/**
 * The Agent Console's browser-side state machine (B4 D3 — browser-driven off
 * phone events; no server push).
 *
 * States: 'offline' until the phone registers, then 'ready'. CP2a adds the call
 * leg: an inbound call -> 'ringing' (caller shown), Answer -> 'onCall', either
 * side hangs up -> back to 'ready'. (CP3 turns that last hop into wrap-up.)
 *
 * Registered as an Alpine component named "agentConsole" so the Blade view can
 * mount it with x-data="agentConsole(config)". Alpine ships with Filament — we
 * never import our own copy.
 */
const agentConsole = (config) => ({
    state: 'offline',
    error: null,
    callerNumber: null,
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

        this.phone.on('incoming', (number) => {
            this.callerNumber = number;
            this.state = 'ringing';
        });
        this.phone.on('answered', () => {
            this.state = 'onCall';
        });
        this.phone.on('ended', () => {
            this.callerNumber = null;
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
