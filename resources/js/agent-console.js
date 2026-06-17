import { AgentPhone } from './telephony/agent-phone';

/**
 * The Agent Console's browser-side state machine (B4 D3 — browser-driven off
 * phone events; no server push).
 *
 * States: 'offline' until the phone registers, then 'ready'. An inbound call ->
 * 'ringing' (caller shown), Answer -> 'onCall', either side hangs up. CP3: when
 * an *answered* call ends it goes to 'wrapUp' (an unanswered/declined ring goes
 * straight back to 'ready'); the agent picks a disposition and Save records the
 * outcome, then -> 'ready'.
 *
 * Outbound (B-outbound CP-O1): in 'ready' the agent picks a campaign and the
 * served lead shows (server-rendered). Dial -> the page originates the AGENT leg
 * first (agent-first); that leg rings this browser, and because the agent already
 * clicked Dial we AUTO-ANSWER it (no second ring to accept) -> 'calling' until the
 * customer is bridged in -> 'onCall'. Skip advances the served lead without
 * dialing. An ended outbound call lands in 'wrapUp' just like inbound.
 *
 * CP2b: on 'incoming' the caller's number is matched to a lead via the page's
 * lookupLead() over $wire (the lead card, or a bare-number fallback), and mute
 * toggles the mic mid-call (pure browser, reflected in `muted`).
 *
 * CP3: in 'wrapUp' the disposition options come from the page's dispositions()
 * (the matched lead's campaign set); Save calls saveWrapUp(id), and a no-match
 * wrap-up calls completeUnmatched() — both server-walled (decision C/D).
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
    answered: false,
    dispositions: {},
    selectedDisposition: '',
    saving: false,
    phone: null,

    // Outbound: true between clicking Dial and the call ending, so the agent
    // leg's inbound INVITE is auto-answered instead of presented as a ring.
    outboundDialing: false,

    // The agent's typed ad-hoc number (CP-O3 D3) and a short notice shown back in
    // 'ready' after a dial that never rang (e.g. blocked by Do-Not-Call).
    adhocNumber: '',
    notice: null,

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
            // Outbound: the agent already clicked Dial, so their own leg ringing
            // here must be auto-answered — no ring to accept. The served lead is
            // already known (dial() returned it), so no inbound lookup.
            if (this.outboundDialing) {
                this.phone.answer();
                return;
            }

            this.resetCall();
            this.callerNumber = number;
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
            this.answered = true;
            this.state = 'onCall';
        });
        this.phone.on('ended', async () => {
            // The dial is over either way — stop auto-answering.
            this.outboundDialing = false;

            // Only an answered call opens wrap-up (CP3 decision A). A declined or
            // abandoned ring (never answered) goes straight back to ready.
            if (! this.answered) {
                this.resetCall();
                this.state = 'ready';
                return;
            }

            this.dispositions = {};
            this.selectedDisposition = '';
            this.state = 'wrapUp';

            // Matched lead -> load its campaign's disposition options for the
            // picker. No match -> the panel shows the "nothing recorded" variant.
            if (this.lead) {
                try {
                    this.dispositions = await this.$wire.dispositions();
                } catch (e) {
                    this.dispositions = {};
                }
            }
        });

        this.phone.start();
    },

    answer() {
        this.phone.answer();
    },

    hangup() {
        this.phone.hangup();
    },

    /**
     * Outbound: dial the served lead (B-outbound CP-O1 / CP-O3). The page returns
     * a small result the screen branches on (CP-O3): 'dialed' (a lead rides along,
     * the agent leg auto-answers via outboundDialing), 'blocked' (on Do-Not-Call,
     * never rang — show a notice, back to ready), or 'none' (nothing callable).
     */
    async dial() {
        if (this.state !== 'ready' || this.outboundDialing) {
            return;
        }

        this.resetCall();
        this.outboundDialing = true;
        this.state = 'calling';

        try {
            this.applyDialResult(await this.$wire.dial());
        } catch (e) {
            this.outboundDialing = false;
            this.state = 'ready';
        }
    },

    /**
     * Outbound: dial an ad-hoc typed number (CP-O3 D3). Same result branching as
     * dial(), plus an 'invalid' notice when the number isn't dialable. An ad-hoc
     * call has no lead, so 'dialed' shows the bare number ("No matching lead").
     */
    async dialAdhoc() {
        const number = this.adhocNumber.trim();

        if (this.state !== 'ready' || this.outboundDialing || ! number) {
            return;
        }

        this.resetCall();
        this.outboundDialing = true;
        this.state = 'calling';

        try {
            const result = await this.$wire.dialAdhoc(number);

            if (result?.outcome === 'invalid') {
                this.outboundDialing = false;
                this.state = 'ready';
                this.notice = "That doesn't look like a dialable number.";
                return;
            }

            this.applyDialResult(result);

            if (result?.outcome === 'dialed') {
                this.adhocNumber = '';
            }
        } catch (e) {
            this.outboundDialing = false;
            this.state = 'ready';
        }
    },

    /**
     * Apply a dial result from the page (shared by dial() and dialAdhoc()).
     * 'dialed' keeps us in 'calling' until the call bridges; anything else placed
     * no call, so fall back to 'ready' (with a Do-Not-Call notice when blocked).
     */
    applyDialResult(result) {
        if (result?.outcome === 'dialed') {
            this.lead = result.lead ?? null;
            this.callerNumber = this.lead ? this.lead.phone : result.phone;
            return;
        }

        this.outboundDialing = false;
        this.state = 'ready';

        if (result?.outcome === 'blocked') {
            this.notice = `${result.phone} is on the Do-Not-Call list — not dialed.`;
        }
    },

    /** Outbound: pass the served lead without calling; the page advances the cursor. */
    async skip() {
        if (this.state !== 'ready' || this.outboundDialing) {
            return;
        }

        await this.$wire.skip();
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

    /** Save the picked disposition for the matched lead, then return to ready. */
    async saveWrapUp() {
        if (this.saving || ! this.selectedDisposition) {
            return;
        }

        this.saving = true;

        try {
            await this.$wire.saveWrapUp(Number(this.selectedDisposition));
        } finally {
            this.saving = false;
            this.resetCall();
            this.state = 'ready';
        }
    },

    /** No matching lead: close the call out (the miss is logged server-side). */
    async completeUnmatched() {
        if (this.saving) {
            return;
        }

        this.saving = true;

        try {
            await this.$wire.completeUnmatched();
        } finally {
            this.saving = false;
            this.resetCall();
            this.state = 'ready';
        }
    },

    /** Clear all per-call state (caller, lead, mute, wrap-up, outbound dial). */
    resetCall() {
        this.callerNumber = null;
        this.lead = null;
        this.leadResolved = false;
        this.muted = false;
        this.answered = false;
        this.dispositions = {};
        this.selectedDisposition = '';
        this.outboundDialing = false;
        this.notice = null;
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
