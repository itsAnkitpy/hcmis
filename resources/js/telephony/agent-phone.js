import JsSIP from 'jssip';

/**
 * AgentPhone — the one module that owns JsSIP (B4 D2 discipline).
 *
 * The rest of the app (Blade, Livewire, the Alpine state machine) never touches
 * JsSIP directly — it speaks to this small surface. A future library swap
 * (sip.js) is contained to this file.
 *
 * Surface:
 *   - CP1: start() + 'registered' / 'unregistered' / 'registrationFailed'.
 *   - CP2a: an inbound call rings here (the app dials this agent). It emits
 *     'incoming' (with the caller's number) -> 'answered' -> 'ended', and
 *     answer() / hangup() drive it. The caller's voice is wired into the
 *     <audio> element handed in via attachRemoteAudio(), using the exact
 *     'track' -> srcObject path the A4 lab page proved against this Asterisk.
 *
 * @typedef {object} AgentPhoneConfig
 * @property {string} extension  the SIP user (e.g. "1003")
 * @property {string} password   the SIP secret (lab throwaway; prod uses wss + real auth)
 * @property {string} wsUrl      the SIP-over-WebSocket URL (ws://… lab, wss://… prod)
 * @property {string} sipDomain  identity domain; Asterisk matches by extension
 */
export class AgentPhone {
    /** @param {AgentPhoneConfig} config */
    constructor(config) {
        this.config = config;
        this.ua = null;
        this.session = null;
        this.remoteAudio = null;

        // event name -> Set<callback>; the only way the outside world hears the phone.
        this.listeners = new Map();
    }

    /**
     * Subscribe to a phone event: 'registered', 'unregistered',
     * 'registrationFailed', 'incoming' (caller number), 'answered', 'ended'.
     *
     * @param {string} event
     * @param {(payload?: any) => void} callback
     */
    on(event, callback) {
        if (! this.listeners.has(event)) {
            this.listeners.set(event, new Set());
        }

        this.listeners.get(event).add(callback);

        return this;
    }

    /** @param {string} event */
    emit(event, payload) {
        this.listeners.get(event)?.forEach((callback) => callback(payload));
    }

    /**
     * The <audio> element the caller's voice plays through. Handed in by the
     * page so the module owns no DOM of its own.
     *
     * @param {HTMLAudioElement} element
     */
    attachRemoteAudio(element) {
        this.remoteAudio = element;

        return this;
    }

    /** Build the user agent and start registering. */
    start() {
        const socket = new JsSIP.WebSocketInterface(this.config.wsUrl);

        this.ua = new JsSIP.UA({
            sockets: [socket],
            uri: `sip:${this.config.extension}@${this.config.sipDomain}`,
            password: this.config.password,
            register: true,
        });

        this.ua.on('registered', () => this.emit('registered'));
        this.ua.on('unregistered', () => this.emit('unregistered'));
        this.ua.on('registrationFailed', (e) =>
            this.emit('registrationFailed', e?.cause ?? 'unknown'),
        );
        this.ua.on('newRTCSession', (data) => this.onSession(data));

        this.ua.start();
    }

    /**
     * An inbound call. v1 only ever receives calls (the app dials the agent),
     * so we drive only remote-originated sessions.
     *
     * @param {{originator: string, session: object}} data
     */
    onSession(data) {
        if (data.originator !== 'remote') {
            return;
        }

        this.session = data.session;

        // The peer connection is created during answer(); wire the caller's
        // audio in the moment it exists (the A4-proven 'track' -> srcObject path).
        this.session.on('peerconnection', (e) => {
            e.peerconnection.addEventListener('track', (event) => {
                if (this.remoteAudio) {
                    this.remoteAudio.srcObject = event.streams[0];
                }
            });
        });
        this.session.on('confirmed', () => this.emit('answered'));
        this.session.on('ended', () => this.clearSession());
        this.session.on('failed', () => this.clearSession());

        this.emit('incoming', this.callerNumber());
    }

    /** Accept the ringing call and open two-way audio. */
    answer() {
        this.session?.answer({
            mediaConstraints: { audio: true, video: false },
            pcConfig: { iceServers: [] }, // lab is same-machine; host candidates suffice
        });
    }

    /** Hang up / decline the current call (works ringing or connected). */
    hangup() {
        this.session?.terminate();
    }

    /** The caller's number as Asterisk set it on the INVITE (shown on the screen). */
    callerNumber() {
        return this.session?.remote_identity?.uri?.user ?? null;
    }

    clearSession() {
        this.session = null;
        this.emit('ended');
    }

    /** Tear the phone down — called when the Console page unmounts. */
    stop() {
        this.session?.terminate();
        this.ua?.stop();
        this.ua = null;
        this.session = null;
    }
}
