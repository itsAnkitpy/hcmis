import JsSIP from 'jssip';

/**
 * AgentPhone — the one module that owns JsSIP (B4 D2 discipline).
 *
 * The rest of the app (Blade, Livewire, the Alpine state machine) never touches
 * JsSIP directly — it speaks to this small surface. A future library swap
 * (sip.js) is contained to this file. CP1 covers registration + status only;
 * dial / answer / mute / hangup land here in CP2.
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

        // event name -> Set<callback>; the only way the outside world hears the phone.
        this.listeners = new Map();
    }

    /**
     * Subscribe to a phone event. CP1 emits: 'registered', 'unregistered',
     * 'registrationFailed'. Returns nothing — keep it simple for one consumer.
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

        this.ua.start();
    }

    /** Tear the phone down — called when the Console page unmounts. */
    stop() {
        this.ua?.stop();
        this.ua = null;
    }
}
