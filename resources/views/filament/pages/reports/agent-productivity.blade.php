<x-filament-panels::page>
    {{-- RP-5: shared date-range/campaign/direction filter form; ->live() so the
         table below recomputes as filters change. --}}
    {{ $this->filtersForm }}

    <x-filament::section>
        <x-slot name="heading">Agent productivity</x-slot>
        <x-slot name="description">Call counts and outcome mix per agent for the selected dates.</x-slot>

        {{-- Alignment is set inline (not via text-start/-end utilities): those are
             compiled into the theme at build time, so a brand-new view can misalign
             until the CSS is rebuilt. Inline text-align is deterministic. --}}
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="px-3 py-2 font-medium" style="text-align:left">Agent</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Total</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Inbound</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Outbound</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Contacts</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Sales</th>
                        {{-- RP-5: No-answer is the one PROVISIONAL column (agent-reported
                             outcome, overridden at the trunk); Contact rate is reliable. --}}
                        <th class="px-3 py-2 font-medium" style="text-align:right">
                            No-answer
                            <span class="block text-xs font-normal text-gray-400">provisional</span>
                        </th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">With recording</th>
                        <th class="px-3 py-2 font-medium" style="text-align:right">Contact rate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows() as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2 font-medium" style="text-align:left">{{ $row['agent'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['total'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['inbound'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['outbound'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['contacts'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['sales'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['no_answer'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['with_recording'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ number_format($row['contact_rate'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-6 text-gray-400" style="text-align:center">
                                No calls in the selected range.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
