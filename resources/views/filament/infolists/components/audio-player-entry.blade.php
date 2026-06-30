<x-dynamic-component
    :component="$getEntryWrapperView()"
    :entry="$entry"
>
    {{--
        HD-1: the in-page play bar on the call's detail view, pointed at the gated
        stream route (CR-2). HD-3: preload="none" means opening the page neither
        fetches the audio nor counts as a listen — the route is hit (and audited)
        only when the reviewer presses play. The route's range support (HD-2) is what
        lets the progress line seek, and what Safari needs before it will play at all.
    --}}
    <audio
        controls
        preload="none"
        src="{{ route('calls.recording', $record) }}"
        class="w-full max-w-xl"
    ></audio>
</x-dynamic-component>
