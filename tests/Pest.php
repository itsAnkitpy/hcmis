<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create a user holding a per-client role scoped to the given tenant's team
 * (the spatie-teams posture). Shared by the Filament feature tests.
 */
function clientUserWithRole(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->tenants()->attach($tenant);

    TenantContext::run($tenant->id, function () use ($user, $role) {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    });

    return $user;
}

/**
 * Write a simple CSV onto the (faked) local disk for the lead importer to read.
 * Shared by the M5 import tests.
 *
 * @param  array<int, array<int, string>>  $rows
 * @param  array<int, string>  $headers
 */
function writeLeadCsv(string $path, array $rows, array $headers = ['phone', 'name', 'email', 'region']): void
{
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    Storage::disk('local')->put($path, implode("\n", $lines));
}
