<x-filament-panels::page>
    @vite('resources/js/agent-console.js')

    {{-- B4 CP1: the page registers as a browser phone; the status pill flips
         offline -> ready on its own off the phone's events (D3). The work
         screen (lead card, call controls) builds into this shell in CP2+. --}}
    <div x-data="agentConsole(@js($this->getPhoneConfig()))" class="mx-auto w-full max-w-2xl">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                        Browser phone
                    </h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Extension {{ $this->getPhoneConfig()['extension'] ?? '—' }}
                    </p>
                </div>

                <span
                    class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium"
                    :class="{
                        'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': state === 'offline' && ! error,
                        'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400': state === 'ready',
                        'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400': error,
                    }"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :class="{
                            'bg-gray-400': state === 'offline' && ! error,
                            'bg-green-500': state === 'ready',
                            'bg-red-500': error,
                        }"
                    ></span>
                    <span x-text="error ? 'registration failed' : (state === 'ready' ? 'ready' : 'connecting…')"></span>
                </span>
            </div>

            <p x-show="error" x-cloak class="mt-3 text-sm text-red-600 dark:text-red-400">
                <span x-text="error"></span>
            </p>
        </div>

        {{-- B4 CP2a/CP2b: the call panel. The app dials this agent; the phone
             fires 'incoming' -> ringing, Answer opens two-way audio -> on-call,
             either hang-up returns to ready (CP3 makes that last hop wrap-up).
             CP2b shows the matched lead (D4) and a mute toggle; the caller's
             voice plays through the hidden <audio> sink below. --}}
        <div
            x-show="state === 'ringing' || state === 'onCall'"
            x-cloak
            class="mt-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p
                        class="text-sm text-gray-500 dark:text-gray-400"
                        x-text="state === 'ringing' ? 'Incoming call' : 'On call'"
                    ></p>

                    {{-- Matched lead (B4 D4): name + context. No match -> the bare
                         number, honestly labelled "No matching lead" (D4/D6). --}}
                    <template x-if="lead">
                        <div class="mt-1">
                            <p
                                class="text-lg font-semibold text-gray-950 dark:text-white"
                                x-text="lead.name || 'Unnamed lead'"
                            ></p>
                            <p class="text-sm text-gray-500 dark:text-gray-400" x-text="callerNumber"></p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                                <span x-show="lead.campaign" x-text="lead.campaign"></span>
                                <span class="font-medium text-gray-700 dark:text-gray-300" x-text="lead.status"></span>
                                <span x-show="lead.lastDisposition" x-text="'Last: ' + lead.lastDisposition"></span>
                            </div>
                        </div>
                    </template>

                    <template x-if="! lead">
                        <div class="mt-1">
                            <p
                                class="text-lg font-semibold text-gray-950 dark:text-white"
                                x-text="callerNumber || 'Unknown number'"
                            ></p>
                            <p
                                x-show="leadResolved && callerNumber"
                                class="text-sm text-gray-500 dark:text-gray-400"
                            >No matching lead</p>
                        </div>
                    </template>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-show="state === 'ringing'"
                        x-on:click="answer()"
                        class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500"
                    >
                        Answer
                    </button>
                    <button
                        type="button"
                        x-show="state === 'onCall'"
                        x-on:click="toggleMute()"
                        :class="muted
                            ? 'bg-amber-500 text-white hover:bg-amber-400'
                            : 'bg-gray-200 text-gray-700 hover:bg-gray-300 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/20'"
                        class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold"
                        x-text="muted ? 'Unmute' : 'Mute'"
                    ></button>
                    <button
                        type="button"
                        x-on:click="hangup()"
                        class="inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500"
                        x-text="state === 'ringing' ? 'Decline' : 'Hang up'"
                    ></button>
                </div>
            </div>
        </div>

        {{-- The caller's voice plays here; hidden, but audio still flows (D2). --}}
        <audio x-ref="remoteAudio" autoplay class="hidden"></audio>
    </div>
</x-filament-panels::page>
