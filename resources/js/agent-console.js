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
const agentConsole = (config, breakCategories = [], resumeBreak = null) => ({
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

    // B2.2a presence (the who's-free board): the last status pushed to the server,
    // so the per-transition writes dedupe (PD-3, the screen is the single writer);
    // and the handle for the ~15s heartbeat timer (PD-4), cleared on unmount.
    presence: null,
    heartbeatTimer: null,

    // BK-3 break picker: the client's active break types (server-rendered at page
    // load), whether the picker is open, and the chosen type while on break —
    // {id, label, limitMinutes} — null for an untyped break. The id rides the
    // presence write to setPresence; statusSince + limitMinutes drive the
    // client-side countdown (BK-4: overstay is computed here, never enforced).
    breakCategories,
    breakPickerOpen: false,
    activeBreak: null,

    // The status strip's clocks: when the current BOARD status began (statusSince,
    // reset when pushPresence actually writes — rapid screen hops like ringing ->
    // onCall share one board status, so the clock doesn't reset mid-call), and a
    // 1s display tick (`now`) that drives time-in-status + the break countdown.
    // Display only — no server traffic rides this tick (the heartbeat stays 15s).
    statusSince: Date.now(),
    now: Date.now(),
    clockTimer: null,

    // M4 callback capture: the disposition ids that mean "schedule a callback"
    // (server-derived), and the schedule fields revealed when one is picked.
    callbackDispositionIds: [],
    callbackAt: '',
    callbackNotes: '',

    // B2.0 PC-1: who can take the callback being captured — false = sticky to me
    // (default), true = pooled (any free agent can grab it). Sent to saveWrapUp.
    callbackPooled: false,

    // Outbound: true between clicking Dial and the call ending, so the agent
    // leg's inbound INVITE is auto-answered instead of presented as a ring.
    outboundDialing: false,

    // The agent's typed ad-hoc number (CP-O3 D3) and a short notice shown back in
    // 'ready' after a dial that never rang (e.g. blocked by Do-Not-Call).
    adhocNumber: '',
    notice: null,

    // B2.4a cold transfer: `transferring` flips on while a transfer is in flight (drives
    // the button's "Transferring…" label); `transferTimer` is the screen-side no-answer
    // window (TD-5 — on success A's own leg hangs up and the 'ended' handler takes over,
    // so this only has to revert on no-answer / nobody-free, where nothing changes
    // server-side); `transferNotice` is the short "didn't go through" line shown back on
    // the call. There is deliberately no listener->screen signal, so the timeout is the
    // one feedback path (the listener log shows no-answer vs nobody-free; the screen can't).
    transferring: false,
    transferTimer: null,
    transferNotice: null,

    // B2.4b conference: `conferencing` flips on while a conference ring is in flight (drives
    // the non-blocking "ringing to join…" indicator + disables the Conference button so a
    // second ring isn't fired — the listener also no-ops a second signal while a ring is in
    // flight). Unlike transfer it does NOT lock the call: A keeps mute/hang-up and STAYS on
    // the (now 3-way) call on success. There is no listener->screen signal (CD-6), so on
    // success A simply hears B join (audio confirms); `conferenceTimer` is the ring window
    // and `conferenceNotice` the neutral line shown after it — true whether B joined or not.
    conferencing: false,
    conferenceTimer: null,
    conferenceNotice: null,

    init() {
        this.phone = new AgentPhone(config).attachRemoteAudio(this.$refs.remoteAudio);

        // BK-7 resume-on-return (the industry pattern: the screen asks the server
        // "what am I?" on load — it never assumes Ready). A still-fresh on-break
        // board row resumes on screen exactly where it left off: same type, the
        // countdown anchored on the ORIGINAL start (server-supplied), the phone
        // busy so rings decline like any break. presence is pre-seeded so the
        // $watch pushes NO write — the board already says on_break; a write would
        // be a pointless self-echo. A dead session never reaches here: the server
        // returns null for a stale row (BK-6 — a new login is a new stay).
        if (resumeBreak) {
            this.state = 'onBreak';
            this.presence = 'on_break';
            this.activeBreak = resumeBreak.category;
            this.statusSince = resumeBreak.startedAtMs;
            this.phone.busy = true;
        }

        this.phone.on('registered', () => {
            // Only lift the boot state — never stomp a resumed break (BK-7), a live
            // call, or wherever a mid-session re-registration might land.
            if (this.state === 'offline') {
                this.state = 'ready';
            }
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

            // B2.4b TH-2/TH-3: claim the call's ticket the listener left for this agent
            // (the handoff), so the wrap-up stamps it on the calls row and the inbound
            // recording attaches. Runs on EVERY inbound ring — including the anonymous
            // branch below — independent of the lead lookup, so an anonymous caller still
            // attaches (Finding A). Fire-and-forget + best-effort: it completes long before
            // wrap-up (5-30s away) and must not block showing the lead; a failure just
            // means no recording attach (graceful, TH-2). Option A makes the order versus
            // lookupLead irrelevant (the claim owns callCorrelationId).
            this.$wire.claimHandoffTicket().catch(() => {});

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

            // B2.4a: a successful transfer hangs up THIS agent's leg, which lands here.
            // Cancel the screen-side no-answer timer so it can't fire during wrap-up, and
            // clear the transfer flags — the call moving to wrap-up is the success signal.
            clearTimeout(this.transferTimer);
            this.transferring = false;
            this.transferNotice = null;

            // B2.4b: A's leg ending also ends any conference indicator — either A dropped
            // out of the 3-way (the other two continue server-side) or the whole call ended.
            clearTimeout(this.conferenceTimer);
            this.conferencing = false;
            this.conferenceNotice = null;

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
            // picker, plus which of them schedule a callback (M4). No match -> the
            // panel shows the "nothing recorded" variant.
            if (this.lead) {
                try {
                    this.dispositions = await this.$wire.dispositions();
                    this.callbackDispositionIds = await this.$wire.callbackDispositionIds();
                } catch (e) {
                    this.dispositions = {};
                    this.callbackDispositionIds = [];
                }
            }
        });

        // B2.2a PD-4 — the heartbeat: "still here" every ~15s on its OWN timer. It
        // deliberately does NOT piggyback the pooled-callbacks wire:poll: that poll
        // is `.visible` and lives inside the ready-only block, so it PAUSES during a
        // call — exactly when the board must keep the agent shown alive. heartbeat()
        // is renderless server-side, so this never disturbs a live call.
        this.heartbeatTimer = setInterval(() => this.$wire.heartbeat(), 15000);

        // The strip's display tick (BK-3): browser-only, updates the reactive clock
        // the time-in-status and break countdown read. Never touches the server.
        this.clockTimer = setInterval(() => (this.now = Date.now()), 1000);

        // B2.2a PD-3 — one place maps every screen transition to a board write (+
        // the busy flag that declines rings during wrap-up / break). Catches every
        // state change, so no transition can silently skip the board.
        this.$watch('state', (state) => this.syncPresence(state));

        this.phone.start();
    },

    /**
     * B2.2a — reflect the screen state onto the who's-free board (PD-3). One status
     * per state group; deduped so the rapid transitions (ringing -> onCall) write
     * once. Also toggles the phone's busy flag: a ring while away with no active
     * call (wrap-up / on break) is declined (on a live call the phone's own
     * session-in-hand check already rejects a 2nd INVITE).
     */
    syncPresence(state) {
        const status = {
            offline: 'offline',
            ready: 'ready',
            ringing: 'on_call',
            calling: 'on_call',
            onCall: 'on_call',
            wrapUp: 'wrapping_up',
            onBreak: 'on_break',
        }[state];

        if (status) {
            this.pushPresence(status);
        }

        if (this.phone) {
            this.phone.busy = state === 'wrapUp' || state === 'onBreak';
        }
    },

    /** Push a board status to the server (renderless), skipping a no-op repeat. */
    pushPresence(status) {
        if (this.presence === status) {
            return;
        }

        this.presence = status;
        this.statusSince = Date.now();
        // BK-3: a break carries its picked type's id (untyped break -> null). The
        // server re-validates it (active + own client) — this is a hint, not a wall.
        this.$wire.setPresence(status, status === 'on_break' ? (this.activeBreak?.id ?? null) : null);
    },

    /** A human label for the agent's current board status (drives the status strip). */
    presenceLabel() {
        return {
            offline: 'Offline',
            ready: 'Ready',
            ringing: 'On a call',
            calling: 'On a call',
            onCall: 'On a call',
            wrapUp: 'Wrapping up',
            onBreak: 'On break',
        }[this.state] ?? '—';
    },

    /** Seconds in the current board status (the strip's small clock). */
    statusSeconds() {
        return Math.max(0, Math.floor((this.now - this.statusSince) / 1000));
    },

    /** Seconds as a clock — "4:07", or "1:02:07" past the hour. */
    formatClock(totalSeconds) {
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const pad = (n) => String(n).padStart(2, '0');

        return hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${minutes}:${pad(seconds)}`;
    },

    /** The picked break's limit in seconds — null for untyped / no-limit breaks. */
    breakLimitSeconds() {
        return this.activeBreak?.limitMinutes ? this.activeBreak.limitMinutes * 60 : null;
    },

    /**
     * BK-4: overstay is COMPUTED (now past start + limit), never stored and never
     * enforced — the strip turns red and shouts, and that is all. Breaks without
     * a limit can't overstay (they count up instead, BK-3).
     */
    isBreakOverdue() {
        const limit = this.breakLimitSeconds();

        return this.state === 'onBreak' && limit !== null && this.statusSeconds() > limit;
    },

    /**
     * The strip's break clock: counts DOWN to the limit, then counts the overrun
     * back UP; a break with no limit just counts up (BK-3 elapsed-only). The
     * caption below the number says which of the three it is.
     */
    breakClock() {
        const limit = this.breakLimitSeconds();
        const elapsed = this.statusSeconds();

        if (limit === null) {
            return this.formatClock(elapsed);
        }

        return this.isBreakOverdue() ? this.formatClock(elapsed - limit) : this.formatClock(limit - elapsed);
    },

    /** What the break clock is counting — reads with the number ("4:07 left of your 30 min"). */
    breakCaption() {
        const limit = this.activeBreak?.limitMinutes;

        if (! limit) {
            return 'on break so far';
        }

        return this.isBreakOverdue() ? `past your ${limit} min` : `left of your ${limit} min`;
    },

    /** The strip's headline: the status, plus the break type when one was picked. */
    stripTitle() {
        if (this.state === 'onBreak' && this.activeBreak) {
            return `On break — ${this.activeBreak.label}`;
        }

        return this.presenceLabel();
    },

    /**
     * B2.2a — go on break (PD-2): only from ready. An away state that declines rings.
     * BK-3: when the client has active break types the picker opens first (required
     * — untyped breaks are the Dialshree gap this replaces); with none configured
     * it falls straight to a plain untyped break rather than trapping the agent.
     */
    startBreak() {
        if (this.state !== 'ready') {
            return;
        }

        if (this.breakCategories.length > 0) {
            this.breakPickerOpen = true;
            return;
        }

        this.beginBreak(null);
    },

    /** BK-3 — the picker's choice: start the break under this type. */
    chooseBreak(category) {
        if (this.state !== 'ready') {
            return;
        }

        this.beginBreak(category);
    },

    /**
     * Flip to on-break under a type (or untyped = null). The countdown anchors on
     * statusSince — the board write resets it as the break starts, and nothing
     * touches it again until the agent returns (one clock, no second anchor); the
     * $watch pushes the board write with the type's id.
     */
    beginBreak(category) {
        this.breakPickerOpen = false;
        this.activeBreak = category;
        this.state = 'onBreak';
    },

    /** B2.2a — come back from break to ready (the $watch flips the board + busy flag). */
    endBreak() {
        if (this.state !== 'onBreak') {
            return;
        }

        this.activeBreak = null;
        this.state = 'ready';
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

    /**
     * Outbound: dial a specific due callback (M4) rather than the next campaign
     * lead. Same result branching as dial() — 'dialed' rides the lead along and the
     * agent leg auto-answers; 'blocked' (now on Do-Not-Call) shows a notice; 'none'
     * (the callback was already taken / vanished) quietly returns to ready.
     */
    async dialCallback(id) {
        if (this.state !== 'ready' || this.outboundDialing) {
            return;
        }

        this.resetCall();
        this.outboundDialing = true;
        this.state = 'calling';

        try {
            this.applyDialResult(await this.$wire.dialCallback(id));
        } catch (e) {
            this.outboundDialing = false;
            this.state = 'ready';
        }
    },

    /**
     * B2.0: grab a pooled (unowned) callback so it becomes mine. The page resolves
     * the small race atomically (claimCallback) — 'claimed' means I won, and the
     * $wire round-trip re-renders both panels so the row moves from the pooled list
     * into "My due callbacks"; 'taken' means another agent won first, so show a
     * short notice (the morph has already dropped the row from the pooled list).
     * Only acts from 'ready' — grabbing is a between-calls action.
     */
    async grabCallback(id) {
        if (this.state !== 'ready' || this.outboundDialing) {
            return;
        }

        try {
            const result = await this.$wire.claimCallback(id);

            if (result?.outcome === 'taken') {
                this.notice = 'That callback was just taken by another agent.';
            }
        } catch (e) {
            // A failed grab leaves the row in the pool for the next refresh.
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

    /**
     * B2.4a — cold-transfer the live call to a free agent. Signal the listener (over
     * $wire -> transferCall(), which POSTs the user-event); the listener reserves a
     * free agent and rings them while this agent keeps talking. On success this
     * agent's own leg is hung up by the listener -> the 'ended' handler moves us to
     * wrap-up. On no-answer / nobody-free nothing changes server-side, so a screen-side
     * timer (TD-5) reverts the button after the ring window with a short note. Only
     * from 'onCall', and only one at a time.
     */
    transfer() {
        if (this.state !== 'onCall' || this.transferring) {
            return;
        }

        this.transferring = true;
        this.transferNotice = null;
        this.$wire.transferCall();

        // The ring window: a touch longer than Asterisk's 30s originate timeout, so the
        // real no-answer (B's leg ending) is always given the chance to resolve first.
        clearTimeout(this.transferTimer);
        this.transferTimer = setTimeout(() => {
            this.transferring = false;
            this.transferNotice = "Transfer didn't go through — you're still on the call.";
        }, 35000);
    },

    /**
     * B2.4b — conference a free agent into the live call (CD-3/CD-6). Signal the listener
     * (over $wire -> conferenceCall(), the same web→provider POST as transfer, only named
     * 'conference'); the listener reserves a free agent and rings them while this agent
     * KEEPS TALKING. Non-blocking: unlike transfer this does NOT lock the call — A retains
     * mute/hang-up and stays on the (now 3-way) call. On success A simply hears B join
     * (there is no listener->screen signal, CD-6); on no-answer / nobody-free nothing
     * changes server-side, so a screen-side timer clears the indicator after the ring
     * window with a NEUTRAL note (true either way, since A stays on the call regardless).
     * Only from 'onCall', and only one ring in flight at a time.
     */
    conference() {
        if (this.state !== 'onCall' || this.conferencing) {
            return;
        }

        this.conferencing = true;
        this.conferenceNotice = null;
        this.$wire.conferenceCall();

        // The ring window: a touch longer than Asterisk's 30s originate timeout, so a real
        // no-answer (B's leg ending) resolves first. The note must NOT claim failure — on
        // success B has already joined audibly, so this just confirms the ring window closed.
        clearTimeout(this.conferenceTimer);
        this.conferenceTimer = setTimeout(() => {
            this.conferencing = false;
            this.conferenceNotice = 'Conference ring ended — if no one joined, you’re still on the call.';
        }, 35000);
    },

    /** Whether the picked outcome schedules a callback (reveals the date/time fields). */
    isCallbackSelected() {
        return this.callbackDispositionIds.includes(Number(this.selectedDisposition));
    },

    /** Now, as a `datetime-local` value — the earliest a callback may be set to. */
    minCallbackLocal() {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());

        return now.toISOString().slice(0, 16);
    },

    /** A due callback's UTC time, rendered in the agent's own local clock. */
    formatDue(iso) {
        return new Date(iso).toLocaleString([], {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
    },

    /**
     * Save the picked disposition for the matched lead, then return to ready. When
     * the outcome schedules a callback (M4), a date/time is required and rides along
     * with the optional note; the server validates and creates the callback row.
     */
    async saveWrapUp() {
        if (this.saving || ! this.selectedDisposition) {
            return;
        }

        const isCallback = this.isCallbackSelected();

        // A callback needs a time — the server enforces this too, but guard here so
        // the click can't silently no-op a missing schedule.
        if (isCallback && ! this.callbackAt) {
            return;
        }

        this.saving = true;

        try {
            // The picker is browser-local; send UTC so the server (UTC) stores the
            // real instant. The agent's clock and the stored time then agree.
            const scheduledAt = isCallback ? new Date(this.callbackAt).toISOString() : null;

            await this.$wire.saveWrapUp(
                Number(this.selectedDisposition),
                scheduledAt,
                isCallback ? (this.callbackNotes || null) : null,
                isCallback ? this.callbackPooled : false,
            );
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
        // A ring can land with the break picker open (picking is a 'ready' act) —
        // close it so it doesn't reappear stale after the call.
        this.breakPickerOpen = false;
        this.callerNumber = null;
        this.lead = null;
        this.leadResolved = false;
        this.muted = false;
        this.answered = false;
        this.dispositions = {};
        this.selectedDisposition = '';
        this.callbackDispositionIds = [];
        this.callbackAt = '';
        this.callbackNotes = '';
        this.callbackPooled = false;
        this.outboundDialing = false;
        this.notice = null;
        clearTimeout(this.transferTimer);
        this.transferring = false;
        this.transferNotice = null;
        clearTimeout(this.conferenceTimer);
        this.conferencing = false;
        this.conferenceNotice = null;
    },

    destroy() {
        clearInterval(this.heartbeatTimer);
        clearInterval(this.clockTimer);
        clearTimeout(this.transferTimer);
        clearTimeout(this.conferenceTimer);
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
