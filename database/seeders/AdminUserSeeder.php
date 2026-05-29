<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * A local admin login for the Filament panel. Role assignment (super_admin)
     * lands in M2 Checkpoint C once roles are seeded. Idempotent.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@hcmis.test'],
            [
                'name' => 'HC Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
    }
}
