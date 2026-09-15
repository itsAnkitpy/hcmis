<x-filament-panels::page>
    @vite('resources/js/supervisor-phone.js')

    {{-- SM-2: the supervisor's own phone, registered here rather than on a screen of
         its own — this page is already the team leader's floor board, already walled to
         the right audience, and already lists who is on a call. The listen / whisper /
         barge controls (slices 2-4) land in this same panel.

         🔴 OUTSIDE the polled block below, for the same reason the call clock is: the
         board replaces its whole contents every 15 seconds, and a registered SIP phone
         inside it would be torn down and rebuilt on every tick.

         Anyone with no phone issued to them sees nothing at all — the panel is not an
         error, it is simply absent, because most people who can read this board have no
         reason to hold a phone. --}}
    <div x-data="supervisorPhone(@js($this->getPhoneConfig()))" x-show="state !== 'none'" x-cloak
         x-on:monitoring-started.window="mode = $event.detail.mode"
         class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-gray-900">
        <span class="flex items-center gap-2 font-medium text-gray-950 dark:text-white">
            <span class="size-2 rounded-full"
                  :class="state === 'listening' && mode === 'whisper' ? 'bg-warning-500' : (state === 'ready' || state === 'listening' ? 'bg-success-500' : 'bg-gray-400')"></span>
            Your phone
        </span>
        {{-- SM slice 3: the live line has to say which mode it is in, because the two make
             OPPOSITE promises about who can hear you. A single reassuring sentence covering
             both would be wrong half the time, and it is wrong in the direction that matters
             — a supervisor who believes they are silent while the agent can hear them. --}}
        <span class="text-gray-500 dark:text-gray-400"
              x-text="state === 'listening'
                  ? (mode === 'whisper'
                      ? 'Coaching. The agent hears you; the customer does not.'
                      : 'Listening in. The agent and the customer cannot hear you.')
                  : ({
                      ready: 'Registered on {{ $this->getPhoneConfig()['extension'] }} — ready to listen in.',
                      offline: 'Connecting…',
                  }[state] ?? 'Connecting…')"></span>
        <span x-show="error" x-cloak class="text-danger-600 dark:text-danger-400"
              x-text="'Registration failed: ' + error"></span>

        {{-- SM slice 2: stopping is hanging up, so the button is purely browser-side —
             no second signal, no second verb on the listener. The leg ending is what
             releases the tap and folds the mixer, which is also what happens if this tab
             is simply closed. --}}
        <x-filament::button
            x-show="state === 'listening'"
            x-cloak
            x-on:click="phone.hangup()"
            size="xs"
            color="danger"
            outlined
            x-text="mode === 'whisper' ? 'Stop coaching' : 'Stop listening'"
        ></x-filament::button>

        {{-- The far side's voice plays here; hidden, but audio still flows (D2). Nothing
             rings this phone in slice 1 — the sink is what slice 2's listen leg needs. --}}
        <audio x-ref="supervisorAudio" autoplay class="hidden"></audio>
    </div>

    {{-- The running clock behind the "For" column, copied from the agent console's own
         strip (agent-console.js: `now` + formatClock) so a duration is spelled the same
         on the agent's screen and the supervisor's — 4:07, or 1:02:07 past the hour.

         Three deliberate details:
          - it lives OUTSIDE the polled block, so the 15-second refresh can never replace
            the element and leave a second timer running behind the first;
          - `skew` corrects the browser's clock against the server's once, at page load,
            so a laptop whose clock is five minutes out still shows the true duration;
          - the tick only re-reads the wall clock. Nothing is counted up, so a tab that
            Chrome throttles in the background (the S93 lesson) is right again the moment
            it is looked at, and no server traffic rides this at all. --}}
    <div
        x-data="{
            now: Date.now(),
            skew: 0,
            clock(startedAtMs) {
                const total = Math.max(0, Math.floor((this.now - this.skew - startedAtMs) / 1000));
                const pad = (n) => String(n).padStart(2, '0');
                const hours = Math.floor(total / 3600);
                const minutes = Math.floor((total % 3600) / 60);

                return hours > 0
                    ? hours + ':' + pad(minutes) + ':' + pad(total % 60)
                    : minutes + ':' + pad(total % 60);
            },
        }"
        x-init="skew = Date.now() - {{ now()->getTimestampMs() }}; setInterval(() => (now = Date.now()), 1000)"
    >
    {{-- LB-4: the board re-asks the server every 15s so a supervisor watching it sees
         agents move between states without touching anything. The whole roster + header
         recompute on each poll (roster() below). --}}
    <div wire:poll.15s>
        @php
            $rows = $this->roster();
            $stuck = $this->stuckRoster();
            $tabs = $this->tabs($rows, count($stuck));
            $overstayed = count(array_filter($rows, fn (array $row): bool => $row['overstayed']));
            $rows = $this->visibleRows($rows);
            $onStuckTab = $this->tab === 'stuck';
            // SM slice 2: the last column now carries two different buttons. It shows for
            // anyone who can use either — a QC with no phone still sees nothing extra.
            $showsActions = $this->canForceLogOut() || $this->hasPhone();
        @endphp

        {{-- The tab strip (LB-9): the head count first, then one tab per state, each
             carrying its own count. Clicking one narrows the table below — nothing is
             re-fetched. Beside it, the red "over break" count when someone has run past
             their limit (BK-4: flag, never force). --}}
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <x-filament::tabs>
                @foreach ($tabs as $tabItem)
                    <x-filament::tabs.item
                        :active="$this->tab === $tabItem['key']"
                        :badge="$tabItem['count']"
                        wire:click="$set('tab', '{{ $tabItem['key'] }}')"
                    >{{ $tabItem['label'] }}</x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
            @if ($overstayed > 0)
                <x-filament::badge color="danger">{{ $overstayed }} over break</x-filament::badge>
            @endif

            {{-- LB-14: the browser's own fullscreen, the same thing F11 does, so the board
                 can go up on a TV and be read across the room. The page updates in place
                 rather than reloading, so the 15-second refresh does not drop out of it. --}}
            <div class="ml-auto" x-data>
                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-m-arrows-pointing-out"
                    x-on:click="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()"
                >Fullscreen</x-filament::button>
            </div>
        </div>

        {{-- Call Stats CS-5: the three numbers about the PHONE LINES, in their own
             labelled group so they never read as being about the people in the tabs
             above. Active and the "On a call" tab can legitimately disagree — the tab
             counts agents whose own screen says they are on a call, this counts calls the
             phone engine is carrying — and the label is what keeps that from becoming a
             support ticket.

             CS-7: when the phone service is not reporting, say so. Three zeros on a calm
             floor and three zeros on a dead listener look the same, and the second one is
             the situation somebody has to act on. --}}
        @php $callStats = $this->callStats(); @endphp
        <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
            <span class="font-medium text-gray-500 dark:text-gray-400">Calls right now</span>
            @if ($callStats === null)
                <span class="text-gray-400">&mdash; phone service not reporting</span>
            @else
                <span class="tabular-nums">Active <span class="font-semibold">{{ $callStats['active'] }}</span></span>
                <span class="text-gray-400">&middot;</span>
                <span class="tabular-nums">Ringing <span class="font-semibold">{{ $callStats['ringing'] }}</span></span>
                <span class="text-gray-400">&middot;</span>
                <span class="tabular-nums">Waiting <span class="font-semibold">{{ $callStats['waiting'] }}</span></span>

                {{-- LW-4: how long the caller who has waited longest has been holding. Shown
                     only when somebody actually is — an empty floor has no clock to show, and
                     "0:00" beside "Waiting 0" is the same fact written twice.

                     The seconds are worked out HERE, on the server, from the arrival moment
                     in the note, and the browser then counts on from that number by one a
                     second. Counting up from a number the server gave us rather than from a
                     timestamp the browser reads means a TV whose own clock is wrong still
                     shows the right wait — only the ticking is the browser's, never the
                     starting point. Without the tick the clock would sit frozen for fifteen
                     seconds at a time, which on a wall screen reads as broken in a way a
                     frozen COUNT never does.

                     LW-7: amber past 3 minutes, red past 5. The colour rides the SAME
                     ticking number the text does, so it turns over on the floor without
                     waiting for the next page refresh. --}}
                @if ($callStats['oldestWaitingAt'] !== null)
                    <span class="text-gray-400">&middot;</span>
                    <span class="tabular-nums"
                          x-data="{ held: {{ max(0, now()->getTimestamp() - $callStats['oldestWaitingAt']) }} }"
                          x-init="setInterval(() => held++, 1000)"
                          :class="held >= 300 ? 'text-red-600 dark:text-red-400' : (held >= 180 ? 'text-amber-600 dark:text-amber-400' : '')">
                        Longest wait <span class="font-semibold"
                            x-text="Math.floor(held / 60) + ':' + String(held % 60).padStart(2, '0')">&nbsp;</span>
                    </span>
                @endif
            @endif
        </div>

        {{-- LB-6: the Stuck tab is its own, smaller table — these people are not on the
             floor, so the floor's columns (break detail, calls today) say nothing useful
             about them. What a team leader needs is who, what the board still claims they
             are doing, and how long ago their screen last spoke. --}}
        @if ($onStuckTab)
            <x-filament::section>
                <x-slot name="heading">Screens that have gone quiet</x-slot>
                <x-slot name="description">Their screen stopped answering but the board still shows them working · longest-quiet first · last 12 hours</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="px-3 py-2 font-medium" style="text-align:left">Agent</th>
                                <th class="px-3 py-2 font-medium" style="text-align:left">Board still says</th>
                                <th class="px-3 py-2 font-medium" style="text-align:left">Last responded</th>
                                @if ($this->canForceLogOut())
                                    <th class="px-3 py-2 font-medium" style="text-align:right">&nbsp;</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($stuck as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="px-3 py-2 font-medium" style="text-align:left">
                                        @if ($row['id'] !== null)
                                            <a
                                                href="{{ \App\Filament\Pages\AgentDetail::getUrl(['record' => $row['id']]) }}"
                                                class="text-primary-600 hover:underline dark:text-primary-400"
                                            >{{ $row['name'] }}</a>
                                        @else
                                            {{ $row['name'] }}
                                        @endif
                                    </td>
                                    <td class="px-3 py-2" style="text-align:left">
                                        <x-filament::badge color="gray">{{ $row['statusLabel'] }}</x-filament::badge>
                                    </td>
                                    <td class="px-3 py-2 tabular-nums text-gray-500 dark:text-gray-400" style="text-align:left">
                                        @php $quiet = $row['quietForMinutes']; @endphp
                                        {{ $quiet >= 60 ? intdiv($quiet, 60) . 'h ' . ($quiet % 60) . 'm' : $quiet . 'm' }} ago
                                    </td>
                                    {{-- LB-8/LB-12: team leaders and our own global staff only, never on a
                                         row still showing a live call, and the confirmation carries the one
                                         fact the manager needs — how long ago that screen last spoke. --}}
                                    @if ($this->canForceLogOut())
                                        <td class="px-3 py-2" style="text-align:right">
                                            @if ($row['canLogOut'])
                                                <x-filament::button
                                                    size="xs"
                                                    color="danger"
                                                    outlined
                                                    wire:click="forceLogOut({{ $row['id'] }})"
                                                    wire:confirm="{{ $row['name'] }} last responded {{ $quiet }} minutes ago. If they are still working, this will cut them off."
                                                >Log out</x-filament::button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $this->canForceLogOut() ? 4 : 3 }}" class="px-3 py-6 text-gray-400" style="text-align:center">
                                        Every screen is answering.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @else
        <x-filament::section>
            <x-slot name="heading">Agents on the floor</x-slot>
            <x-slot name="description">Most-actionable first · red means a break has run past its limit · refreshes every 15 seconds</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        {{-- LB-11: three headings sort, two do not. Status would just
                             return the order the page already opens in, and Break is
                             mostly dashes.

                             Every sortable heading carries a faint up-down mark even when
                             nothing is sorted — otherwise the sorting is a feature nobody
                             can see. The heading in use swaps it for the direction it is
                             sorted in, in the link colour. --}}
                        @php
                            $mark = fn (string $key): string => $this->sort === $key
                                ? ($this->sortDirection === 'asc' ? '▲' : '▼')
                                : '↕';
                            $markClass = fn (string $key): string => $this->sort === $key
                                ? 'text-primary-600 dark:text-primary-400'
                                : 'text-gray-400';
                        @endphp
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="px-3 py-2 font-medium" style="text-align:left">
                                <button type="button" wire:click="sortBy('name')" class="inline-flex cursor-pointer items-center gap-1 font-medium hover:text-primary-600 dark:hover:text-primary-400">
                                    Agent <span class="text-xs {{ $markClass('name') }}">{{ $mark('name') }}</span>
                                </button>
                            </th>
                            {{-- LB-13: our own global staff see every client's agents in one
                                 list, so they get a column saying whose each one is — and
                                 sorting by it groups the list by client without building
                                 grouping. A team leader's list is all their own people. --}}
                            @if ($this->showsClient())
                                <th class="px-3 py-2 font-medium" style="text-align:left">
                                    <button type="button" wire:click="sortBy('client')" class="inline-flex cursor-pointer items-center gap-1 font-medium hover:text-primary-600 dark:hover:text-primary-400">
                                        Client <span class="text-xs {{ $markClass('client') }}">{{ $mark('client') }}</span>
                                    </button>
                                </th>
                            @endif
                            <th class="px-3 py-2 font-medium" style="text-align:left">Status</th>
                            <th class="px-3 py-2 font-medium" style="text-align:left">
                                <button type="button" wire:click="sortBy('inStatusMinutes')" class="inline-flex cursor-pointer items-center gap-1 font-medium hover:text-primary-600 dark:hover:text-primary-400">
                                    For <span class="text-xs {{ $markClass('inStatusMinutes') }}">{{ $mark('inStatusMinutes') }}</span>
                                </button>
                            </th>
                            <th class="px-3 py-2 font-medium" style="text-align:left">Break</th>
                            <th class="px-3 py-2 font-medium" style="text-align:right">
                                <button type="button" wire:click="sortBy('callsToday')" class="inline-flex cursor-pointer items-center gap-1 font-medium hover:text-primary-600 dark:hover:text-primary-400">
                                    Calls today <span class="text-xs {{ $markClass('callsToday') }}">{{ $mark('callsToday') }}</span>
                                </button>
                            </th>
                            @if ($showsActions)
                                <th class="px-3 py-2 font-medium" style="text-align:right">&nbsp;</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="px-3 py-2 font-medium" style="text-align:left">
                                    @if ($row['id'] !== null)
                                        {{-- AD-1 entry point: click an agent → their Agent Detail day view. The
                                             board's own gate (Call viewAny) already matches Agent Detail's, so
                                             every name here is a link the viewer is allowed to open. --}}
                                        <a
                                            href="{{ \App\Filament\Pages\AgentDetail::getUrl(['record' => $row['id']]) }}"
                                            class="text-primary-600 hover:underline dark:text-primary-400"
                                        >{{ $row['name'] }}</a>
                                    @else
                                        {{ $row['name'] }}
                                    @endif
                                </td>
                                @if ($this->showsClient())
                                    <td class="px-3 py-2 text-gray-500 dark:text-gray-400" style="text-align:left">{{ $row['client'] }}</td>
                                @endif
                                <td class="px-3 py-2" style="text-align:left">
                                    <x-filament::badge :color="$row['statusColor']">{{ $row['statusLabel'] }}</x-filament::badge>
                                </td>
                                <td class="px-3 py-2 tabular-nums text-gray-500 dark:text-gray-400" style="text-align:left">
                                    @php $minutes = $row['inStatusMinutes']; @endphp
                                    {{-- The server-rendered figure stays inside the span as the
                                         fallback: Alpine overwrites it on init, but if the page's
                                         JavaScript never runs, the most-watched column on the board
                                         still reads correctly instead of going blank. --}}
                                    @if ($row['startedAtMs'] !== null)
                                        <span x-text="clock({{ $row['startedAtMs'] }})">{{ $minutes >= 60 ? intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm' : $minutes . 'm' }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2" style="text-align:left">
                                    @if ($row['breakCategory'] !== null)
                                        <span @class([
                                            'tabular-nums',
                                            'font-semibold text-red-600 dark:text-red-400' => $row['overstayed'],
                                            'text-gray-500 dark:text-gray-400' => ! $row['overstayed'],
                                        ])>
                                            {{ $row['breakCategory'] }}
                                            @if ($row['limitMinutes'] !== null)
                                                &middot; {{ $row['inStatusMinutes'] }} of {{ $row['limitMinutes'] }} min
                                            @else
                                                &middot; {{ $row['inStatusMinutes'] }} min
                                            @endif
                                            @if ($row['overstayed'])
                                                &mdash; over the limit
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 tabular-nums" style="text-align:right">{{ $row['callsToday'] }}</td>
                                {{-- LB-8/LB-12: the same button as the Stuck tab, for the case that
                                     tab can never catch — an agent who went home leaving the machine
                                     on. Their screen keeps punching, so the board keeps them Ready
                                     and the router keeps ringing a dead desk. Never on a live call. --}}
                                @if ($showsActions)
                                    <td class="px-3 py-2 whitespace-nowrap" style="text-align:right">
                                        {{-- SM slice 2: the listen-in the page's own docblock
                                             reserved a column for. Only on a row that is mid-call,
                                             and only for a reader holding a phone of their own —
                                             otherwise it would be a button that does nothing. --}}
                                        @if ($this->hasPhone() && $row['canMonitor'])
                                            <x-filament::button
                                                size="xs"
                                                color="gray"
                                                outlined
                                                icon="heroicon-m-signal"
                                                wire:click="listenTo({{ $row['id'] }})"
                                            >Listen</x-filament::button>
                                            {{-- SM slice 3. Warning-coloured, not grey: unlike Listen
                                                 this one opens the supervisor's microphone onto a live
                                                 call, and the two buttons sit side by side. Not danger
                                                 either — that colour belongs to force-logout in the
                                                 same column, and coaching an agent is not destructive. --}}
                                            <x-filament::button
                                                size="xs"
                                                color="warning"
                                                outlined
                                                icon="heroicon-m-megaphone"
                                                wire:click="whisperTo({{ $row['id'] }})"
                                            >Whisper</x-filament::button>
                                        @endif
                                        @if ($this->canForceLogOut() && $row['canLogOut'])
                                            {{-- Outlined, not solid: on a twenty-agent floor a
                                                 column of solid red pulls the eye away from the
                                                 over-break flag, which is the thing that actually
                                                 needs attention. Still unmistakably destructive. --}}
                                            <x-filament::button
                                                size="xs"
                                                color="danger"
                                                outlined
                                                wire:click="forceLogOut({{ $row['id'] }})"
                                                wire:confirm="{{ $row['name'] }} has been {{ strtolower($row['statusLabel']) }} for {{ $row['inStatusMinutes'] }} minutes. If they are still working, this will cut them off."
                                            >Log out</x-filament::button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ ($this->showsClient() ? 6 : 5) + ($showsActions ? 1 : 0) }}" class="px-3 py-6 text-gray-400" style="text-align:center">
                                    {{ $this->tab === 'floor' ? 'No agents are on the floor right now.' : 'Nobody is in this state right now.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
        @endif
    </div>
    </div>
</x-filament-panels::page>
