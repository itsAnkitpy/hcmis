<x-filament-panels::page>
    {{-- QD-9: the callers nobody reached, newest first — the freshest one is the most
         worth ringing back, so it sits at the top. Number shown, not dialled: a
         call-back button reaches into the console's dial path (parked, QD-9). --}}
    <x-filament::section>
        <x-slot name="heading">Callers who never got through</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="px-3 py-2 font-medium" style="text-align:left">When</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Caller</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Rang</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Waited</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">What happened</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->missedCalls() as $call)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2" style="text-align:left">
                                {{ $this->whenFor($call) }}
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ $call->created_at->diffForHumans() }}
                                </span>
                            </td>
                            <td class="px-3 py-2 font-medium" style="text-align:left">
                                {{ $call->from_number ?? 'Number withheld' }}
                            </td>
                            <td class="px-3 py-2" style="text-align:left">{{ $call->to_number ?? '—' }}</td>
                            <td class="px-3 py-2" style="text-align:left">
                                {{ $this->waitedFor($call) }}
                            </td>
                            <td class="px-3 py-2" style="text-align:left">{{ $this->reasonFor($call) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-gray-400" style="text-align:center">
                                Nobody has been missed. Every caller got through.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
