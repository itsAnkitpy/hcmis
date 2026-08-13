<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Models\Call;
use App\Reporting\CallExportRows;
use App\Reporting\CallReportCsv;
use App\Tenancy\TenantContext;
use Generator;
use Illuminate\Contracts\Cache\Lock;
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
 *  3. one export at a time per user (CE-3a). Prevents the supervisor who presses
 *     Download twice because nothing visibly happened. Someone else's export is
 *     unaffected — a global cap is the stage-2 companion (§9.3), not this.
 *  4. a time limit on THIS route only. A generous limit application-wide hides real
 *     problems everywhere else; this route is the only one that knows it is allowed to
 *     take minutes.
 *
 * BUILD STATE (steps 1-2 of the CE build order): guards, streaming, the real menu and
 * the one filtered query (CE-4/CE-5a, in CallExportRows). Still to come: the 50,000-row
 * refusal (CE-3), the Excel-safety rules (CE-7), and the client-zone day boundary
 * (CE-10/CE-10a), which today is cut in UTC and labelled as such in the headers.
 */
class CallExportController extends Controller
{
    /**
     * How long one export may run — the lock's time-to-live and the route's own time
     * limit, deliberately the same number. If a worker is killed mid-write the lock
     * expires on its own rather than locking the supervisor out until a cache flush.
     */
    private const MAX_SECONDS = 900;

    public function __invoke(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', Call::class);

        $filters = $this->validatedFilters($request);

        $lock = Cache::lock("call-export:{$request->user()->id}", self::MAX_SECONDS);

        abort_if(
            ! $lock->get(),
            409,
            'Your previous export is still downloading. Wait for it to finish, or cancel it.',
        );

        set_time_limit(self::MAX_SECONDS);

        $rows = new CallExportRows($filters);

        return CallReportCsv::stream(
            'calls-'.now()->format('Y-m-d').'.csv',
            $rows->headings(),
            $this->rows($rows, $lock, TenantContext::id(), TenantContext::isCrossTenant()),
        );
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
