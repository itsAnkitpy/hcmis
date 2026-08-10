<?php

use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * One spelling per number, one lead per number per client.
 *
 * Before this pair existed, only the importer normalized phones and only the
 * importer skipped duplicates — so a lead typed in by hand could be stored as
 * `999-123 4567`, be invisible to screen-pop's exact match, AND sit beside a
 * second lead holding the same number in its bare form. The mutator closes the
 * spelling half at the one door every write path shares; the unique index
 * closes the duplicate half at the database.
 *
 * The two are deliberately tested together: the constraint is only meaningful
 * because the mutator makes the two spellings collide in the first place.
 */
it('normalizes a formatted phone on create', function () {
    $tenant = Tenant::factory()->create();

    // The re-read has to happen INSIDE the context — outside it RLS default-denies
    // and fresh() comes back null.
    $phone = TenantContext::run($tenant->id, fn (): string => Lead::factory()
        ->create(['phone' => ' (999) 123-4567 '])
        ->fresh()
        ->phone);

    expect($phone)->toBe('9991234567');
});

it('normalizes a formatted phone on update too', function () {
    $tenant = Tenant::factory()->create();

    $phone = TenantContext::run($tenant->id, function (): string {
        $lead = Lead::factory()->create(['phone' => '9991234567']);
        $lead->update(['phone' => '888-777 6666']);

        return $lead->fresh()->phone;
    });

    expect($phone)->toBe('8887776666');
});

it('refuses a second lead with the same number in one client', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        Lead::factory()->create(['phone' => '9991234567']);

        // The same number in a different spelling — normalized to the same
        // string by the mutator, then rejected by the unique index. Without the
        // mutator these would be two different strings and both would be stored.
        Lead::factory()->create(['phone' => '999-123 4567']);
    });
})->throws(QueryException::class);

it('allows the same number for two different clients', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();

    $a = TenantContext::run($clientA->id, fn (): Lead => Lead::factory()->create(['phone' => '9991234567']));
    $b = TenantContext::run($clientB->id, fn (): Lead => Lead::factory()->create(['phone' => '9991234567']));

    // The unique rule is (tenant_id, phone), not phone alone: one person can be
    // a lead for two of our clients, and neither may block the other.
    expect($a->id)->not->toBe($b->id)
        ->and($a->phone)->toBe($b->phone);
});
