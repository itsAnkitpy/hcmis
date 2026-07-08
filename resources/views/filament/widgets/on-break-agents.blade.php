{{--
    BK-5: the team leader's per-agent break detail. One line per agent on break —
    name, break type, minutes elapsed — turning red once past the limit (the same
    red the agent's own console flips to, BK-4: flag loudly, never force).
    Rows come pre-shaped from OnBreakAgents::getViewData().
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">On break now</x-slot>
        <x-slot name="description">Longest away first — red means the break has run past its limit · refresh to update</x-slot>

        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No one is on break right now.</p>
        @else
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($rows as $row)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <span @class([
                            'text-sm font-medium',
                            'text-red-600 dark:text-red-400' => $row['overstayed'],
                            'text-gray-950 dark:text-white' => ! $row['overstayed'],
                        ])>
                            {{ $row['name'] }}
                        </span>
                        <span @class([
                            'text-sm tabular-nums',
                            'font-semibold text-red-600 dark:text-red-400' => $row['overstayed'],
                            'text-gray-500 dark:text-gray-400' => ! $row['overstayed'],
                        ])>
                            {{ $row['category'] }}
                            &middot;
                            @if ($row['limitMinutes'] !== null)
                                {{ $row['elapsedMinutes'] }} of {{ $row['limitMinutes'] }} min
                            @else
                                {{ $row['elapsedMinutes'] }} min
                            @endif
                            @if ($row['overstayed'])
                                &mdash; over the limit
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
