<?php

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * call-export.md CE-10 — the client's report zone as a stored setting.
 *
 * Both tests here exist because both failures are SILENT. A column missing from
 * `$fillable` does not error, it just never saves; a column missing from the audit
 * allowlist does not error either, and its absence only surfaces months later when
 * somebody asks why every report before a certain date reads an hour out.
 */
it('saves the zone a client is given', function () {
    $tenant = Tenant::factory()->create(['timezone' => null]);

    $tenant->update(['timezone' => 'Asia/Kolkata']);

    expect($tenant->fresh()->timezone)->toBe('Asia/Kolkata');
});

it('falls back to the system default, and prefers the client\'s own zone over it', function () {
    config(['app.report_timezone' => 'Asia/Kolkata']);

    expect(Tenant::factory()->create(['timezone' => null])->reportTimezone())->toBe('Asia/Kolkata')
        ->and(Tenant::factory()->create(['timezone' => 'Europe/London'])->reportTimezone())->toBe('Europe/London');
});

// Changing this moves calls between days in every report from that moment on. That is
// exactly the change worth a record of — and the reason CE-10 chose a real column over
// `tenants.settings`, which is deliberately excluded from the trail (D-M7-2).
//
// Read from `attribute_changes`, not `properties`: this version of the activity log
// keeps automatic before/after model changes in their own column and leaves `properties`
// for the hand-written entries in App\Audit\Audit.
it('records a zone change in the audit trail, with both the old and new value', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'UTC']);

    $tenant->update(['timezone' => 'Asia/Kolkata']);

    $activity = TenantContext::cross(fn (): ?ActivityLog => ActivityLog::query()
        ->where('subject_type', Tenant::class)
        ->where('subject_id', $tenant->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first());

    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes['attributes']['timezone'])->toBe('Asia/Kolkata')
        ->and($activity->attribute_changes['old']['timezone'])->toBe('UTC');
});
