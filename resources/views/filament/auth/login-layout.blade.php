{{--
    Full-bleed shell for the login page: keeps Filament's base document
    (assets, theme, Livewire) but skips the simple-layout's centered card
    so the page view controls the whole viewport.
--}}

@php
    $livewire ??= null;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    {{ $slot }}
</x-filament-panels::layout.base>
