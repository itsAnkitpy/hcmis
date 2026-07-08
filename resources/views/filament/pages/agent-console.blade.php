<x-filament-panels::page>
    @vite('resources/js/agent-console.js')

    {{-- B4 CP1: the page registers as a browser phone; the strip flips
         offline -> ready on its own off the phone's events (D3).

         BK-3/BK-4 (+ the S72 status-strip fold-in): the small pill grew into the
         STATUS STRIP — one full-width colored bar carrying the agent's status,
         the time in it, and the one action that makes sense right now (Take a
         break / I'm back). On break the clock counts down the picked type's
         limit; past it the strip flips red and shouts — visual only, nobody is
         ever auto-returned (BK-4). Break types come server-rendered from
         breakCategoryOptions() (active, in order, own client).

         BK-7: resumableBreak() rides in the same way — a still-fresh on-break
         board row means this load RESUMES the break (same type, countdown on
         the original start) instead of auto-pushing Ready. --}}
    <div x-data="agentConsole(@js($this->getPhoneConfig()), @js($this->breakCategoryOptions()), @js($this->resumableBreak()))" class="mx-auto w-full max-w-2xl">
        <div
            class="rounded-xl border p-5 shadow-sm"
            :class="{
                'border-red-300 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10': error || isBreakOverdue(),
                'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900': ! error && state === 'offline',
                'border-green-200 bg-green-50 dark:border-green-500/20 dark:bg-green-500/10': ! error && state === 'ready',
                'border-blue-200 bg-blue-50 dark:border-blue-500/20 dark:bg-blue-500/10': ! error && (state === 'ringing' || state === 'calling' || state === 'onCall'),
                'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10': ! error && state === 'wrapUp',
                'border-orange-200 bg-orange-50 dark:border-orange-500/20 dark:bg-orange-500/10': ! error && state === 'onBreak' && ! isBreakOverdue(),
            }"
        >
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
                <div class="flex items-center gap-3">
                    <span class="relative flex h-3 w-3">
                        {{-- The overstay ping: a slow red pulse you can't miss (BK-4's shout). --}}
                        <span
                            x-show="isBreakOverdue()"
                            x-cloak
                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"
                        ></span>
                        <span
                            class="relative inline-flex h-3 w-3 rounded-full"
                            :class="{
                                'bg-red-500': error || isBreakOverdue(),
                                'bg-gray-400': ! error && state === 'offline',
                                'bg-green-500': ! error && state === 'ready',
                                'bg-blue-500': ! error && (state === 'ringing' || state === 'calling' || state === 'onCall'),
                                'bg-amber-500': ! error && state === 'wrapUp',
                                'bg-orange-500': ! error && state === 'onBreak' && ! isBreakOverdue(),
                            }"
                        ></span>
                    </span>

                    <div>
                        <p
                            class="text-xl font-bold"
                            :class="{
                                'text-red-800 dark:text-red-300': error || isBreakOverdue(),
                                'text-gray-500 dark:text-gray-400': ! error && state === 'offline',
                                'text-green-800 dark:text-green-300': ! error && state === 'ready',
                                'text-blue-800 dark:text-blue-300': ! error && (state === 'ringing' || state === 'calling' || state === 'onCall'),
                                'text-amber-800 dark:text-amber-300': ! error && state === 'wrapUp',
                                'text-orange-800 dark:text-orange-300': ! error && state === 'onBreak' && ! isBreakOverdue(),
                            }"
                            x-text="error ? 'Registration failed' : stripTitle()"
                        ></p>
                        <p x-show="state !== 'onBreak'" class="text-sm text-gray-500 dark:text-gray-400">
                            Extension {{ $this->getPhoneConfig()['extension'] ?? '—' }}
                        </p>
                        <p x-show="state === 'onBreak'" x-cloak class="text-sm text-gray-500 dark:text-gray-400">
                            You won't be rung while you're on break.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    {{-- The strip's clock: time in this status; on break, the countdown
                         (down to the limit, then the overrun back up — BK-3). --}}
                    <div x-show="state !== 'offline'" class="text-right">
                        <p
                            class="text-2xl font-bold tabular-nums text-gray-950 dark:text-white"
                            x-text="state === 'onBreak' ? breakClock() : formatClock(statusSeconds())"
                        ></p>
                        <p
                            class="text-xs text-gray-500 dark:text-gray-400"
                            x-text="state === 'onBreak' ? breakCaption() : 'in this status'"
                        ></p>
                    </div>

                    {{-- B2.2a PD-2: the one manual board control, now living in the strip.
                         Take a break opens the picker when break types exist (BK-3 —
                         required), or goes straight to a plain break when none do. --}}
                    <button
                        type="button"
                        x-show="state === 'ready' && ! breakPickerOpen"
                        x-on:click="startBreak()"
                        class="inline-flex items-center rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/20"
                    >
                        Take a break
                    </button>
                    <button
                        type="button"
                        x-show="state === 'onBreak'"
                        x-cloak
                        x-on:click="endBreak()"
                        class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-500"
                    >
                        I'm back
                    </button>
                </div>
            </div>

            <p x-show="error" x-cloak class="mt-3 text-sm text-red-600 dark:text-red-400">
                <span x-text="error"></span>
            </p>

            {{-- BK-3 break picker: which kind? Active types in display order, each
                 with its limit. Picking one starts the break; Cancel backs out. --}}
            <div
                x-show="breakPickerOpen && state === 'ready'"
                x-cloak
                class="mt-4 border-t border-green-200 pt-4 dark:border-green-500/20"
            >
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">What kind of break?</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <template x-for="category in breakCategories" :key="category.id">
                        <button
                            type="button"
                            x-on:click="chooseBreak(category)"
                            class="inline-flex items-baseline gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-orange-50 hover:ring-orange-300 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/20"
                        >
                            <span x-text="category.label"></span>
                            <span
                                class="text-xs font-normal text-gray-400 dark:text-gray-500"
                                x-text="category.limitMinutes ? category.limitMinutes + ' min' : 'no limit'"
                            ></span>
                        </button>
                    </template>
                    <button
                        type="button"
                        x-on:click="breakPickerOpen = false"
                        class="px-2 py-1.5 text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        Cancel
                    </button>
                </div>
            </div>

            {{-- BK-4: the overstay shout — loud, visual, and nothing more. The system
                 never flips anyone back to Ready; a human ends the break. --}}
            <div
                x-show="isBreakOverdue()"
                x-cloak
                class="mt-4 rounded-lg bg-red-600 px-4 py-3"
            >
                <p class="text-sm font-bold text-white">
                    Break time is up — press “I'm back” when you return.
                </p>
            </div>
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

                    {{-- B2.4a cold transfer: hand the live caller to a free agent. The
                         listener rings the new agent while this agent keeps talking; on
                         success this agent's leg hangs up and the screen moves to wrap-up,
                         on no-answer the button reverts after the ring window (TD-5). --}}
                    <button
                        type="button"
                        x-show="state === 'onCall'"
                        x-on:click="transfer()"
                        :disabled="transferring"
                        class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
                        x-text="transferring ? 'Transferring…' : 'Transfer'"
                    ></button>

                    {{-- B2.4b conference: pull a free agent into a 3-way (CD-3/CD-6). Non-blocking
                         — A keeps full call control and stays on the call; the listener rings the
                         new agent while A keeps talking, and on answer adds them WITHOUT dropping A.
                         The button only disables while its own ring is in flight (one at a time). --}}
                    <button
                        type="button"
                        x-show="state === 'onCall'"
                        x-on:click="conference()"
                        :disabled="conferencing"
                        class="inline-flex items-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-500 disabled:cursor-not-allowed disabled:opacity-60"
                        x-text="conferencing ? 'Ringing…' : 'Conference'"
                    ></button>

                    <button
                        type="button"
                        x-on:click="hangup()"
                        class="inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500"
                        x-text="state === 'ringing' ? 'Decline' : 'Hang up'"
                    ></button>
                </div>
            </div>

            {{-- B2.4a (TD-5): the screen-side transfer feedback. A failed transfer
                 (no-answer / nobody-free) reverts here with a neutral note, since there
                 is no listener->screen signal to tell the two apart. --}}
            <p
                x-show="transferNotice"
                x-cloak
                class="mt-3 text-sm text-amber-600 dark:text-amber-400"
                x-text="transferNotice"
            ></p>

            {{-- B2.4b (CD-6): the conference feedback. A lightweight, non-blocking "ringing
                 to join…" line while B rings; after the ring window a neutral note (there is
                 no listener->screen signal, so success is confirmed by audio — A hears B join). --}}
            <p
                x-show="conferencing"
                x-cloak
                class="mt-3 text-sm text-teal-600 dark:text-teal-400"
            >Ringing an agent to join the call…</p>
            <p
                x-show="conferenceNotice"
                x-cloak
                class="mt-3 text-sm text-amber-600 dark:text-amber-400"
                x-text="conferenceNotice"
            ></p>
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
