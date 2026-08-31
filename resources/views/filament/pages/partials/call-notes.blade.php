{{-- CP-5 (N1b): the agent's note about THIS call, in their own words.

     Included TWICE — beside the live call (A2: agents write at their own pace,
     while the customer is still talking) and again on wrap-up (the last chance,
     once the line is down). One `callNotes` variable backs both, so whatever was
     typed mid-call is still in the box at wrap-up and simply carries on.

     It is sent at wrap-up rather than as-you-type because the calls row does not
     exist until then — recordCall() writes it on Save / Done, so before that there
     is nothing to attach a note to. The server caps the length again at 2000.

     🔴 Alpine state, not Blade, like every other panel on this screen: a renderless
     method sharing the Livewire batch would throw server-rendered markup away. --}}
<div>
    <label
        for="{{ $id }}"
        class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500"
    >Call notes</label>

    <textarea
        id="{{ $id }}"
        x-model="callNotes"
        rows="3"
        maxlength="2000"
        placeholder="What was this call about? Saved when you wrap up."
        class="mt-2 block w-full rounded-lg border-gray-200 text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-gray-100"
    ></textarea>
</div>
