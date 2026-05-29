<?php

use App\Enums\TenantStatus;

it('marks only active as operable', function () {
    expect(TenantStatus::Active->isOperable())->toBeTrue()
        ->and(TenantStatus::Suspended->isOperable())->toBeFalse()
        ->and(TenantStatus::Archived->isOperable())->toBeFalse();
});

it('allows active <-> suspended and either -> archived', function () {
    expect(TenantStatus::Active->canTransitionTo(TenantStatus::Suspended))->toBeTrue()
        ->and(TenantStatus::Active->canTransitionTo(TenantStatus::Archived))->toBeTrue()
        ->and(TenantStatus::Suspended->canTransitionTo(TenantStatus::Active))->toBeTrue()
        ->and(TenantStatus::Suspended->canTransitionTo(TenantStatus::Archived))->toBeTrue();
});

it('makes archived terminal', function () {
    expect(TenantStatus::Archived->allowedTransitions())->toBe([])
        ->and(TenantStatus::Archived->canTransitionTo(TenantStatus::Active))->toBeFalse()
        ->and(TenantStatus::Archived->canTransitionTo(TenantStatus::Suspended))->toBeFalse();
});
