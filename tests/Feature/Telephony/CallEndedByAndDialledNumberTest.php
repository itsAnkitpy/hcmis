<?php

use App\Enums\CallEndedBy;
use App\Enums\CallOutcome;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Campaign;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * call-export.md CE-6 + CE-11 — the two facts the listener has always known and thrown
 * away: WHICH of our numbers the caller rang, and WHICH SIDE hung up.
 *
 * Both ride the handoff note, because the listener owns them and may not write the call
 * row itself (D2's single-writer rule). These tests are about the note; the console half
 * — copying them onto the row and looking the campaign up — is in
 * tests/Feature/Filament/AgentConsoleEndedByTest.php.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => fakeAgentDirectory());   // SEC-1 slice 4: the reserved agent has a phone

afterEach(function () {
    TenantContext::forget();
});

beforeEach(function () {
    fakeNumberDirectory();
    Queue::fake();
});

/** @return array{0: Tenant, 1: int} */
function endedByTenant(): array
{
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');
    fakeAgentRouter($agent->id);

    return [$tenant, $agent->id];
}

function latestNoteFor(Tenant $tenant): ?CallHandoff
{
    return TenantContext::run($tenant->id, fn (): ?CallHandoff => CallHandoff::query()->latest('id')->first());
}

// CE-6. Without this the number dies with the call, which is why most inbound rows
// export with a blank Campaign — the biggest hole in the export's menu.
it('writes the number the caller rang onto the handoff note', function () {
    [$tenant] = endedByTenant();

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));

    expect(latestNoteFor($tenant)->dialled_number)->toBe(dialledNumberForTenant($tenant->id));
});

// CE-11, the branch that already existed and said nothing. The customer's leg going is
// the ordinary end of an inbound call.
it('records the customer as the side that hung up when the caller\'s leg ends', function () {
    [$tenant] = endedByTenant();

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn(
        new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard')
    );
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $flow->handle(stasisStart('agent-leg', ['agent']));   // agent picks up
    $flow->handle(channelDestroyed('caller-leg'));        // caller hangs up

    $note = latestNoteFor($tenant);

    expect($note->ended_by)->toBe(CallEndedBy::Customer)
        ->and($note->ended_at)->not->toBeNull();
});

it('records the agent as the side that hung up when their leg ends first', function () {
    [$tenant] = endedByTenant();

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn(
        new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard')
    );
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $telephony->shouldReceive('endConversation')->once();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $flow->handle(stasisStart('agent-leg', ['agent']));   // agent picks up
    $flow->handle(channelDestroyed('agent-leg'));         // the AGENT puts the phone down

    expect(latestNoteFor($tenant)->ended_by)->toBe(CallEndedBy::Agent);
});

// The missed-call writer has no note to ride — it writes the row itself. The two
// outcomes mean genuinely different things about who ended the call, and `System` exists
// so "we stopped waiting on their behalf" is not filed as "we do not know".
it('files an abandoned caller as ending the call themselves', function () {
    [$tenant] = endedByTenant();
    fakeAgentRouter(null); // nobody free, so the caller waits

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('playHoldMusic')->andReturnNull();
    $telephony->shouldReceive('stopHoldMusic')->andReturnNull();
    $telephony->shouldReceive('hangup')->andReturnNull();
    $telephony->shouldReceive('endConversation')->andReturnNull();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $flow->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => 'caller-leg']]);

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->outcome)->toBe(CallOutcome::Abandoned)
        ->and($call->ended_by)->toBe(CallEndedBy::Customer);
});

// CE-6, the half the console already had. Found on staging: the answered rows carried
// their campaign and the unanswered ones did not — and "which campaign is losing
// callers" is exactly the question these rows exist to answer.
it('files the campaign that owns the number on a call nobody answered', function () {
    [$tenant] = endedByTenant();
    fakeAgentRouter(null); // nobody free, so the caller waits and then gives up

    $campaignId = TenantContext::run($tenant->id, function () use ($tenant): int {
        $campaign = Campaign::factory()->create();

        PhoneNumber::factory()->forCampaign($campaign)->create([
            'number' => dialledNumberForTenant($tenant->id),
        ]);

        return $campaign->id;
    });

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('playHoldMusic')->andReturnNull();
    $telephony->shouldReceive('stopHoldMusic')->andReturnNull();
    $telephony->shouldReceive('hangup')->andReturnNull();
    $telephony->shouldReceive('endConversation')->andReturnNull();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $flow->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => 'caller-leg']]);

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->campaign_id)->toBe($campaignId);
});

// The number is real but nobody assigned it a campaign — a blank is the honest answer,
// not a crash and not a guess.
it('leaves the campaign blank when the number belongs to no campaign', function () {
    [$tenant] = endedByTenant();
    fakeAgentRouter(null);

    TenantContext::run($tenant->id, fn () => PhoneNumber::factory()->create([
        'number' => dialledNumberForTenant($tenant->id),
    ]));

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('playHoldMusic')->andReturnNull();
    $telephony->shouldReceive('stopHoldMusic')->andReturnNull();
    $telephony->shouldReceive('hangup')->andReturnNull();
    $telephony->shouldReceive('endConversation')->andReturnNull();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $flow->handle(['type' => 'ChannelDestroyed', 'channel' => ['id' => 'caller-leg']]);

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->campaign_id)->toBeNull();
});
