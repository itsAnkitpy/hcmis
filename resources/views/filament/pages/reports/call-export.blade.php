<x-filament-panels::page>
    {{-- CE-8: the filters decide WHICH calls come out. The columns are fixed and
         chosen by us (CE-1), so there is nothing to tick and nothing to preview. --}}
    {{ $this->filtersForm }}

    <x-filament::section>
        <x-slot name="heading">Download</x-slot>
        <x-slot name="description">
            One row per call, for the calls matching the filters above.
        </x-slot>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            The file starts arriving as it is written, so a large range takes a while to
            finish downloading. Only one export runs at a time per person — if nothing
            seems to happen, check your browser's downloads before pressing again.
        </p>
    </x-filament::section>
</x-filament-panels::page>
