{{-- Call::asClock() is the house duration format (a clock, or a dash when we do not
     hold the number) — the same one the Calls list prints, so a duration reads the
     same wherever it appears. --}}
@use('App\Models\Call')

<x-filament-panels::page>
    {{-- RP-5: shared date-range/campaign/direction filter form; ->live() so the
         table below recomputes as filters change. AP-12: leaving both date boxes
         blank (or clearing them) reads TODAY, not every record ever — the fallback
         lives in rows(), so a cleared box falls back too. --}}
    {{ $this->filtersForm }}

    <x-filament::section>
        <x-slot name="heading">Agent productivity</x-slot>
        <x-slot name="description">How each agent spent the selected dates, and what they got done.</x-slot>

        {{-- AP-7: ONE wide table, not two stacked ones — a manager comparing agents
             should not have to find the same name twice in two grids, and occupancy
             only reads sensibly beside the call numbers it comes from. It scrolls
             sideways inside its own box.

             Alignment is set inline (not via text-start/-end utilities): those are
             compiled into the theme at build time, so a brand-new view can misalign
             until the CSS is rebuilt. Inline text-align is deterministic. --}}
        <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap text-sm">
                <thead>
                    {{-- AP-7: the two group headings, so a manager can see which half
                         of the table they are reading. --}}
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th rowspan="2" class="px-3 py-2 font-medium align-bottom" style="text-align:left">Agent</th>
                        <th colspan="6" class="px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400" style="text-align:center">Shift</th>
                        <th colspan="13" class="px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400" style="text-align:center">Calls</th>
                    </tr>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        {{-- Shift (6) --}}
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Logged-in</th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Ready</th>
                        {{-- AP-13: the On-call status starts when the phone rings and
                             covers the held minutes too. Talk is the conversation
                             alone. Two words, always visible, at the column where the
                             confusion happens. --}}
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">
                            On a call
                            <span class="block text-xs font-normal text-gray-400">includes ring and hold</span>
                        </th>
                        {{-- AP-5: wrap-up appears twice on purpose. This is the STATUS
                             sum, which belongs beside Ready and On break because the
                             four have to add up to Logged-in. --}}
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">
                            Wrapping up
                            <span class="block text-xs font-normal text-gray-400">status</span>
                        </th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">On break</th>
                        {{-- AP-13: the one (i) on the table. Occupancy needs a whole
                             sentence and is the number most likely to be reported as a
                             bug. The native title carries the same words, so the
                             explanation survives even if Alpine has not booted. --}}
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">
                            Occupancy
                            <span
                                class="ml-0.5 cursor-help text-gray-400"
                                x-tooltip.raw="Handling time ÷ logged-in time. Can read above 100% when a call began before the chosen dates."
                                title="Handling time ÷ logged-in time. Can read above 100% when a call began before the chosen dates."
                            >&#9432;</span>
                        </th>

                        {{-- Calls (13) --}}
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Total</th>
                        {{-- The divisor of the AHT column, on screen so the average can
                             be checked by hand: Talk + Hold + Wrap ÷ Answered. Total
                             counts the calls nobody picked up, and dividing by it would
                             not reproduce AHT (AP-6). --}}
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Answered</th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Inbound</th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Outbound</th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Contacts</th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Sales</th>
                        {{-- RP-5: No-answer is the one PROVISIONAL column (agent-reported
                             outcome, overridden at the trunk); Contact rate is reliable. --}}
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">
                            No-answer
                            <span class="block text-xs font-normal text-gray-400">provisional</span>
                        </th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">With recording</th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Contact rate</th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Talk</th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">Hold</th>
                        {{-- AP-5: the PER-CALL wrap, hang-up to the Done click. This is
                             the one inside AHT, because handle time is a per-call
                             figure. It can differ from the status column above. --}}
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">
                            Wrap
                            <span class="block text-xs font-normal text-gray-400">per call</span>
                        </th>
                        <th class="px-3 py-2 font-medium align-bottom" style="text-align:right">AHT</th>
                    </tr>
                </thead>
                <tbody>
                    @php($rows = $this->rows())
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2 font-medium" style="text-align:left">{{ $row['agent'] }}</td>

                            {{-- A dash, not a zero, wherever we hold no shift record:
                                 the Unassigned row is not a person, and a range that
                                 reaches back before the stint log started has no
                                 history at all (AP-2a, AP-7a, AP-13). --}}
                            <td class="px-3 py-2" style="text-align:right">{{ Call::asClock($row['active_seconds']) }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ Call::asClock($row['ready_seconds']) }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ Call::asClock($row['on_call_seconds']) }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ Call::asClock($row['wrapping_up_seconds']) }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ Call::asClock($row['on_break_seconds']) }}</td>
                            <td class="px-3 py-2" style="text-align:right">
                                {{ $row['occupancy'] === null ? '—' : number_format($row['occupancy'], 1).'%' }}
                            </td>

                            <td class="px-3 py-2" style="text-align:right">{{ $row['total'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['answered'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['inbound'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['outbound'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['contacts'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['sales'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['no_answer'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ $row['with_recording'] }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ number_format($row['contact_rate'], 1) }}%</td>
                            <td class="px-3 py-2" style="text-align:right">{{ Call::asClock($row['talk_seconds']) }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ Call::asClock($row['hold_seconds']) }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ Call::asClock($row['wrap_seconds']) }}</td>
                            <td class="px-3 py-2" style="text-align:right">{{ Call::asClock($row['aht_seconds']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="20" class="px-3 py-6 text-gray-400" style="text-align:center">
                                No calls and no shifts in the selected dates.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- AP-13: the three notes that belong to no single column. --}}
        <div class="mt-4 space-y-1 text-xs text-gray-500 dark:text-gray-400">
            {{-- AP-8. Accepted for v1 and said out loud: the held total lands on the
                 agent who pressed Hold, so the other agent's Talk still counts those
                 seconds as conversation. --}}
            <p>On a three-way call, time spent talking to a colleague while the caller is on hold counts as Talk for the agent who did not press Hold.</p>
            {{-- AP-11, rebuilt S118. The reports now cut their days on the client's own
                 clock, the same as the Call Export and the Calls list. The old warning
                 that the two disagreed is no longer true and has been removed. --}}
            <p>A day runs from midnight to midnight on the client's own clock ({{ \App\Tenancy\TenantContext::reportTimezoneLabel() }}), the same as the Call Export and the Calls list.</p>
            {{-- AP-13 point 3. Shown only when a row actually has a blank shift beside
                 real call numbers — the moment the reader would otherwise wonder
                 whether the report is broken. --}}
            @if (collect($rows)->contains(fn (array $row): bool => $row['agent_id'] !== null && $row['total'] > 0 && $row['active_seconds'] === null))
                <p>A dash in the Shift columns means we hold no shift record for those dates, not that the agent worked none. Shift history starts from the day status tracking was switched on.</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
