{{-- A play-only recording player, shared by My Day (MD-4) and Missed Calls (slice 8, AU-33).
     Expects $call with a recording. --}}
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
