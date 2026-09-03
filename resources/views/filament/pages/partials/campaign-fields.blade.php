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

     Two across once the column has room (@lg reads the @container on the column, NOT on
     the card — a card-wide query would put two boxes in a half-width column). Fifteen
     short boxes become eight rows, which is what carries a client past the six-to-eight
     where one-per-row starts costing the agent a scroll mid-call. Same move the Leads
     screen already makes on the same definitions.

     🔴 Alpine state, not Blade, like every panel on this screen: a renderless method
     sharing the Livewire batch would throw server-rendered markup away (S120b).

     Native inputs on purpose (CF-8). The Leads screen uses Filament's JS date picker;
     matching it here costs the console a dependency it does not have, for a difference
     no agent will notice. `type` is bound straight from the definition — text, number
     and date are all valid input types, so only the dropdown needs its own branch.

     🔴 DF-6 (disposition-driven-fields.md): $outcomeAware. This partial is included TWICE
     from the same `history.fields`, so the difference between the two copies has to be
     per-include, not per-state. The wrap-up copy passes the flag and its star follows the
     outcome the agent just picked; the live-call copy does not and keeps a static star,
     because no outcome has been picked there yet. --}}
@php($requiredExpr = ($outcomeAware ?? false) ? 'requiredNow(field)' : 'field.required')
<div x-show="history.fields.length" x-cloak class="mt-4 border-t border-gray-100 pt-4 dark:border-white/10">
    <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $heading ?? 'For this campaign' }}</p>

    {{-- pr-1 keeps the scrollbar off the input borders when it appears. --}}
    <div class="mt-3 grid max-h-64 gap-3 overflow-y-auto pr-1 @lg:grid-cols-2">
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
                    <span x-show="{{ $requiredExpr }}" class="text-red-500 dark:text-red-400" aria-hidden="true">*</span>
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
                        :required="{{ $requiredExpr }}"
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
                        :required="{{ $requiredExpr }}"
                        placeholder="Not captured yet"
                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
                    />
                </template>
            </div>
        </template>
    </div>
</div>
