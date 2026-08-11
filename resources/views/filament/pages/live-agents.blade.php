<x-filament-panels::page>
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
                            @if ($this->canForceLogOut())
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
                                    {{ $minutes >= 60 ? intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm' : $minutes . 'm' }}
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
                                @if ($this->canForceLogOut())
                                    <td class="px-3 py-2" style="text-align:right">
                                        @if ($row['canLogOut'])
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
                                <td colspan="{{ ($this->showsClient() ? 6 : 5) + ($this->canForceLogOut() ? 1 : 0) }}" class="px-3 py-6 text-gray-400" style="text-align:center">
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
</x-filament-panels::page>
