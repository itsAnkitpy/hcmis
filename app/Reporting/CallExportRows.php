<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Enums\CallDirection;
use App\Filament\Support\ClientColumn;
use App\Models\Call;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Call Export's one row per call (call-export.md CE-4 + CE-5a) — the menu, and the
 * single query that fills it.
 *
 * THE MENU RULE (CE-4): we choose the columns, nobody ticks them, so we never list a
 * dish the kitchen cannot cook. Every column here is filled by the real call path, not
 * by the seeder. Columns DialShree has and we would export empty on every row are
 * deliberately absent — hold time, IVR time, user group, lists, dialer fields, surveys,
 * and `leads.attempts`, which exists as a column that nothing in the call path ever
 * increments and would therefore export authoritative-looking fiction.
 *
 * ONE BUILDER (CE-3 + CE-5a): query() is the only place a calls query is built for this
 * slice. The row cap counts it and the writer walks it, so the count can never disagree
 * with the file. A second builder would drift silently — the count says 49,000 and the
 * file has 51,000 rows.
 *
 * BUILD STATE: the client-zone day boundary (CE-10a) and the 50,000-row refusal (CE-3)
 * are the next steps. The dates below are cut in UTC and the headers say so, which is
 * true today and becomes false the moment CE-10 lands — so the zone lives in exactly
 * one method here, ready to be told a different one.
 */
final class CallExportRows
{
    /** How many rows one database round trip fetches while walking the range. */
    private const CHUNK = 1000;

    /**
     * @param  array<string, mixed>  $filters  The validated query parameters (CE-5d).
     */
    public function __construct(private readonly array $filters) {}

    /**
     * The one query. Every filter, the date boundary, and the relations each row reads.
     *
     * @return Builder<Call>
     */
    public function query(): Builder
    {
        // Read before the chain: `when(false, …)` does NOT run its callback, so a
        // "without a recording" filter passed straight into when() would be silently
        // dropped and the export would return every call. Only the null/not-null
        // question belongs in when(); which way it points is decided inside.
        $hasRecording = $this->hasRecording();

        return Call::query()
            ->with($this->relations())
            ->addSelect('calls.*')
            // "Passed on" = another row shares this call's ticket, i.e. it was handed to
            // a colleague (CT-5). Done as a correlated sub-select rather than the model
            // read §5 first sketched: a per-row method is one query PER ROW, which is
            // exactly what CE-5a's pre-loading exists to avoid and what §8's query-count
            // test would catch. Rides the existing `correlation_id` index; tenant_id is
            // matched explicitly so it stays correct in the cross-client posture, where
            // the wall is deliberately not narrowing.
            ->selectRaw(
                '(select count(*) from calls sibling'
                .' where sibling.correlation_id = calls.correlation_id'
                .' and sibling.tenant_id = calls.tenant_id) > 1 as was_passed_on'
            )
            ->when($this->date('startDate'), fn (Builder $q, CarbonImmutable $from): Builder => $q
                ->where('calls.created_at', '>=', $from))
            // CE-4a: half-open — the day AFTER the end date, exclusive. `<= $endDate`
            // alone means midnight and drops nearly the whole final day; the Calls list
            // uses whereDate, which means the whole of it. This form matches the list
            // and has no "how many decimal places does a timestamp have" question in it.
            ->when($this->date('endDate'), fn (Builder $q, CarbonImmutable $until): Builder => $q
                ->where('calls.created_at', '<', $until->addDay()))
            ->when($this->id('agentId'), fn (Builder $q, int $id): Builder => $q->where('agent_id', $id))
            ->when($this->id('campaignId'), fn (Builder $q, int $id): Builder => $q->where('campaign_id', $id))
            ->when($this->id('dispositionId'), fn (Builder $q, int $id): Builder => $q->where('disposition_id', $id))
            // Only global staff can move this needle: everyone else is pinned by the
            // wall, so a hand-edited clientId yields an empty AND, never other rows.
            ->when($this->id('clientId'), fn (Builder $q, int $id): Builder => $q->where('calls.tenant_id', $id))
            ->when($this->filters['direction'] ?? null, fn (Builder $q, string $d): Builder => $q->where('direction', $d))
            ->when($this->filters['outcome'] ?? null, fn (Builder $q, string $o): Builder => $q->where('outcome', $o))
            ->when($hasRecording !== null, fn (Builder $q): Builder => $hasRecording
                ? $q->whereNotNull('recording_path')
                : $q->whereNull('recording_path'));
    }

    /**
     * The column headings, in the order rows() writes them.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return array_values(array_filter([
            'Call ID',
            'Ticket',
            $this->showsClient() ? 'Client' : null,
            'Started ('.$this->zone().')',
            'Direction',
            'Customer number',
            'Our number',
            'Agent',
            'Campaign',
            'Lead ID',
            'Lead name',
            'Lead entered ('.$this->zone().')',
            'Disposition',
            'Sale',
            'Contact',
            // CE-4: the header says provisional because in v1 the outcome is derived
            // from the agent's disposition, not from the line result. A column that
            // looks like a telecom fact and is an agent's opinion needs saying so.
            'Outcome (provisional)',
            'Queue time',
            'Ring time',
            'Waited',
            'Dial time',
            'Talked',
            'Wrap-up',
            'Recording',
            'Recording link',
            'Passed on',
        ], fn (?string $heading): bool => $heading !== null));
    }

    /**
     * One array per call, newest first, memory flat however many there are.
     *
     * lazyByIdDesc, not lazyById: the by-id walk is the one that cannot skip or repeat
     * a row while calls keep arriving mid-export, and DESCENDING is what matches the
     * Calls list (which sorts created_at desc). Ascending would return the right rows
     * upside down. As a bonus, calls arriving mid-write get higher ids and are simply
     * never visited, so the file is a clean snapshot of the moment it started.
     *
     * @return Generator<int, array<int, string|int|null>>
     */
    public function rows(): Generator
    {
        foreach ($this->query()->lazyByIdDesc(self::CHUNK) as $call) {
            yield $this->row($call);
        }
    }

    /**
     * @return array<int, string|int|null>
     */
    private function row(Call $call): array
    {
        $outbound = $call->direction === CallDirection::Outbound;

        return array_values(array_filter([
            'id' => $call->id,
            'ticket' => $call->correlation_id ?? '',
            'client' => $this->showsClient() ? ($call->tenant?->name ?? '') : null,
            // CE-4a: the range filters on created_at (the Done click, the one indexed
            // date), but the column SHOWS when the call began. Filter by one, show the
            // other — they answer different questions and both are honest. Outbound has
            // no arrival at all (CT-8, nobody waited), so it falls back to the ring.
            'started' => $this->moment($call->started_at ?? $call->ringing_at),
            'direction' => $call->direction->label(),
            // Which end is the customer flips with the direction: outbound we dialled
            // them (to_number), inbound they dialled us (from_number).
            'customer' => ($outbound ? $call->to_number : $call->from_number) ?? '',
            // CE-4b: the other end of the same pair. On outbound this is one configured
            // caller ID (AgentConsole fills it from telephony.outbound.caller_id), so
            // it is an identical string on every outbound row — do not read it as
            // evidence of anything. On inbound it is the number they rang, which the
            // ANSWERED path does not yet carry (blank until CE-6); a missed call
            // already has it, because CallToAgentFlow writes the dialled number.
            'ours' => ($outbound ? $call->from_number : $call->to_number) ?? '',
            'agent' => $call->agent?->name ?? '',
            'campaign' => $call->campaign?->name ?? '',
            'lead_id' => $call->lead_id ?? '',
            'lead_name' => $call->lead?->name ?? '',
            'lead_entered' => $this->moment($call->lead?->created_at),
            'disposition' => $call->disposition?->label ?? '',
            'sale' => $this->flag($call->disposition?->is_sale),
            'contact' => $this->flag($call->disposition?->is_contact),
            'outcome' => $call->outcome?->label() ?? '',
            // The wait, split the way their column sheet splits it. Queue is the caller
            // holding before any phone rang; Ring is a phone ringing; Waited is both
            // together, which is the number the Calls list shows.
            'queue' => $this->duration($this->secondsBetween($call->started_at, $call->ringing_at)),
            'ring' => $this->duration($this->secondsBetween($call->ringing_at, $call->answered_at)),
            'waited' => $this->duration($call->waitedSeconds()),
            // Their "Answered time" for outbound: the same ring→pickup span, presented
            // as its own column because their sheet reads it as a separate figure.
            'dial' => $outbound
                ? $this->duration($this->secondsBetween($call->ringing_at, $call->answered_at))
                : '',
            'talked' => $this->duration($call->talkedSeconds()),
            'wrap' => $this->duration($this->secondsBetween($call->ended_at, $call->created_at)),
            'recording' => $this->flag(filled($call->recording_path)),
            // Safe to hand out: recordings are served through a login-gated,
            // audit-logged route, never a public URL.
            'recording_link' => filled($call->recording_path) ? route('calls.recording', $call) : '',
            'passed_on' => $this->flag((bool) $call->was_passed_on),
        ], fn (string|int|null $value): bool => $value !== null));
    }

    /**
     * The relations every row reads. Four always; the owning client only when the
     * Client column is being written — the same predicate the Calls list keys its own
     * eager-load off, deliberately, so the column and its pre-load cannot drift apart.
     *
     * @return array<int, string>
     */
    private function relations(): array
    {
        return $this->showsClient()
            ? ['agent', 'campaign', 'lead', 'disposition', 'tenant']
            : ['agent', 'campaign', 'lead', 'disposition'];
    }

    /** Client attribution is meaningful only in the cross-client posture. */
    private function showsClient(): bool
    {
        return ClientColumn::shouldShow();
    }

    /**
     * The zone the timestamps are written in. One method, because CE-10 replaces its
     * body with the client's own zone and nothing else has to move.
     */
    private function zone(): string
    {
        return 'UTC';
    }

    private function moment(?DateTimeInterface $at): string
    {
        return $at === null
            ? ''
            : CarbonImmutable::instance($at)->timezone($this->zone())->format('Y-m-d H:i:s');
    }

    /**
     * CE-7 point 3. Whole seconds by default, because a clock-formatted duration gets
     * silently re-read by Excel as a time of day. This is a choice the user made on the
     * filter form about what the number MEANS, which is why it shapes the row here
     * rather than living in the CSV writer — that class is handed finished strings and
     * never sees a filter.
     */
    private function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        return ($this->filters['durationFormat'] ?? 'seconds') === 'clock'
            ? gmdate($seconds >= 3600 ? 'H:i:s' : 'i:s', $seconds)
            : (string) $seconds;
    }

    /** A yes/no column, blank when the fact behind it does not exist (CE-4). */
    private function flag(?bool $value): string
    {
        return $value === null ? '' : ($value ? 'Yes' : 'No');
    }

    private function secondsBetween(?DateTimeInterface $from, ?DateTimeInterface $to): ?int
    {
        return $from !== null && $to !== null
            ? (int) CarbonImmutable::instance($from)->diffInSeconds($to)
            : null;
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = $this->filters[$key] ?? null;

        // Interpreted in the export's zone, then compared against created_at, which
        // Postgres stores in UTC. Today those are the same thing; CE-10a is the day
        // this line starts doing real work.
        return filled($value)
            ? CarbonImmutable::parse((string) $value, $this->zone())->startOfDay()->utc()
            : null;
    }

    private function id(string $key): ?int
    {
        $value = $this->filters[$key] ?? null;

        return filled($value) ? (int) $value : null;
    }

    private function hasRecording(): ?bool
    {
        $value = $this->filters['hasRecording'] ?? null;

        return $value === null || $value === '' ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
