<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Campaign;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

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
 *    'options' => ['Gold', 'Silver']]   // options only for the select type
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
                Toggle::make('required')
                    ->default(false)
                    ->helperText('Must be filled on the lead form.'),
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
