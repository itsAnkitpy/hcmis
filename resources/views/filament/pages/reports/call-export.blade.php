<x-filament-panels::page>
    {{-- CE-8: the filters decide WHICH calls come out. The columns are fixed and
         chosen by us (CE-1), so there is nothing to tick and nothing to preview. --}}
    {{ $this->filtersForm }}

    <x-filament::section>
        <x-slot name="heading">Download</x-slot>
        <x-slot name="description">
            One row per call, for the calls matching the filters above.
        </x-slot>

        {{-- The filters read back in plain words, with the count from the very query
             the download walks. This page has no table, so without this line a filter
             left over from earlier is invisible until the spreadsheet is open. --}}
        <p class="text-base font-medium text-gray-950 dark:text-white">
            {{ $this->exportSummary() }}
        </p>

        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            The file starts arriving as it is written, so a large range takes a while to
            finish downloading. Only one export runs at a time per person — if nothing
            seems to happen, check your browser's downloads before pressing again.
        </p>
    </x-filament::section>
</x-filament-panels::page>
