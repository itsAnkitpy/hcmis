<x-filament-panels::page>
    {{-- RP-5: shared date-range/agent/campaign/direction filter form. --}}
    {{ $this->filtersForm }}

    {{-- Alignment is inline (see agent-productivity.blade.php note): deterministic
         without a theme rebuild. --}}
    <x-filament::section>
        <x-slot name="heading">Calls by day</x-slot>
        <x-slot name="description">The call mix per calendar day for the selected range.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="px-3 py-2 font-medium" style="text-align:left">Date</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Total</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Inbound</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Outbound</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Contacts</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Sales</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->dailyRows() as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2 font-medium" style="text-align:left">{{ $row['date'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['total'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['inbound'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['outbound'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['contacts'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['sales'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-gray-400" style="text-align:center">
                                No calls in the selected range.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Disposition breakdown</x-slot>
        <x-slot name="description">How the calls were dispositioned, as a share of all calls in range.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="px-3 py-2 font-medium" style="text-align:left">Disposition</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Count</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">% of total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->dispositionRows() as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2 font-medium" style="text-align:left">{{ $row['label'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['count'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ number_format($row['percentage'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-3 py-6 text-gray-400" style="text-align:center">
                                No dispositioned calls in the selected range.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
