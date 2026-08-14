<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Filament\Pages\Reports\CallExportReport;
use App\Models\Call;
use App\Reporting\CallExportRows;
use App\Reporting\CallReportCsv;
use App\Tenancy\TenantContext;
use Filament\Notifications\Notification;
use Generator;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Call Export download (call-export.md CE-5b) — one row per call, streamed.
 *
 * WHY a plain route and not a Filament button: Livewire captures a download into an
 * output buffer, base64-encodes it (a third larger) and returns it inside the page's
 * JSON reply, so every byte is materialised in memory twice whatever the row source
 * does. A streamed response cannot survive that channel; it needs its own address.
 *
 * Middleware (routes/web.php, the same group as calls.recording): `auth` +
 * SetCurrentTenant, so the tenant wall (RLS) is in force for the whole write —
 * SetCurrentTenant clears the binding in terminate(), which Laravel runs AFTER the
 * response is sent, so a slow export keeps its client to the last row.
 *
 * Four guards, in order:
 *  1. `viewAny` (CE-5c), NOT `view`. CallRecordingController's per-row `view` is
 *     deliberately passable by an agent for their own call (My Day MD-1), and there is
 *     no row here to scope it against — copying it would open the whole export to every
 *     agent, silently. Row scoping stays the tenant wall's job.
 *  2. query-parameter validation (CE-5d). On a Filament page the filters are form state;
 *     in a web address they are strings anyone can edit. Not a leak — the wall still
 *     refuses another client's rows, so an edited clientId yields an EMPTY file — but
 *     `?startDate=nonsense` would reach a date comparison and 500 on a download link.
 *  3. the row cap (CE-3). Counted before the first byte, so the export refuses rather
 *     than truncating — a short file that looks complete is worse than no file.
 *  4. one export at a time per user (CE-3a). Prevents the supervisor who presses
 *     Download twice because nothing visibly happened. Someone else's export is
 *     unaffected — a global cap is the stage-2 companion (§9.3), not this.
 *  5. a time limit on THIS route only. A generous limit application-wide hides real
 *     problems everywhere else; this route is the only one that knows it is allowed to
 *     take minutes.
 *
 * Guards 3 and 4 send the user back to the export screen with the reason on it, rather
 * than aborting. WHY (S108): `abort(409, 'Your previous export is still downloading')`
 * shows that sentence only while APP_DEBUG is on. With debug off — which is every real
 * deployment — Laravel has no view for 409 or 422, falls back to Symfony's renderer, and
 * the supervisor gets "An Error Occurred: Conflict" with our message thrown away. CE-3
 * requires the refusal to name the real count and a next step, so the message has to
 * travel somewhere it will actually be rendered: a Filament notification on the page
 * they came from.
 *
 * BUILD STATE: all eight CE steps are built (S108) — guards, streaming, the real menu
 * and the one filtered query (CE-4/CE-5a, in CallExportRows), both refusals, the
 * spreadsheet-safety rules (CE-7, in CallReportCsv), the client's own zone for both the
 * printed time and the day boundary (CE-10/CE-10a), and the campaign's custom fields
 * (CE-12). What remains is the queued export for ranges over the cap (§9.3 stage 2).
 */
class CallExportController extends Controller
{
    /**
     * How long one export may run — the lock's time-to-live and the route's own time
     * limit, deliberately the same number. If a worker is killed mid-write the lock
     * expires on its own rather than locking the supervisor out until a cache flush.
     */
    private const MAX_SECONDS = 900;

    public function __invoke(Request $request): RedirectResponse|StreamedResponse
    {
        Gate::authorize('viewAny', Call::class);

        $rows = new CallExportRows($this->validatedFilters($request));

        // Raised BEFORE the count, not after. The count below is itself a full scan of
        // the filtered range, and on the very export CE-3 exists to refuse it is the
        // slowest query this route runs — under php-fpm's ordinary 30s it is the count,
        // not the writing, that hits the wall first. That would 500 the request and lose
        // the refusal message, which is the exact S108 failure this whole guard was
        // rewritten to avoid.
        set_time_limit(self::MAX_SECONDS);

        // CE-3. Counting the SAME builder the writer walks (CE-5a's one-builder rule) is
        // what makes the number in the message the number in the file. Counted before the
        // lock is taken, so a refused export never locks the supervisor out of the retry
        // it just told them to make.
        $count = $rows->query()->count();
        $limit = CallExportRows::maxRows();

        if ($count > $limit) {
            return $this->refuse(
                sprintf('That is %s calls. The limit is %s.', number_format($count), number_format($limit)),
                'Try a shorter date range, or add a filter to narrow it down.',
            );
        }

        $lock = Cache::lock("call-export:{$request->user()->id}", self::MAX_SECONDS);

        // CE-3a. The message says what is happening, not that something failed.
        if (! $lock->get()) {
            return $this->refuse(
                'Your previous export is still downloading.',
                'Wait for it to finish, or cancel it in your browser\'s downloads, then try again.',
            );
        }

        return CallReportCsv::stream(
            'calls-'.now()->format('Y-m-d').'.csv',
            $rows->headings(),
            $this->rows($rows, $lock, TenantContext::id(), TenantContext::isCrossTenant()),
        );
    }

    /**
     * Turn the user around at the export screen with the reason in front of them.
     *
     * Filament flashes the notification to the session and the panel renders it on the
     * next page load, which is exactly what a plain redirect gives us. The destination is
     * named rather than `back()`: the link can be bookmarked or hand-edited, and a
     * refusal that lands on the site root explains nothing.
     */
    private function refuse(string $title, string $body): RedirectResponse
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body($body)
            ->persistent()
            ->send();

        return redirect()->to(CallExportReport::getUrl());
    }

    /**
     * The rows, the tenant binding, and the lock's whole lifetime.
     *
     * WHY the binding is re-stamped here. `streamDownload()` hands its callback back
     * UNRUN — Symfony runs it when the response is sent, after this controller has
     * returned. So every row is read OUTSIDE the controller, and the export's tenancy
     * would rest entirely on send() happening before SetCurrentTenant::terminate().
     * That ordering holds in a normal HTTP request today, but it is an invisible
     * dependency on the one screen whose whole job is writing every call to a file, and
     * the failure mode when it changes is the tenant scope throwing mid-download —
     * after a 200 and half a file have already reached the browser. Re-stamping the
     * posture we captured in the controller makes the write self-contained, which is
     * exactly what ImportLeadsJob and AttachRecordingToCall do for the same reason.
     * Re-asserting a binding that is already correct costs one SET and changes nothing.
     *
     * The same reasoning puts the `finally` here rather than around the controller
     * body: a finally in the controller would release the lock before the first row was
     * written — a guard that compiles, passes a careless test, and prevents nothing. A
     * generator's finally runs when it completes AND when it is destroyed, so an
     * abandoned download (the supervisor closes the tab) releases the lock too.
     *
     * @return Generator<int, array<int, string|int>>
     */
    private function rows(CallExportRows $source, Lock $lock, ?int $tenantId, bool $crossTenant): Generator
    {
        TenantContext::applyWebRequest($tenantId, $crossTenant);

        try {
            yield from $source->rows();
        } finally {
            $lock->release();
            TenantContext::resetWebRequest();
        }
    }

    /**
     * CE-5d. Field names follow CallReportFilters (the two grouped report pages), not
     * CallsTable's column-shaped ones: this screen is a report page, flat names survive
     * a web address where a nested `date.from` group does not, and three of these nine
     * do not exist on the Calls list at all.
     *
     * Everything is nullable — absent means unfiltered, which is the honest default for
     * a link built from an empty filter form.
     *
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'agentId' => ['nullable', 'integer', 'min:1'],
            'campaignId' => ['nullable', 'integer', 'min:1'],
            'clientId' => ['nullable', 'integer', 'min:1'],
            'dispositionId' => ['nullable', 'integer', 'min:1'],
            'direction' => ['nullable', Rule::enum(CallDirection::class)],
            'outcome' => ['nullable', Rule::enum(CallOutcome::class)],
            'hasRecording' => ['nullable', 'boolean'],
            'durationFormat' => ['nullable', 'in:seconds,clock'],
        ]);
    }
}
