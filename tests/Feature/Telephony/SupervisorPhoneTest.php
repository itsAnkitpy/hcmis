<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Filament\Pages\LiveAgents;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AgentPresence;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AgentPhoneWriter;
use App\Telephony\AgentRouter;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantContext::forget();
    createAsteriskPhoneTables();

    foreach (RoleName::globals() as $role) {
        Role::findOrCreate($role->value, 'web');
    }

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(RoleName::SuperAdmin->value);

    $this->tenant = Tenant::factory()->create();
    $this->supervisor = clientUserWithRole($this->tenant, RoleName::TeamLeader->value);
});

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

// --- SM-1: the missing trigger — a phone for someone who is not an agent ---

it('offers the issue button only to someone who holds no number at all', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $this->supervisor->getRouteKey()])
        ->assertActionVisible('issue_phone')
        ->assertActionHidden('retire_phone')
        ->assertActionHidden('reissue_phone');

    app(AgentPhoneWriter::class)->provisionFor($this->supervisor);

    Livewire::test(EditUser::class, ['record' => $this->supervisor->fresh()->getRouteKey()])
        ->assertActionHidden('issue_phone')
        ->assertActionVisible('retire_phone');
});

it('gives a team leader a working phone without touching their roles', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $this->supervisor->getRouteKey()])
        ->callAction('issue_phone');

    $extension = $this->supervisor->fresh()->sip_extension;

    expect($extension)->not->toBeNull()
        ->and(app(AgentPhoneWriter::class)->hasKey($this->supervisor->fresh()))->toBeTrue()
        ->and(TenantContext::run(
            $this->tenant->id,
            fn (): array => $this->supervisor->fresh()->getRoleNames()->all(),
        ))->toBe([RoleName::TeamLeader->value]);
});

// --- SM-3: a phone is not a place in the queue ---

/**
 * 🔴 This is the never-Ready rule, and it is asserted on the ABSENCE OF A BOARD ROW
 * rather than on "SetAgentPresence was not called".
 *
 * The review that preceded this slice found the plan's premise slightly off: status
 * is not written through one door. AgentRouter writes it directly when it books a
 * desk, the agent console writes the heartbeat stamp directly, and ReservationReaper
 * frees a stuck one. What every one of those has in common is that they only ever
 * UPDATE a row that already exists — updateOrCreate inside SetAgentPresence is the
 * only thing in the codebase that creates one. So the guarantee worth pinning is
 * that no row is ever created for a supervisor, which is what keeps them out of the
 * pool no matter which of those writers runs.
 *
 * Pinning "the door was not knocked on" would pass while missing that.
 */
it('never books a supervisor a call, however long they hold a registered phone', function () {
    $agent = clientUserWithRole($this->tenant, RoleName::Agent->value);

    // Both hold a phone. The only difference is that one of them is on the board.
    app(AgentPhoneWriter::class)->provisionFor($this->supervisor);
    app(AgentPhoneWriter::class)->provisionFor($agent);

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::Ready)->create();
    });

    // The supervisor sits on the floor board with their phone registered.
    $this->actingAs($this->supervisor->fresh());
    TenantContext::run($this->tenant->id, function (): void {
        Livewire::test(LiveAgents::class)->assertOk();
    });

    $reserved = app(AgentRouter::class)->reserveFreeAgent($this->tenant->id);

    expect($reserved)->toBe($agent->getKey())
        ->and(TenantContext::run(
            $this->tenant->id,
            fn (): bool => AgentPresence::query()->where('user_id', $this->supervisor->getKey())->exists(),
        ))->toBeFalse();
});

it('leaves nobody to ring when the only person holding a phone is a supervisor', function () {
    app(AgentPhoneWriter::class)->provisionFor($this->supervisor);

    $this->actingAs($this->supervisor->fresh());
    TenantContext::run($this->tenant->id, function (): void {
        Livewire::test(LiveAgents::class)->assertOk();
    });

    expect(app(AgentRouter::class)->reserveFreeAgent($this->tenant->id))->toBeNull();
});

// --- SM-2: the board hands the browser the reader's own phone, or nothing ---

it('hands a supervisor their own registration identity and never somebody elses', function () {
    $extension = app(AgentPhoneWriter::class)->provisionFor($this->supervisor);

    $this->actingAs($this->supervisor->fresh());

    $config = TenantContext::run(
        $this->tenant->id,
        fn (): array => Livewire::test(LiveAgents::class)->instance()->getPhoneConfig(),
    );

    expect($config['extension'])->toBe($extension)
        ->and($config['password'])->not->toBeEmpty();
});

it('hands nothing to a reader who was never issued a phone', function () {
    $qc = clientUserWithRole($this->tenant, RoleName::Qc->value);

    $this->actingAs($qc->fresh());

    $config = TenantContext::run(
        $this->tenant->id,
        fn (): array => Livewire::test(LiveAgents::class)->instance()->getPhoneConfig(),
    );

    expect($config['extension'])->toBeNull()
        ->and($config['password'])->toBeNull();
});

// --- SM slice 2: the Listen button, and what happens when it is pressed ---

/**
 * The gap slice 1 left open. The phone panel already hides itself for a reader with no
 * phone, but a button on a ROW would still have been clickable for them and would have
 * silently done nothing — which reads as a broken screen rather than as a capability
 * they do not have.
 */
it('offers listening only to a reader who holds a phone of their own', function () {
    $qc = clientUserWithRole($this->tenant, RoleName::Qc->value);

    $this->actingAs($qc->fresh());
    TenantContext::run($this->tenant->id, function (): void {
        expect(Livewire::test(LiveAgents::class)->instance()->hasPhone())->toBeFalse();
    });

    app(AgentPhoneWriter::class)->provisionFor($this->supervisor);
    $this->actingAs($this->supervisor->fresh());
    TenantContext::run($this->tenant->id, function (): void {
        expect(Livewire::test(LiveAgents::class)->instance()->hasPhone())->toBeTrue();
    });
});

it('offers listening only on a row that is actually on a call', function () {
    $onCall = clientUserWithRole($this->tenant, RoleName::Agent->value);
    $ready = clientUserWithRole($this->tenant, RoleName::Agent->value);

    TenantContext::run($this->tenant->id, function () use ($onCall, $ready): void {
        AgentPresence::factory()->forUser($onCall)->status(PresenceStatus::OnCall)->create();
        AgentPresence::factory()->forUser($ready)->status(PresenceStatus::Ready)->create();
    });

    $this->actingAs($this->supervisor->fresh());

    $rows = TenantContext::run(
        $this->tenant->id,
        fn (): array => collect(Livewire::test(LiveAgents::class)->instance()->roster())
            ->keyBy('id')
            ->all(),
    );

    expect($rows[$onCall->getKey()]['canMonitor'])->toBeTrue()
        ->and($rows[$ready->getKey()]['canMonitor'])->toBeFalse();
});

it('signals the phone program and audits the access when a supervisor listens in', function () {
    $agent = clientUserWithRole($this->tenant, RoleName::Agent->value);
    app(AgentPhoneWriter::class)->provisionFor($this->supervisor);

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnCall)->create();
    });

    // 🔴 The supervisor is named from web auth, never from the browser — the button
    // sends only which agent to listen to.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('signal')->once()->with('listen', [
        'agentUserId' => (string) $agent->getKey(),
        'supervisorUserId' => (string) $this->supervisor->getKey(),
    ]);
    app()->instance(TelephonyProvider::class, $telephony);

    $this->actingAs($this->supervisor->fresh());

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        Livewire::test(LiveAgents::class)->call('listenTo', $agent->getKey());

        // SM-5, pulled forward from slice 4: hearing a live customer is an access to
        // their voice, filed the same way a recording playback already is.
        expect(Activity::query()
            ->where('log_name', 'call')
            ->where('event', 'live_call_monitored')
            ->where('causer_id', $this->supervisor->getKey())
            ->exists())->toBeTrue();
    });
});

it('sends the coaching signal and audits it as coaching, not as listening', function () {
    $agent = clientUserWithRole($this->tenant, RoleName::Agent->value);
    app(AgentPhoneWriter::class)->provisionFor($this->supervisor);

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnCall)->create();
    });

    // 🔴 SM slice 3: the SIGNAL NAME carries the mode, which is why the two buttons are
    // two methods rather than one taking a mode from the browser. There is no mode
    // argument anywhere on this path for a crafted request to bend.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('signal')->once()->with('whisper', [
        'agentUserId' => (string) $agent->getKey(),
        'supervisorUserId' => (string) $this->supervisor->getKey(),
    ]);
    app()->instance(TelephonyProvider::class, $telephony);

    $this->actingAs($this->supervisor->fresh());

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        Livewire::test(LiveAgents::class)->call('whisperTo', $agent->getKey());

        // The audit row has to say WHICH, because the two are different accesses: one is
        // hearing a customer, the other is also speaking into a live call. A trail that
        // records both as "monitored" cannot answer the question it exists to answer.
        expect(Activity::query()
            ->where('log_name', 'call')
            ->where('event', 'live_call_monitored')
            ->where('causer_id', $this->supervisor->getKey())
            ->where('properties->mode', 'whisper')
            ->exists())->toBeTrue();
    });
});

it('refuses to listen to an agent whose call has already ended', function () {
    $agent = clientUserWithRole($this->tenant, RoleName::Agent->value);
    app(AgentPhoneWriter::class)->provisionFor($this->supervisor);

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::WrappingUp)->create();
    });

    // The board refreshes every fifteen seconds, so a click can always name somebody
    // who has since hung up. Nothing is signalled and nothing is audited.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldNotReceive('signal');
    app()->instance(TelephonyProvider::class, $telephony);

    $this->actingAs($this->supervisor->fresh());

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        Livewire::test(LiveAgents::class)->call('listenTo', $agent->getKey());

        expect(Activity::query()->where('event', 'live_call_monitored')->exists())->toBeFalse();
    });
});

it('refuses to listen at all when the supervisor holds no phone', function () {
    $agent = clientUserWithRole($this->tenant, RoleName::Agent->value);

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnCall)->create();
    });

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldNotReceive('signal');
    app()->instance(TelephonyProvider::class, $telephony);

    $this->actingAs($this->supervisor->fresh());   // no phone issued

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        Livewire::test(LiveAgents::class)->call('listenTo', $agent->getKey());

        expect(Activity::query()->where('event', 'live_call_monitored')->exists())->toBeFalse();
    });
});

// --- SM-4 / slice 4: barge is a second right, not the board's own ---

it('offers barge to a team leader and never to a QC reviewer who can still listen', function () {
    $qc = clientUserWithRole($this->tenant, RoleName::Qc->value);

    // 🔴 THE WHOLE OF SM-4 IN ONE CASE. QC keeps the board, keeps Listen and keeps
    // Whisper — those are invisible to the customer. Barge puts a third voice on a live
    // customer call, and someone whose job is grading recordings is not automatically
    // someone who should interrupt one.
    $this->actingAs($qc->fresh());
    TenantContext::run($this->tenant->id, function (): void {
        $page = Livewire::test(LiveAgents::class)->instance();

        expect($page->canBarge())->toBeFalse()
            ->and($page::canAccess())->toBeTrue();
    });

    $this->actingAs($this->supervisor->fresh());
    TenantContext::run($this->tenant->id, function (): void {
        expect(Livewire::test(LiveAgents::class)->instance()->canBarge())->toBeTrue();
    });
});

it('sends the barge signal and audits it as barging, not as listening', function () {
    $agent = clientUserWithRole($this->tenant, RoleName::Agent->value);
    app(AgentPhoneWriter::class)->provisionFor($this->supervisor);

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnCall)->create();
    });

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('signal')->once()->with('barge', [
        'agentUserId' => (string) $agent->getKey(),
        'supervisorUserId' => (string) $this->supervisor->getKey(),
    ]);
    app()->instance(TelephonyProvider::class, $telephony);

    $this->actingAs($this->supervisor->fresh());

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        Livewire::test(LiveAgents::class)->call('bargeInto', $agent->getKey());

        // The trail has to say which of the three it was. Barging is the access a
        // customer could actually notice, and it is the one that lands on the recording
        // (SQ-1, answered the other way for this slice) — a row reading "monitored"
        // cannot tell it apart from silent listening.
        expect(Activity::query()
            ->where('log_name', 'call')
            ->where('event', 'live_call_monitored')
            ->where('causer_id', $this->supervisor->getKey())
            ->where('properties->mode', 'barge')
            ->exists())->toBeTrue();
    });
});

it('refuses a barge from someone who may listen but holds no right to barge', function () {
    $agent = clientUserWithRole($this->tenant, RoleName::Agent->value);
    $qc = clientUserWithRole($this->tenant, RoleName::Qc->value);
    app(AgentPhoneWriter::class)->provisionFor($qc);

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnCall)->create();
    });

    // 🔴 The button is not drawn for them, and the method is still reachable from any
    // browser — which is the only reason this check exists in the method at all. Nothing
    // is signalled and nothing is audited.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldNotReceive('signal');
    app()->instance(TelephonyProvider::class, $telephony);

    $this->actingAs($qc->fresh());

    TenantContext::run($this->tenant->id, function () use ($agent): void {
        Livewire::test(LiveAgents::class)
            ->call('bargeInto', $agent->getKey())
            ->assertForbidden();

        expect(Activity::query()->where('event', 'live_call_monitored')->exists())->toBeFalse();
    });
});
