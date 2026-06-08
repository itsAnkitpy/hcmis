<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Campaign;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads a lead file (CSV/XLSX) for ONE campaign and inserts the valid rows as
 * Leads (FR-LC01). Designed to run INSIDE a TenantContext::run() block (the job
 * owns that — see ImportLeadsJob): every Lead::create here is auto-stamped with
 * the active tenant by BelongsToTenant, and RLS is the backstop. This class
 * never sets a tenant itself — if it is ever run with no context, the default-
 * deny wall throws and nothing is written (D-M5-3, the catastrophic seam).
 *
 * Chunked reading keeps memory flat for large files; it is deliberately NOT
 * queued at the library level (no ShouldQueue) so the whole read stays in the
 * single tenant-bound job and can never split into per-chunk jobs that would
 * lose the binding.
 *
 * Results accumulate on public properties for the job to read once the import
 * returns (same process), then turn into the completion notification.
 */
class LeadsImport implements SkipsEmptyRows, ToCollection, WithChunkReading, WithHeadingRow
{
    /** Fixed lead columns accepted from the file, besides per-campaign custom fields. */
    private const FIXED_COLUMNS = ['phone', 'name', 'email', 'region'];

    /** Rows successfully inserted. */
    public int $imported = 0;

    /** Rows skipped because the phone already exists in this client (D-M5-7). */
    public int $duplicates = 0;

    /**
     * Rows skipped for a validation reason, with the spreadsheet row number.
     *
     * @var array<int, array{row: int, reason: string}>
     */
    public array $skipped = [];

    /**
     * The target campaign's custom-field definitions, keyed by the slug of each
     * field key so they line up with the slugged heading-row columns.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $customFields;

    /**
     * Phones already taken in this client — seeded per chunk from existing rows
     * and grown as we insert, so duplicates (existing or within-file) are skipped.
     *
     * @var array<string, true>
     */
    private array $seenPhones = [];

    /** Spreadsheet row pointer; row 1 is the heading row. */
    private int $currentRow = 1;

    public function __construct(private readonly Campaign $campaign)
    {
        $this->customFields = collect($campaign->custom_fields ?? [])
            ->filter(fn (array $definition): bool => filled($definition['key'] ?? null))
            ->keyBy(fn (array $definition): string => Str::slug((string) $definition['key'], '_'))
            ->all();
    }

    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        $this->markExistingPhones($rows);

        foreach ($rows as $row) {
            $this->currentRow++;
            $this->handleRow($row->all());
        }
    }

    /**
     * One query per chunk: mark every phone in this chunk that already exists in
     * the client so per-row dedupe is a cheap in-memory lookup, not N queries.
     *
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    private function markExistingPhones(Collection $rows): void
    {
        $phones = $rows
            ->map(fn (Collection $row): ?string => $this->normalizePhone($row->get('phone')))
            ->filter()
            ->unique()
            ->values();

        if ($phones->isEmpty()) {
            return;
        }

        Lead::query()
            ->whereIn('phone', $phones->all())
            ->pluck('phone')
            ->each(function (string $phone): void {
                $this->seenPhones[$phone] = true;
            });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function handleRow(array $row): void
    {
        $phone = $this->normalizePhone($row['phone'] ?? null);

        if ($phone === null) {
            $this->skip('phone is required');

            return;
        }

        if (! $this->isValidPhone($phone)) {
            $this->skip('phone is not a valid number');

            return;
        }

        $email = $this->cleanString($row['email'] ?? null);

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->skip('email is not a valid address');

            return;
        }

        if (isset($this->seenPhones[$phone])) {
            $this->duplicates++;

            return;
        }

        $customFields = $this->mapCustomFields($row);

        if ($customFields === false) {
            return; // a required custom field was missing — already recorded
        }

        Lead::create([
            'campaign_id' => $this->campaign->id,
            'name' => $this->cleanString($row['name'] ?? null),
            'phone' => $phone,
            'email' => $email,
            'region' => $this->cleanString($row['region'] ?? null),
            'custom_fields' => $customFields,
            // status falls back to the DB default (LeadStatus::New) — D-M5 open-Q2.
        ]);

        $this->seenPhones[$phone] = true;
        $this->imported++;
    }

    /**
     * Pull the campaign's custom-field values out of the row by matching slugged
     * keys. Returns the value map, or false when a required field is missing (and
     * records the skip).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|false
     */
    private function mapCustomFields(array $row): array|false
    {
        $values = [];

        foreach ($this->customFields as $slug => $definition) {
            $value = $row[$slug] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            if (blank($value)) {
                if ($definition['required'] ?? false) {
                    $label = $definition['label'] ?? $definition['key'];
                    $this->skip("required field '{$label}' is missing");

                    return false;
                }

                continue;
            }

            $values[$definition['key']] = $value;
        }

        return $values;
    }

    private function skip(string $reason): void
    {
        $this->skipped[] = ['row' => $this->currentRow, 'reason' => $reason];
    }

    /**
     * Light normalization: trim and strip spaces, dashes and brackets so the same
     * number written two ways dedupes as one. No country-code logic — voice is not
     * live in Phase 1, phone is a data field only (guardrail #1).
     */
    private function normalizePhone(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $normalized = preg_replace('/[\s\-()]/', '', trim((string) $raw));

        return $normalized === '' ? null : $normalized;
    }

    private function isValidPhone(string $phone): bool
    {
        return preg_match('/^\+?\d{7,15}$/', $phone) === 1;
    }

    private function cleanString(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }
}
