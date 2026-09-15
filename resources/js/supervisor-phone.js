import { AgentPhone } from './telephony/agent-phone';

/**
 * The supervisor's phone on the Live Agents board (SM-2).
 *
 * The agent console's state machine minus everything an agent does: no dialling,
 * no wrap-up, no breaks, no lead. All that is left is "register this browser as
 * this person's phone and say whether it worked" — which is the whole of slice 1.
 * Listen / whisper / barge are slices 2-4 and add their verbs here.
 *
 * 🔴 It never writes the who's-free board. That is the entire never-Ready rule
 * (SM-3): a supervisor is kept out of the routing pool by having no board row, not
 * by a role check anywhere in the router. Do not add a presence write to this file.
 *
 * 🔴 It also never raises AgentPhone's `busy` flag. That flag is what makes the
 * console refuse a second ring during wrap-up or a break, and it starts lowered —
 * so a monitoring leg reaches this phone rather than being answered with 486 Busy.
 * Slice 2's auto-answer (R1) hangs off the 'incoming' event from here.
 *
 * Mounted from live-agents.blade.php OUTSIDE the 15-second polled block, so the
 * board refreshing cannot tear down a registered phone — the same placement, and
 * the same reason, as that page's running call clock.
 */
const supervisorPhone = (config) => ({
    // 'none'  — no phone issued to this person (PP-12: a QC who was never given one,
    //           or a retired number whose key is gone). Nothing is attempted.
    // 'offline' — a phone exists and has not registered yet, or dropped.
    // 'ready'   — registered with the switch.
    state: 'none',
    error: null,
    phone: null,

    init() {
        // PP-12: no number, or a number with no key, means no registration at all —
        // never somebody else's identity. The directory already collapses both cases
        // to a null extension, so this is the one thing to check.
        if (! config.extension) {
            return;
        }

        this.state = 'offline';
        this.phone = new AgentPhone(config).attachRemoteAudio(this.$refs.supervisorAudio);

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

        this.phone.start();
    },

    destroy() {
        this.phone?.stop();
    },
});

const register = () => window.Alpine.data('supervisorPhone', supervisorPhone);

// Same mounting dance as the agent console: the panel is not in SPA mode, so this
// module runs before Filament starts Alpine.
if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
