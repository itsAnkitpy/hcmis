<x-filament-panels::page>
    @php
        $tiles = $this->tiles();
        $callbacksDue = $this->callbacksDue();
    @endphp

    {{-- MD-3: the five honest tiles — one agentProductivity() row for me + today.
         No-answer carries the same "provisional" tag the manager reports use. --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
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
                        <th class="px-3 py-2 font-medium" style="text-align:left">Play</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->myCalls() as $call)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="px-3 py-2" style="text-align:left">{{ $call->created_at->format('H:i') }}</td>
                            <td class="px-3 py-2" style="text-align:left">{{ $call->direction->label() }}</td>
                            <td class="px-3 py-2" style="text-align:left">
                                <span class="font-medium">{{ $call->lead?->name ?? '—' }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ ($call->direction === \App\Enums\CallDirection::Outbound ? $call->to_number : $call->from_number) ?? '—' }}
                                </span>
                            </td>
                            <td class="px-3 py-2" style="text-align:left">{{ $call->campaign?->name ?? '—' }}</td>
                            <td class="px-3 py-2" style="text-align:left">{{ $call->outcome?->label() ?? '—' }}</td>
                            <td class="px-3 py-2" style="text-align:left">
                                @if (filled($call->recording_path))
                                    {{-- MD-4 (amended 2026-07-06): a compact custom control instead of the
                                         native browser bar — Chrome's built-in player menu carries a Download
                                         item, which contradicts play-only for agents. No native controls =
                                         no menu; controlsList is belt-and-braces should they ever return.
                                         The audit behavior is unchanged (HD-3): preload="none" fetches nothing
                                         until play; the first play hits the gated route from byte 0 (one
                                         listen entry); a scrub sends a mid-file range (not re-audited). --}}
                                    <div
                                        x-data="{
                                            playing: false,
                                            dragging: false,
                                            progress: 0,
                                            current: 0,
                                            duration: 0,
                                            toggle() { this.$refs.audio.paused ? this.$refs.audio.play() : this.$refs.audio.pause() },
                                            seek() { if (this.duration) { this.$refs.audio.currentTime = (this.progress / 100) * this.duration } this.dragging = false },
                                            clock(s) { return isFinite(s) && s > 0 ? Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0') : '0:00' },
                                        }"
                                        class="flex items-center gap-2"
                                    >
                                        <audio
                                            x-ref="audio"
                                            preload="none"
                                            src="{{ route('calls.recording', $call) }}"
                                            controlsList="nodownload"
                                            @play="playing = true"
                                            @pause="playing = false"
                                            @ended="playing = false; progress = 0; current = 0"
                                            @loadedmetadata="duration = $refs.audio.duration"
                                            @timeupdate="current = $refs.audio.currentTime; if (! dragging) { progress = duration ? (current / duration) * 100 : 0 }"
                                        ></audio>
                                        <button
                                            type="button"
                                            @click="toggle"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-white transition hover:bg-primary-500"
                                            x-bind:aria-label="playing ? 'Pause' : 'Play'"
                                        >
                                            <svg x-show="! playing" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                            </svg>
                                            <svg x-show="playing" x-cloak class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M6.75 5.25a.75.75 0 0 1 .75-.75H9a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75H7.5a.75.75 0 0 1-.75-.75V5.25Zm7.5 0a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75H15a.75.75 0 0 1-.75-.75V5.25Z" />
                                            </svg>
                                        </button>
                                        <input
                                            type="range"
                                            min="0"
                                            max="100"
                                            step="0.1"
                                            x-model.number="progress"
                                            @pointerdown="dragging = true"
                                            @change="seek"
                                            class="h-1.5 w-28 cursor-pointer"
                                            aria-label="Seek"
                                        />
                                        <span
                                            class="text-xs tabular-nums text-gray-500 dark:text-gray-400"
                                            x-text="clock(current) + ' / ' + clock(duration)"
                                        ></span>
                                    </div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-gray-400" style="text-align:center">
                                No calls yet today.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
