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

                {{-- B2.2a: the who's-free board indicator (PD-2). The pill reads the
                     agent's live status off the screen state — Ready / On a call /
                     Wrapping up / On break / Offline — the same value the screen
                     writes to the agent_presence board over $wire. --}}
                <span
                    class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium"
                    :class="{
                        'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400': error,
                        'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': ! error && state === 'offline',
                        'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400': ! error && state === 'ready',
                        'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400': ! error && (state === 'ringing' || state === 'calling' || state === 'onCall'),
                        'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400': ! error && state === 'wrapUp',
                        'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400': ! error && state === 'onBreak',
                    }"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :class="{
                            'bg-red-500': error,
                            'bg-gray-400': ! error && state === 'offline',
                            'bg-green-500': ! error && state === 'ready',
                            'bg-blue-500': ! error && (state === 'ringing' || state === 'calling' || state === 'onCall'),
                            'bg-amber-500': ! error && state === 'wrapUp',
                            'bg-orange-500': ! error && state === 'onBreak',
                        }"
                    ></span>
                    <span x-text="error ? 'registration failed' : presenceLabel()"></span>
                </span>
            </div>

            <p x-show="error" x-cloak class="mt-3 text-sm text-red-600 dark:text-red-400">
                <span x-text="error"></span>
            </p>
        </div>

        {{-- B-outbound CP-O1: the preview dialer. In 'ready' the agent picks a
             campaign and the next callable lead is served (server-rendered: not
             Closed, fewest attempts then oldest). Dial originates the call
             agent-first (the page places the agent leg carrying the customer
             number); Skip advances the served cursor without calling. --}}
        <div
            x-show="state === 'ready'"
            x-cloak
            class="mt-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900"
        >
            {{-- B2.2a (PD-2): the one manual board control — step away to "On break"
                 so the system stops ringing this agent. Returns via the On break
                 panel's "I'm back". Only offered between calls (the ready block). --}}
            <div class="mb-4 flex items-center justify-between gap-3">
                <p class="text-sm text-gray-500 dark:text-gray-400">You're ready for calls.</p>
                <button
                    type="button"
                    x-on:click="startBreak()"
                    class="inline-flex items-center rounded-lg bg-gray-200 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-300 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/20"
                >
                    Take a break
                </button>
            </div>

            {{-- CP-O3 O1: a dial that never rang (blocked by Do-Not-Call, or an
                 unusable number) drops back here with a short notice. --}}
            <div
                x-show="notice"
                x-cloak
                class="mb-4 flex items-start justify-between gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
            >
                <span x-text="notice"></span>
                <button type="button" x-on:click="notice = null" class="font-semibold hover:opacity-70">Dismiss</button>
            </div>

            {{-- M4 (CP-O4): the agent's due callbacks — their own, pending, and now
                 due. Spans campaigns (owned by the agent, not the selected campaign).
                 Dialing one calls that lead like any served lead and consumes the
                 callback (it drops off the list on the next render). --}}
            @php($dueCallbacks = $this->dueCallbacks())
            @if (count($dueCallbacks) > 0)
                <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-500/20 dark:bg-blue-500/10">
                    <p class="text-sm font-semibold text-blue-800 dark:text-blue-300">My due callbacks</p>
                    <ul class="mt-3 flex flex-col gap-3">
                        @foreach ($dueCallbacks as $callback)
                            <li class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $callback['leadName'] ?? 'Unnamed lead' }}
                                        <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $callback['phone'] }}</span>
                                    </p>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        @if ($callback['campaign'])
                                            <span>{{ $callback['campaign'] }}</span>
                                        @endif
                                        <span>Due <span x-text="formatDue(@js($callback['scheduledAtIso']))"></span></span>
                                        @if ($callback['notes'])
                                            <span class="italic">“{{ $callback['notes'] }}”</span>
                                        @endif
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    x-on:click="dialCallback({{ $callback['id'] }})"
                                    class="inline-flex items-center self-start rounded-lg bg-green-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-green-500 sm:self-auto"
                                >
                                    Dial
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- B2.0 pooled callbacks: "call me back" notes any free agent in the
                 client can take (the row has no owner). Grab claims it atomically
                 (claimCallback) — on a win it moves into "My due callbacks" above.
                 Light auto-refresh (wire:poll.visible) keeps the list live: a row
                 another agent grabs drops off here, and a freshly pooled one
                 appears — but ONLY while this panel is on screen. During a call the
                 whole ready block is hidden, so the poll pauses and never disturbs
                 a live call (the reason it polls on .visible, not always). Rendered
                 even when empty so the poll keeps running for an idle agent. --}}
            @php($pooledCallbacks = $this->pooledCallbacks())
            <div
                wire:poll.15s.visible
                class="mb-6 rounded-lg border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-500/20 dark:bg-indigo-500/10"
            >
                <p class="text-sm font-semibold text-indigo-800 dark:text-indigo-300">Pooled callbacks</p>
                @if (count($pooledCallbacks) > 0)
                    <ul class="mt-3 flex flex-col gap-3">
                        @foreach ($pooledCallbacks as $callback)
                            <li class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $callback['leadName'] ?? 'Unnamed lead' }}
                                        <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $callback['phone'] }}</span>
                                    </p>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        @if ($callback['campaign'])
                                            <span>{{ $callback['campaign'] }}</span>
                                        @endif
                                        <span>Due <span x-text="formatDue(@js($callback['scheduledAtIso']))"></span></span>
                                        @if ($callback['notes'])
                                            <span class="italic">“{{ $callback['notes'] }}”</span>
                                        @endif
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    x-on:click="grabCallback({{ $callback['id'] }})"
                                    class="inline-flex items-center self-start rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 sm:self-auto"
                                >
                                    Grab
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-1 text-xs text-indigo-700/70 dark:text-indigo-300/60">No callbacks waiting in the pool.</p>
                @endif
            </div>

            <label for="campaign" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Campaign</label>
            <select
                id="campaign"
                wire:model.live="selectedCampaignId"
                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-white sm:max-w-xs"
            >
                <option value="">Select a campaign…</option>
                @foreach ($this->campaignOptions() as $campaignId => $campaignName)
                    <option value="{{ $campaignId }}">{{ $campaignName }}</option>
                @endforeach
            </select>

            @if ($this->selectedCampaignId)
                @php($served = $this->servedLead())

                @if ($served)
                    <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Next lead</p>
                            <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                                {{ $served['name'] ?? 'Unnamed lead' }}
                            </p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $served['phone'] }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                                @if ($served['campaign'])
                                    <span>{{ $served['campaign'] }}</span>
                                @endif
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $served['status'] }}</span>
                                @if ($served['lastDisposition'])
                                    <span>Last: {{ $served['lastDisposition'] }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                x-on:click="dial()"
                                class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500"
                            >
                                Dial
                            </button>
                            <button
                                type="button"
                                x-on:click="skip()"
                                class="inline-flex items-center rounded-lg bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-300 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/20"
                            >
                                Skip
                            </button>
                        </div>
                    </div>
                @else
                    <p class="mt-5 text-sm text-gray-500 dark:text-gray-400">No callable leads in this campaign.</p>
                @endif
            @else
                <p class="mt-5 text-sm text-gray-500 dark:text-gray-400">Pick a campaign to start dialing.</p>
            @endif

            {{-- CP-O3 D3: ad-hoc dialing — a one-off typed number, not a served
                 lead. Rendered only for an agent with the dial-adhoc permission
                 (the server gate on dialAdhoc() is the real wall). Do-Not-Call
                 still applies (O1). An ad-hoc call has no lead, so its wrap-up logs
                 a lead-less call row (B3 D5) when the agent clicks Done. --}}
            @if ($this->canDialAdhoc())
                <div class="mt-6 border-t border-gray-200 pt-5 dark:border-white/10">
                    <label for="adhoc" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Dial a number</label>
                    <div class="mt-1 flex items-center gap-2">
                        <input
                            id="adhoc"
                            type="tel"
                            x-model="adhocNumber"
                            x-on:keydown.enter.prevent="dialAdhoc()"
                            placeholder="e.g. 9991234567"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-white sm:max-w-xs"
                        />
                        <button
                            type="button"
                            x-on:click="dialAdhoc()"
                            :disabled="! adhocNumber.trim()"
                            class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Dial
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">One-off call to a typed number. Do-Not-Call still applies.</p>
                </div>
            @endif
        </div>

        {{-- B2.2a (PD-2/PD-3): on break. An away state the system won't ring — a
             call that arrives while here is auto-declined at the phone (busy flag),
             never shown. "I'm back" returns to ready and the board flips back. --}}
        <div
            x-show="state === 'onBreak'"
            x-cloak
            class="mt-4 rounded-xl border border-orange-200 bg-orange-50 p-6 shadow-sm dark:border-orange-500/20 dark:bg-orange-500/10"
        >
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-base font-semibold text-orange-800 dark:text-orange-300">On break</p>
                    <p class="mt-1 text-sm text-orange-700/80 dark:text-orange-300/70">You won't be rung while you're on break.</p>
                </div>
                <button
                    type="button"
                    x-on:click="endBreak()"
                    class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500"
                >
                    I'm back
                </button>
            </div>
        </div>

        {{-- B4 CP2a/CP2b + B-outbound CP-O1: the call panel. Inbound: the app
             dials this agent -> 'incoming' -> ringing, Answer -> on-call. Outbound:
             Dial auto-answers the agent leg -> 'calling' (customer ringing) ->
             'onCall' once bridged. Either hang-up returns to ready (CP3 makes that
             last hop wrap-up). The matched/served lead (D4) and a mute toggle show
             here; the far-side voice plays through the hidden <audio> sink below. --}}
        <div
            x-show="state === 'ringing' || state === 'calling' || state === 'onCall'"
            x-cloak
            class="mt-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p
                        class="text-sm text-gray-500 dark:text-gray-400"
                        x-text="state === 'ringing' ? 'Incoming call' : (state === 'calling' ? 'Calling…' : 'On call')"
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

        {{-- B4 CP3: wrap-up. An *answered* call that ended lands here. Matched
             lead -> pick a disposition + Save (writes last outcome + attempt +
             forward status, audited, behind the narrow record-call-outcome gate).
             No match -> Done logs a lead-less call row (B3) + the miss; the calls
             row is written either way (D2), so Done must be clicked to log it. --}}
        <div
            x-show="state === 'wrapUp'"
            x-cloak
            class="mt-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900"
        >
            <p class="text-sm text-gray-500 dark:text-gray-400">Wrap-up</p>

            {{-- Matched lead: the disposition picker. --}}
            <template x-if="lead">
                <div class="mt-1">
                    <p
                        class="text-lg font-semibold text-gray-950 dark:text-white"
                        x-text="lead.name || 'Unnamed lead'"
                    ></p>
                    <p class="text-sm text-gray-500 dark:text-gray-400" x-text="callerNumber"></p>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <select
                            x-model="selectedDisposition"
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-white sm:max-w-xs"
                        >
                            <option value="">Choose an outcome…</option>
                            <template x-for="[id, label] in Object.entries(dispositions)" :key="id">
                                <option :value="id" x-text="label"></option>
                            </template>
                        </select>

                        <button
                            type="button"
                            x-on:click="saveWrapUp()"
                            :disabled="! selectedDisposition || saving || (isCallbackSelected() && ! callbackAt)"
                            class="inline-flex items-center justify-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500 disabled:cursor-not-allowed disabled:opacity-50"
                            x-text="saving ? 'Saving…' : 'Save'"
                        ></button>
                    </div>

                    {{-- M4 callback capture: revealed only when the picked outcome
                         schedules a callback (a CALLBACK-coded disposition). The
                         date/time is required (Save stays disabled without it); the
                         note is optional. The server validates + creates the row. --}}
                    <div x-show="isCallbackSelected()" x-cloak class="mt-4 flex flex-col gap-3 sm:max-w-xs">
                        <div>
                            <label for="callbackAt" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Call back at</label>
                            <input
                                id="callbackAt"
                                type="datetime-local"
                                x-model="callbackAt"
                                :min="minCallbackLocal()"
                                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                            />
                        </div>
                        <div>
                            <label for="callbackNotes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Note <span class="text-gray-400">(optional)</span></label>
                            <textarea
                                id="callbackNotes"
                                x-model="callbackNotes"
                                rows="2"
                                placeholder="e.g. prefers evenings"
                                class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                            ></textarea>
                        </div>

                        {{-- B2.0 PC-1: who can take this callback. Off (default) keeps
                             it for me; on lets any free agent grab it from the pool. --}}
                        <label for="callbackPooled" class="flex items-start gap-2">
                            <input
                                id="callbackPooled"
                                type="checkbox"
                                x-model="callbackPooled"
                                class="mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm dark:border-white/10 dark:bg-gray-800"
                            />
                            <span class="text-sm text-gray-700 dark:text-gray-300">
                                Let any free agent take this callback
                                <span class="block text-xs text-gray-400">Leave off to keep it for yourself.</span>
                            </span>
                        </label>
                    </div>
                </div>
            </template>

            {{-- No matching lead: nothing is recorded; just close the call out. --}}
            <template x-if="! lead">
                <div class="mt-1">
                    <p
                        class="text-lg font-semibold text-gray-950 dark:text-white"
                        x-text="callerNumber || 'Unknown number'"
                    ></p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">No matching lead — the call is still logged</p>

                    <button
                        type="button"
                        x-on:click="completeUnmatched()"
                        :disabled="saving"
                        class="mt-4 inline-flex items-center justify-center rounded-lg bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-300 disabled:opacity-50 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/20"
                        x-text="saving ? 'Saving…' : 'Done'"
                    ></button>
                </div>
            </template>
        </div>

        {{-- The caller's voice plays here; hidden, but audio still flows (D2). --}}
        <audio x-ref="remoteAudio" autoplay class="hidden"></audio>
    </div>
</x-filament-panels::page>
