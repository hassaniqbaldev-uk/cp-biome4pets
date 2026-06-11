<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * The single staging admin account. Any authenticated user has full admin
     * access (User::canAccessPanel() returns true for the Filament panel), so no
     * extra role/flag is required. Keyed on email so a reseed updates the one row
     * instead of duplicating it. Password is hashed — never stored in plaintext.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@biome4pets.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Admin@Biome2026!'),
                'email_verified_at' => now(),
            ],
        );
    }
}
