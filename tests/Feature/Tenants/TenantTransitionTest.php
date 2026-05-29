<?php

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Tenancy\InvalidTenantTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stamps suspended_at and persists the reason when suspending', function () {
    $tenant = Tenant::factory()->create();

    $tenant->transitionTo(TenantStatus::Suspended, 'late payment');

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Suspended)
        ->and($tenant->suspended_at)->not->toBeNull()
        ->and($tenant->status_reason)->toBe('late payment');
});

it('clears suspended_at when going back to active', function () {
    $tenant = Tenant::factory()->suspended()->create([
        'status_reason' => 'late payment',
    ]);

    $tenant->transitionTo(TenantStatus::Active, 'paid up');

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->suspended_at)->toBeNull()
        ->and($tenant->status_reason)->toBe('paid up');
});

it('stamps archived_at when archiving from active', function () {
    $tenant = Tenant::factory()->create();

    $tenant->transitionTo(TenantStatus::Archived, 'contract ended');

    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Archived)
        ->and($tenant->archived_at)->not->toBeNull()
        ->and($tenant->status_reason)->toBe('contract ended');
});

it('refuses to leave the archived state', function () {
    $tenant = Tenant::factory()->archived()->create();

    expect(fn () => $tenant->transitionTo(TenantStatus::Active))
        ->toThrow(InvalidTenantTransitionException::class);

    expect(fn () => $tenant->transitionTo(TenantStatus::Suspended))
        ->toThrow(InvalidTenantTransitionException::class);
});

it('is a no-op when transitioning to the same status', function () {
    $tenant = Tenant::factory()->create();
    $originalUpdatedAt = $tenant->updated_at;

    $result = $tenant->transitionTo(TenantStatus::Active, 'noop');

    expect($result)->toBe($tenant)
        ->and($tenant->fresh()->updated_at?->equalTo($originalUpdatedAt))->toBeTrue()
        ->and($tenant->fresh()->status_reason)->toBeNull();
});

it('exposes the from/to states on the exception', function () {
    $tenant = Tenant::factory()->archived()->create();

    try {
        $tenant->transitionTo(TenantStatus::Active);
        $this->fail('Expected exception was not thrown.');
    } catch (InvalidTenantTransitionException $e) {
        expect($e->from)->toBe(TenantStatus::Archived)
            ->and($e->to)->toBe(TenantStatus::Active);
    }
});
