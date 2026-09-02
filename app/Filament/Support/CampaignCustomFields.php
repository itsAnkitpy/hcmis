<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Models\Campaign;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\Rule;

/**
 * Per-campaign custom fields (FR-LC02), shared by both halves of the feature:
 *  - definitionRepeater() builds the editor a Campaign uses to DEFINE its
 *    fields (stored as a JSON array on campaigns.custom_fields);
 *  - valueFields() turns one campaign's definitions into the input components
 *    a Lead form renders to capture VALUES (stored on leads.custom_fields).
 *
 * Kept in one place so the definition shape and the value rendering can never
 * drift apart. Validation lives here in the app layer (the JSONB columns are
 * deliberately unvalidated at the DB — KISS, no EAV; see m4 plan §9).
 *
 * Definition shape (one array entry per field):
 *   ['key' => 'policy_number', 'label' => 'Policy Number',
 *    'type' => 'text|number|date|select', 'required' => bool,
 *    'options' => ['Gold', 'Silver'],   // options only for the select type
 *    'required_on' => [14, 15]]         // disposition ids; narrows `required` at wrap-up
 *
 * `required_on` is CP-10 (DF-2). It only ever makes the `required` rule SMALLER, and only
 * at wrap-up: an empty or absent list means the box blocks on every contact disposition,
 * which is what `required` alone has always meant (DF-1). Ids, not codes —
 * `dispositions.code` carries no unique index, so one code can name two rows.
 */
class CampaignCustomFields
{
    /** The Phase-1 field types (D — text/number/date/select, no rich types yet). */
    public const array TYPES = [
        'text' => 'Text',
        'number' => 'Number',
        'date' => 'Date',
        'select' => 'Dropdown',
    ];

    /**
     * The repeater a Campaign uses to define its custom-field list.
     */
    public static function definitionRepeater(): Repeater
    {
        return Repeater::make('custom_fields')
            ->label('Custom fields')
            ->helperText('Extra fields the lead form will collect for this campaign.')
            ->schema([
                TextInput::make('key')
                    ->label('Field key')
                    ->required()
                    ->alphaDash()
                    ->maxLength(50)
                    ->distinct()
                    ->helperText('Stored name, e.g. policy_number'),
                TextInput::make('label')
                    ->required()
                    ->maxLength(80),
                Select::make('type')
                    ->options(self::TYPES)
                    ->default('text')
                    ->required()
                    ->live()
                    ->native(false),
                // CF-7 (campaign-fields-on-console.md): this text used to say only "Must be
                // filled on the lead form", which was true while the tick affected office
                // staff typing up leads and nothing else. CF-4 makes it stop agents
                // finishing live calls, so one team leader ticking it on a new box halts
                // every agent on the floor on their next answered call — every existing
                // customer has that box blank. Warn the admin rather than silently enforce;
                // that is the standard move, and it is the same division of labour
                // Salesforce uses (the admin is never blocked, the rule's SCOPE is what
                // gets narrowed). Narrowing the scope per disposition is CP-10.
                // ->live() so CP-10's outcome checklist appears the moment Must fill is
                // ticked (DF-3). The `type` select above already does the same for the
                // dropdown-choices control, so the pattern is this file's own.
                Toggle::make('required')
                    ->default(false)
                    ->live()
                    ->helperText('Must be filled on the lead form — and agents cannot finish an answered call until it is filled. Existing customers with this box blank will block on their next answered call.'),
                // CP-10 (disposition-driven-fields.md) — the box names the outcomes it is
                // actually required for, so "Customer informed" can stop asking for a
                // policy number nobody needed. One checklist, one helper block (DF-9.8).
                //
                // Only shown while Must fill is on (DF-3): the list has no meaning on a
                // box that blocks nothing, and most boxes are not must-fill. Unticking
                // Must fill hides it but LEAVES the stored ids, so a mis-click costs
                // nothing and re-ticking brings them back.
                //
                // 🔴 dehydratedWhenHidden() is what makes that sentence true. Filament
                // drops a hidden field from the saved state, so without it a leader who
                // unticks Must fill and saves loses the ids — and re-ticking would hand
                // them an empty list, which DF-1 reads as "block on every contact
                // outcome". Silent, and in the opposite direction from what they picked.
                // On the create screen it dehydrates an empty list, which DF-9.3 already
                // treats identically to an absent key.
                CheckboxList::make('required_on')
                    ->label('Required only for these outcomes')
                    ->options(fn (?Campaign $record): array => LeadForm::dispositionOptions($record?->id, contactOnly: true))
                    ->columns(2)
                    ->visible(fn (string $operation, Get $get): bool => $operation !== 'create' && (bool) $get('required'))
                    ->dehydratedWhenHidden()
                    ->helperText('Only outcomes where the agent reached a person can require a box. Leave every outcome unticked and the box stays required on all of them — to stop requiring it, turn off Must fill above. Office staff filling in the lead form are always asked for it, whatever is ticked here.'),
                // DF-5 — CreateCampaign seeds this campaign's outcomes in afterCreate(),
                // so while the create form is on screen there are none of its own to show.
                // Rendering the checklist here would come back empty and read as a bug, or
                // worse, list only the tenant-wide outcomes and let a leader tick something
                // incomplete. DF-1 makes the wait safe: a box saved with nothing ticked
                // blocks on every contact outcome, which is exactly today's behaviour.
                Placeholder::make('required_on_hint')
                    ->label('Required only for these outcomes')
                    ->content('Outcomes are created with the campaign. Save, then reopen this box to choose them.')
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && (bool) $get('required')),
                TagsInput::make('options')
                    ->label('Dropdown choices')
                    ->visible(fn (Get $get): bool => $get('type') === 'select')
                    ->requiredIf('type', 'select')
                    ->helperText('Press enter after each choice.'),
            ])
            ->columns(2)
            ->addActionLabel('Add field')
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
            ->default([]);
    }

    /**
     * The same definitions as PLAIN DATA, for a screen that draws its own inputs (CF-2).
     *
     * The agent console cannot use valueFields() — that returns Filament components, and
     * the console's live-call panel is Alpine markup fed by a return value, never a
     * server render (the S120b redraw bug). So it needs the list itself: key, label,
     * type, options, required and requiredOn, and it draws native browser inputs from
     * them (CF-8).
     *
     * Three guards worth their line. An unknown `type` falls back to text, matching
     * valueField()'s own match default, so a definition written by an older or newer
     * shape still draws a usable box instead of nothing. `options` always rides out as a
     * list — empty for the three non-select types — so the browser can loop it without
     * asking what type it is first. `requiredOn` rides out the same way: always a list,
     * always ints, empty when the key is absent, and anything non-numeric dropped rather
     * than coerced — so the browser can compare it against a disposition id without
     * checking types first (DF-9.3).
     *
     * 🔴 A `requiredOn` id is a raw number inside JSON and is walled by nothing on its
     * own. It is safe to send because the browser only uses it to paint the star. The
     * refusal compares it against the disposition the SERVER loaded, never against a
     * value the browser sent back.
     *
     * Tenant-scoped: a cross-tenant id resolves to null and yields no fields, exactly as
     * valueFields() does.
     *
     * @return array<int, array{key: string, label: string, type: string, required: bool, options: array<int, string>, requiredOn: array<int, int>}>
     */
    public static function definitionsForBrowser(int|string|null $campaignId): array
    {
        if (blank($campaignId)) {
            return [];
        }

        $campaign = Campaign::find($campaignId);

        if (! $campaign) {
            return [];
        }

        return collect($campaign->custom_fields ?? [])
            ->filter(fn (array $definition): bool => filled($definition['key'] ?? null))
            ->map(fn (array $definition): array => [
                'key' => (string) $definition['key'],
                'label' => (string) ($definition['label'] ?? $definition['key']),
                'type' => isset(self::TYPES[$definition['type'] ?? '']) ? (string) $definition['type'] : 'text',
                'required' => (bool) ($definition['required'] ?? false),
                'options' => array_values(array_map(strval(...), $definition['options'] ?? [])),
                'requiredOn' => array_values(array_map(intval(...), array_filter((array) ($definition['required_on'] ?? []), is_numeric(...)))),
            ])
            ->values()
            ->all();
    }

    /**
     * Validation rules for VALUES a screen sends back, keyed by box name (CF-3).
     *
     * Takes the definitions rather than a campaign id so the caller reads them once and
     * uses the same list for the allow-list and the rules — one query, and no chance of
     * validating against a different set than the one that was drawn.
     *
     * 🔴 NOT `required`, ever, whatever the definition says. A3 settled that the live-call
     * form never blocks: it is the optional mid-call save, and an agent who has learned
     * one detail must be able to file it. Enforcing must-fill is CF-4's job and it happens
     * at wrap-up, on a contact disposition only. Adding `required` here would block exactly
     * what A3 protects.
     *
     * A dropdown is checked against its own choices, so the stored value can only be one
     * the client actually defined — otherwise the option list is decoration.
     *
     * @param  array<int, array{key: string, label: string, type: string, required: bool, options: array<int, string>}>  $definitions
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $definitions): array
    {
        return collect($definitions)
            ->mapWithKeys(fn (array $definition): array => [
                $definition['key'] => match ($definition['type']) {
                    'number' => ['nullable', 'numeric'],
                    'date' => ['nullable', 'date'],
                    'select' => ['nullable', Rule::in($definition['options'])],
                    default => ['nullable', 'string', 'max:255'],
                },
            ])
            ->all();
    }

    /**
     * The input components a Lead form renders for the given campaign's custom
     * fields. Returns an empty array when there is no campaign or it defines no
     * fields, so the caller can drop it into a layout component's schema().
     *
     * @return array<int, Field>
     */
    public static function valueFields(int|string|null $campaignId): array
    {
        if (blank($campaignId)) {
            return [];
        }

        // Tenant-scoped: a cross-tenant id resolves to null and yields no fields.
        $campaign = Campaign::find($campaignId);

        if (! $campaign) {
            return [];
        }

        return collect($campaign->custom_fields ?? [])
            ->map(fn (array $definition) => self::valueField($definition))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Map one field definition to its Lead-form input, bound to the
     * custom_fields JSON under the field's key.
     *
     * @param  array<string, mixed>  $definition
     */
    private static function valueField(array $definition): ?Field
    {
        $key = $definition['key'] ?? null;

        if (blank($key)) {
            return null;
        }

        $name = "custom_fields.{$key}";
        $label = $definition['label'] ?? $key;
        $required = (bool) ($definition['required'] ?? false);

        return match ($definition['type'] ?? 'text') {
            'number' => TextInput::make($name)->label($label)->numeric()->required($required),
            'date' => DatePicker::make($name)->label($label)->required($required)->native(false),
            'select' => Select::make($name)->label($label)
                ->options(self::optionMap($definition))
                ->required($required)
                ->native(false),
            default => TextInput::make($name)->label($label)->maxLength(255)->required($required),
        };
    }

    /**
     * A select field's choices as a value=>label map (we store and show the
     * same string).
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private static function optionMap(array $definition): array
    {
        return collect($definition['options'] ?? [])
            ->mapWithKeys(fn (string $option): array => [$option => $option])
            ->all();
    }
}
