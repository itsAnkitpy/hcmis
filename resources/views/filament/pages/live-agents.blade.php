<x-filament-panels::page>
    {{-- LB-4: the board re-asks the server every 15s so a supervisor watching it sees
         agents move between states without touching anything. The whole roster + header
         recompute on each poll (roster() below). --}}
    <div wire:poll.15s>
        @php
            $rows = $this->roster();
            $summary = $this->summary($rows);
        @endphp

        {{-- The header chips (LB): head count · a per-state tally · a red "over break"
             count when someone has run past their limit (BK-4: flag, never force). --}}
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $summary['total'] }} on the floor</span>
            @foreach ($summary['statuses'] as $chip)
                <x-filament::badge :color="$chip['color']">{{ $chip['label'] }}: {{ $chip['count'] }}</x-filament::badge>
            @endforeach
            @if ($summary['overstayed'] > 0)
                <x-filament::badge color="danger">{{ $summary['overstayed'] }} over break</x-filament::badge>
            @endif
        </div>

        <x-filament::section>
            <x-slot name="heading">Agents on the floor</x-slot>
            <x-slot name="description">Most-actionable first · red means a break has run past its limit · refreshes every 15 seconds</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="px-3 py-2 font-medium" style="text-align:left">Agent</th>
                            <th class="px-3 py-2 font-medium" style="text-align:left">Status</th>
                            <th class="px-3 py-2 font-medium" style="text-align:left">For</th>
                            <th class="px-3 py-2 font-medium" style="text-align:left">Break</th>
                            <th class="px-3 py-2 font-medium" style="text-align:right">Calls today</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="px-3 py-2 font-medium" style="text-align:left">{{ $row['name'] }}</td>
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-gray-400" style="text-align:center">
                                    No agents are on the floor right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
