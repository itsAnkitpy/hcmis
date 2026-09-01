{{-- CF-5 (campaign-fields-on-console.md): the CLIENT's own boxes — the ones their team
     leader defined on the campaign — on the agent's live-call screen.

     Two halves, deliberately named apart so nobody confuses them at 3am:
       `history.fields`   — the DEFINITIONS. What boxes exist, from the CAMPAIGN this
                            call belongs to (CF-1's chain, sent by CF-2 on callHistory).
       `customer.fields`  — the VALUES. What this PERSON has in them. A map keyed by box
                            name, kept nested rather than spread beside name/email/city
                            because a client is free to define a box called `name`.

     🔴 ITS OWN SCROLL AREA, and that is the whole point of CF-5. The right column runs
     customer details -> Save -> the call note, and CP-5 put the note there so the agent
     can type it WHILE STILL ON THE CALL. Six client boxes stacked inline would push it
     below the fold and quietly break a shipped feature. Capped here, the button and the
     note keep the position they have always had, however many boxes a client defines.

     `max-h` rather than a fixed height: two boxes then leave no dead space, and eight
     still scroll. Nothing is capped or hidden — a client who wants twelve gets twelve.
     Silently dropping the twelfth would collect no data and break nothing visible, which
     is the worst failure shape available.

     🔴 Alpine state, not Blade, like every panel on this screen: a renderless method
     sharing the Livewire batch would throw server-rendered markup away (S120b).

     Native inputs on purpose (CF-8). The Leads screen uses Filament's JS date picker;
     matching it here costs the console a dependency it does not have, for a difference
     no agent will notice. `type` is bound straight from the definition — text, number
     and date are all valid input types, so only the dropdown needs its own branch. --}}
<div x-show="history.fields.length" x-cloak class="mt-4 border-t border-gray-100 pt-4 dark:border-white/10">
    <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $heading ?? 'For this campaign' }}</p>

    {{-- pr-1 keeps the scrollbar off the input borders when it appears. --}}
    <div class="mt-3 max-h-64 space-y-3 overflow-y-auto pr-1">
        <template x-for="field in history.fields" :key="field.key">
            <div>
                <label
                    :for="'{{ $id }}-' + field.key"
                    class="block text-xs font-medium text-gray-600 dark:text-gray-400"
                >
                    <span x-text="field.label"></span>
                    {{-- CF-8: the agent sees which boxes are must-fill DURING the call,
                         so they can ask, rather than discovering it at wrap-up when the
                         caller has already gone. --}}
                    <span x-show="field.required" class="text-red-500 dark:text-red-400" aria-hidden="true">*</span>
                </label>

                <template x-if="field.type === 'select'">
                    {{-- 🔴 The x-init is not optional, and text/date do not need it. Alpine
                         initialises this element's own directives BEFORE it processes the
                         children, so x-model sets the select's value while it still has no
                         options — and a select handed a value matching no option silently
                         falls back to the first one. The stored Plan was there and the box
                         still read "Not captured yet". $nextTick re-applies it once the
                         options exist. Later changes are fine on x-model alone, because by
                         then the options are in the DOM. --}}
                    <select
                        :id="'{{ $id }}-' + field.key"
                        x-model="customer.fields[field.key]"
                        x-init="$nextTick(() => { $el.value = customer.fields[field.key] ?? '' })"
                        :required="field.required"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                    >
                        <option value="">Not captured yet</option>
                        <template x-for="option in field.options" :key="option">
                            <option :value="option" x-text="option"></option>
                        </template>
                    </select>
                </template>

                <template x-if="field.type !== 'select'">
                    <input
                        :id="'{{ $id }}-' + field.key"
                        :type="field.type"
                        x-model="customer.fields[field.key]"
                        :required="field.required"
                        placeholder="Not captured yet"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                    />
                </template>
            </div>
        </template>
    </div>
</div>
