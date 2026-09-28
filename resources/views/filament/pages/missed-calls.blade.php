<x-filament-panels::page>
    {{-- QD-9: the callers nobody reached, newest first — the freshest one is the most
         worth ringing back, so it sits at the top. Number shown, not dialled: a
         call-back button reaches into the console's dial path (parked, QD-9). --}}
    <x-filament::section>
        <x-slot name="heading">Callers who never got through</x-slot>

        {{-- Slice 8 (AU-32): the voicemails are a filter of this list, not a screen of
             their own (Aircall's shape). --}}
        <label class="inline-flex items-center gap-2 text-sm" style="margin-bottom:0.75rem">
            <x-filament::input.checkbox wire:model.live="onlyWithVoicemail" />
            <span>Only callers who left a message</span>
        </label>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="px-3 py-2 font-medium" style="text-align:left">When</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Caller</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Rang</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Waited</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">What happened</th>
                        <th class="px-3 py-2 font-medium" style="text-align:left">Message</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->missedCalls() as $call)
                        {{-- Keyed so a playing message stays with its own caller when the
                             filter re-renders the list (review S175 F8). --}}
                        <tr wire:key="missed-{{ $call->id }}" class="border-b border-gray-100 dark:border-white/5">
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
                            <td class="px-3 py-2" style="text-align:left">
                                {{ $this->reasonFor($call) }}
                                {{-- AIV-1 AB-4: the AI's message. Escaped — it is the caller's own words. --}}
                                @if (filled($call->notes))
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $call->notes }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2" style="text-align:left">
                                {{-- Play only for everyone here, auditors included: a download
                                     belongs to Call Review, which already offers it to them. --}}
                                @if (filled($call->recording_path))
                                    @include('filament.pages.partials.play-only-recording', ['call' => $call])
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-gray-400" style="text-align:center">
                                {{ $onlyWithVoicemail ? 'No caller has left a message.' : 'Nobody has been missed. Every caller got through.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
