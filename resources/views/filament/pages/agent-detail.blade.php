<x-filament-panels::page>
    @php
        $summary = $this->daySummary();
        $seconds = $summary['seconds'];
    @endphp

    {{-- AD-4: pick any single past day; defaults today. A future date can't be
         chosen (max) and would fall back to today server-side anyway. --}}
    <div class="flex flex-wrap items-center gap-2">
        <label for="agent-detail-date" class="text-sm font-medium text-gray-950 dark:text-white">Day</label>
        <input
            id="agent-detail-date"
            type="date"
            wire:model.live="date"
            max="{{ now()->toDateString() }}"
            class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
        />
    </div>

    {{-- AD-2 §1 / AD-3: the Time Sheet. Five status sums (On-call is honestly
         labelled — it includes ring/hold until the real phone line), plus Active
         (the whole logged-in span) and total calls. No Duration / IP / Server
         columns — those are additive when the trunk lands. --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Wait time</div>
            <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->clock($seconds['ready']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">
                On-call time
                <span class="block text-xs text-gray-400">incl. ring/hold</span>
            </div>
            <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->clock($seconds['on_call']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Wrap-up time</div>
            <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->clock($seconds['wrapping_up']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Pause time</div>
            <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->clock($seconds['on_break']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Active time</div>
            <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->clock($summary['active']) }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Calls</div>
            <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['totalCalls'] }}</div>
        </div>
    </div>

    <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
        <span>First login: <span class="font-medium text-gray-950 dark:text-white">{{ $summary['firstLogin']?->format('H:i') ?? '—' }}</span></span>
        <span>Last activity: <span class="font-medium text-gray-950 dark:text-white">{{ $summary['lastActivity']?->format('H:i') ?? '—' }}</span></span>
    </div>

    {{-- AD-2 §1 DRY win: the day's status timeline absorbs DialShree's two separate
         session views into one. Status · started · ended · duration · how it ended
         (changed = normal next-status close; stale = a dead session closed lazily). --}}
    <x-filament::section>
        <x-slot name="heading">Status timeline</x-slot>
        <x-slot name="description">Every stint on the chosen day, in order.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="px-3 py-2 font-medium" style="text-align:left">Status</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Started</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Ended</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Duration</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">How it ended</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary['timeline'] as $stint)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2" style="text-align:left">
                                <x-filament::badge :color="$stint['statusColor']">{{ $stint['statusLabel'] }}</x-filament::badge>
                                @if ($stint['breakCategory'] !== null)
                                    <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">{{ $stint['breakCategory'] }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 tabular-nums" style="text-align:left">{{ $stint['startedAt']->format('H:i') }}</td>
                            <td class="px-3 py-2 tabular-nums" style="text-align:left">
                                {{ $stint['endedAt']?->format('H:i') ?? 'ongoing' }}
                            </td>
                            <td class="px-3 py-2 tabular-nums" style="text-align:right">{{ $this->clock($stint['durationSeconds']) }}</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400" style="text-align:left">
                                @if ($stint['endedVia'] === \App\Enums\StintEndedVia::Stale)
                                    session ended
                                @elseif ($stint['endedVia'] === \App\Enums\StintEndedVia::Changed)
                                    changed status
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-gray-400" style="text-align:center">
                                No activity recorded on this day.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- AD-2 §2: Outcomes — the disposition mix, same maths as the managers'
         breakdown, narrowed to this agent + day. List over chart (the My Day
         choice): a few short rows read faster. Per-outcome Duration is omitted
         (AD-3, trunk-era additive). --}}
    <x-filament::section>
        <x-slot name="heading">Outcomes</x-slot>
        <x-slot name="description">How this agent's calls turned out on the chosen day.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="px-3 py-2 font-medium" style="text-align:left">Outcome</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Calls</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->outcomes() as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2 font-medium" style="text-align:left">{{ $row['label'] }}</td>
                            <td class="px-3 py-2 tabular-nums" style="text-align:right">{{ $row['count'] }}</td>
                            <td class="px-3 py-2 tabular-nums" style="text-align:right">{{ number_format($row['percentage'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-3 py-6 text-gray-400" style="text-align:center">
                                No dispositioned calls on this day.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
