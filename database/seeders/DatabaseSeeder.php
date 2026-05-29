<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Order matters: the admin user must exist
     * before RolesSeeder assigns it super_admin.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            RolesSeeder::class,
        ]);
    }
}
