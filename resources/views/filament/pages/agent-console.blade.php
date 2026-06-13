<x-filament-panels::page>
    @vite('resources/js/agent-console.js')

    {{-- B4 CP1: the page registers as a browser phone; the status pill flips
         offline -> ready on its own off the phone's events (D3). The work
         screen (lead card, call controls) builds into this shell in CP2+. --}}
    <div x-data="agentConsole(@js($this->getPhoneConfig()))" class="mx-auto w-full max-w-2xl">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                        Browser phone
                    </h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Extension {{ $this->getPhoneConfig()['extension'] ?? '—' }}
                    </p>
                </div>

                <span
                    class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium"
                    :class="{
                        'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': state === 'offline' && ! error,
                        'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400': state === 'ready',
                        'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400': error,
                    }"
                >
                    <span
                        class="h-2 w-2 rounded-full"
                        :class="{
                            'bg-gray-400': state === 'offline' && ! error,
                            'bg-green-500': state === 'ready',
                            'bg-red-500': error,
                        }"
                    ></span>
                    <span x-text="error ? 'registration failed' : (state === 'ready' ? 'ready' : 'connecting…')"></span>
                </span>
            </div>

            <p x-show="error" x-cloak class="mt-3 text-sm text-red-600 dark:text-red-400">
                <span x-text="error"></span>
            </p>
        </div>
    </div>
</x-filament-panels::page>
