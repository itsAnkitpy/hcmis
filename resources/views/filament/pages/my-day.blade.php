<x-filament-panels::page>
    @php
        $tiles = $this->tiles();
        $callbacksDue = $this->callbacksDue();
        $breakMinutes = $this->breakMinutes();
    @endphp

    {{-- MD-3: the six honest tiles — one agentProductivity() row for me + today,
         plus break time from my status history (BK slice 5, the reopened tile).
         No-answer carries the same "provisional" tag the manager reports use. --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Calls today</div>
            <div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ $tiles['total'] }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Contacts</div>
            <div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ $tiles['contacts'] }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Sales</div>
            <div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ $tiles['sales'] }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Contact rate</div>
            <div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format($tiles['contact_rate'], 1) }}%</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">
                No-answer
                <span class="block text-xs text-gray-400">provisional</span>
            </div>
            <div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ $tiles['no_answer'] }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm text-gray-500 dark:text-gray-400">Break time</div>
            <div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">
                {{ $breakMinutes >= 60 ? intdiv($breakMinutes, 60) . 'h ' . str_pad((string) ($breakMinutes % 60), 2, '0', STR_PAD_LEFT) . 'm' : $breakMinutes . ' min' }}
            </div>
        </div>
    </div>

    {{-- MD-3: the outcome mix of my day — same maths as the managers' disposition
         breakdown, narrowed to me. List over chart: five short rows read faster. --}}
    <x-filament::section>
        <x-slot name="heading">My outcomes today</x-slot>

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
                            <td class="px-3 py-2" style="text-align:right">{{ $row['count'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ number_format($row['percentage'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-3 py-6 text-gray-400" style="text-align:center">
                                No outcomes recorded yet today.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- MD-3: callbacks due — pending, promised for any time up to end of today;
         overdue ones stay in the count until done. --}}
    <x-filament::section>
        <x-slot name="heading">Callbacks due</x-slot>
        <x-slot name="description">Pending call-me-later notes due by end of today, overdue included.</x-slot>

        <div class="text-3xl font-semibold {{ $callbacksDue > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white' }}">
            {{ $callbacksDue }}
        </div>
    </x-filament::section>

    {{-- MD-4: my calls, today, newest first — six columns, play-only (no download:
         a file leaving the system is auditor-only). The <audio> bar is the HD-1
         pattern: preload="none" means nothing is fetched (or audited) until the
         agent presses play; the gated stream route then logs the listen (HD-3). --}}
    <x-filament::section>
        <x-slot name="heading">My calls today</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="px-3 py-2 font-medium" style="text-align:left">Time</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Direction</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Customer</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Campaign</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Outcome</th>
                        {{-- CP-5: the agent's own note, back where they can re-read it.
                             Truncated to keep the row one line; the whole note is in the
                             cell's title, and unabridged on Call Review. --}}
                        <th class="px-3 py-2 font-medium" style="text-align:left">Notes</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Play</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->myCalls() as $call)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2" style="text-align:left">{{ $this->clockAt($call->created_at) }}</td>
                            <td class="px-3 py-2" style="text-align:left">{{ $call->direction->label() }}</td>
                            <td class="px-3 py-2" style="text-align:left">
                                <span class="font-medium">{{ $call->lead?->name ?? '—' }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ ($call->direction === \App\Enums\CallDirection::Outbound ? $call->to_number : $call->from_number) ?? '—' }}
                                </span>
                            </td>
                            <td class="px-3 py-2" style="text-align:left">{{ $call->campaign?->name ?? '—' }}</td>
                            <td class="px-3 py-2" style="text-align:left">{{ $call->outcome?->label() ?? '—' }}</td>
                            <td
                                class="max-w-xs truncate px-3 py-2 text-gray-600 dark:text-gray-400"
                                style="text-align:left"
                                title="{{ $call->notes }}"
                            >{{ $call->notes ?? '—' }}</td>
                            <td class="px-3 py-2" style="text-align:left">
                                @if (filled($call->recording_path))
                                    @include('filament.pages.partials.play-only-recording', ['call' => $call])
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-6 text-gray-400" style="text-align:center">
                                No calls yet today.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
