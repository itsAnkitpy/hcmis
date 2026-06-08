<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Imports\LeadsImport;
use App\Models\Campaign;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * The queued half of M5 (D-M5-1 database driver). This is the ONLY place the
 * import touches the tenant wall: it binds the uploader's client with
 * TenantContext::run() (the job-path SET LOCAL seam) and does ALL its work
 * inside that block, so every inserted Lead is stamped to the right client and
 * RLS is the backstop (D-M5-3).
 *
 * The target campaign is re-resolved INSIDE the bound context, so a tampered or
 * stale cross-client campaign id resolves to null and the import refuses without
 * writing a single row — fail closed.
 *
 * Scalars only in the constructor (ids + a path), never Eloquent models, so the
 * serialized payload carries no cross-request tenant assumptions.
 */
class ImportLeadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt only: a retry would re-read a file we delete on completion and
     * risk double-inserting. A failed import is re-uploaded, not auto-retried
     * (D-M5-9 — cleanup must not race retries).
     */
    public int $tries = 1;

    public function __construct(
        public int $tenantId,
        public int $campaignId,
        public int $uploaderId,
        public string $storedPath,
        public string $originalName,
        public string $disk = 'local',
    ) {}

    public function handle(): void
    {
        $result = TenantContext::run($this->tenantId, function (): array {
            $campaign = Campaign::find($this->campaignId);

            // Fail closed: a campaign that does not resolve inside this client's
            // wall (wrong/stale/tampered id) means we refuse the whole import.
            if (! $campaign) {
                return ['status' => 'rejected'];
            }

            $import = new LeadsImport($campaign);
            Excel::import($import, $this->storedPath, $this->disk);

            return [
                'status' => 'done',
                'campaign' => $campaign->name,
                'imported' => $import->imported,
                'duplicates' => $import->duplicates,
                'skipped' => $import->skipped,
            ];
        });

        $this->cleanup();
        $this->notifyUploader($result);
    }

    /**
     * Permanent failure (tries=1, so the first throw is final): drop the file and
     * tell the uploader. A throw inside run() already rolled back the transaction,
     * so no partial leads remain.
     */
    public function failed(?Throwable $exception): void
    {
        $this->cleanup();

        $this->sendNotification(
            fn (Notification $n): Notification => $n
                ->title('Lead import failed')
                ->body("We couldn't read \"{$this->originalName}\". Check the file and try again.")
                ->danger(),
        );
    }

    private function cleanup(): void
    {
        try {
            Storage::disk($this->disk)->delete($this->storedPath);
        } catch (Throwable) {
            // A missing temp file at cleanup is not worth failing the job over.
        }
    }

    /**
     * @param  array{status: string, campaign?: string, imported?: int, duplicates?: int, skipped?: array<int, array{row: int, reason: string}>}  $result
     */
    private function notifyUploader(array $result): void
    {
        if (($result['status'] ?? null) === 'rejected') {
            $this->sendNotification(
                fn (Notification $n): Notification => $n
                    ->title('Lead import was not processed')
                    ->body('The selected campaign is no longer available. Nothing was imported.')
                    ->warning(),
            );

            return;
        }

        $imported = $result['imported'] ?? 0;
        $duplicates = $result['duplicates'] ?? 0;
        $skipped = $result['skipped'] ?? [];
        $skippedCount = count($skipped);

        $this->sendNotification(
            fn (Notification $n): Notification => $n
                ->title("Lead import finished — {$imported} added")
                ->body($this->summaryBody($result['campaign'] ?? '', $imported, $duplicates, $skippedCount, $skipped))
                ->success(),
        );
    }

    /**
     * @param  array<int, array{row: int, reason: string}>  $skipped
     */
    private function summaryBody(string $campaign, int $imported, int $duplicates, int $skippedCount, array $skipped): string
    {
        $lines = [
            "Campaign: {$campaign}",
            "Added: {$imported}",
            "Duplicates skipped: {$duplicates}",
            "Invalid rows skipped: {$skippedCount}",
        ];

        if ($skippedCount > 0) {
            $reasons = collect($skipped)
                ->countBy('reason')
                ->sortDesc()
                ->take(3)
                ->map(fn (int $count, string $reason): string => "{$reason} ({$count})")
                ->values()
                ->all();

            $lines[] = 'Top reasons: '.implode(', ', $reasons);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  callable(Notification): Notification  $build
     */
    private function sendNotification(callable $build): void
    {
        $user = User::find($this->uploaderId);

        if (! $user) {
            return;
        }

        $build(Notification::make())->sendToDatabase($user);
    }
}
