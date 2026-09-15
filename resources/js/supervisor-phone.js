import { AgentPhone } from './telephony/agent-phone';

/**
 * The supervisor's phone on the Live Agents board (SM-2).
 *
 * The agent console's state machine minus everything an agent does: no dialling,
 * no wrap-up, no breaks, no lead. All that is left is "register this browser as
 * this person's phone and say whether it worked" — which is the whole of slice 1.
 * Listen is slice 2 and whisper slice 3 (below); barge is slice 4.
 *
 * 🔴 It never writes the who's-free board. That is the entire never-Ready rule
 * (SM-3): a supervisor is kept out of the routing pool by having no board row, not
 * by a role check anywhere in the router. Do not add a presence write to this file.
 *
 * 🔴 It also never raises AgentPhone's `busy` flag. That flag is what makes the
 * console refuse a second ring during wrap-up or a break, and it starts lowered —
 * so a monitoring leg reaches this phone rather than being answered with 486 Busy.
 * Slice 2's auto-answer (R1) hangs off the 'incoming' event below.
 *
 * Stopping is hanging up, and nothing more: the leg ending is what tells the listener
 * to release the tap and fold the mixer, so closing this tab stops a monitoring
 * session exactly as pressing Stop does.
 *
 * Mounted from live-agents.blade.php OUTSIDE the 15-second polled block, so the
 * board refreshing cannot tear down a registered phone — the same placement, and
 * the same reason, as that page's running call clock.
 */
const supervisorPhone = (config) => ({
    // 'none'  — no phone issued to this person (PP-12: a QC who was never given one,
    //           or a retired number whose key is gone). Nothing is attempted.
    // 'offline'   — a phone exists and has not registered yet, or dropped.
    // 'ready'     — registered with the switch.
    // 'listening' — a monitoring leg is up and the supervisor is hearing a live call.
    state: 'none',
    // Which button started the session, 'listen' or 'whisper' (SM slice 3). It only
    // decides what this panel SAYS: the audio itself is settled on the switch when the
    // tap is made, and nothing here can change it. It arrives as a browser event from
    // the button rather than as rendered markup, so that starting a session cannot
    // redraw the panel and tear down a registered phone.
    mode: 'listen',
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

        // 🔴 SM slice 2 (R1): the monitoring leg PICKS ITSELF UP. Genesys and Amazon
        // Connect both do this, and the reason is the sequence — the supervisor already
        // said what they wanted when they pressed Listen on the board, so a second
        // "answer?" prompt seconds later is a step that can only be got wrong. It is
        // also the opposite of the agent console's guard on the same event, which
        // DECLINES a second ring; the two must never share a code path by accident.
        //
        // Nothing but a monitoring leg ever rings this phone: it holds no board row, so
        // the router cannot reach it (SM-3).
        this.phone.on('incoming', () => this.phone.answer());

        this.phone.on('answered', () => {
            this.state = 'listening';
        });

        // Covers every way listening stops — the Stop button, the call ending
        // underneath, the listener releasing the leg. The phone stays registered, so
        // the next Listen works without a page refresh.
        this.phone.on('ended', () => {
            if (this.state === 'listening') {
                this.state = 'ready';
                this.mode = 'listen';
            }
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
