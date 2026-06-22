<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Seed the single Super Admin. No password is hard-coded in source: it comes
     * from ADMIN_PASSWORD (.env) if set, otherwise a strong random one printed
     * ONCE to the console. An EXISTING admin's password is never reset on reseed
     * (only the role is ensured) — so a reseed can't silently clobber the live
     * password or recreate a known backdoor. Additional Admins are created by the
     * Super Admin via the Users page, not seeded.
     */
    public function run(): void
    {
        $email = 'admin@biome4pets.com';

        if ($existing = User::where('email', $email)->first()) {
            if ($existing->role !== User::ROLE_SUPER_ADMIN) {
                $existing->update(['role' => User::ROLE_SUPER_ADMIN]);
            }

            $this->command?->info("Super Admin {$email} already exists — role ensured, password left unchanged.");

            return;
        }

        $fromEnv = filled(env('ADMIN_PASSWORD'));
        $password = $fromEnv ? (string) env('ADMIN_PASSWORD') : Str::password(20);

        User::create([
            'name' => 'Super Admin',
            'email' => $email,
            'role' => User::ROLE_SUPER_ADMIN,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        $this->command?->warn('──────────────────────────────────────────────────────────');
        $this->command?->warn("Super Admin created: {$email}");
        if ($fromEnv) {
            $this->command?->warn('Password: set from ADMIN_PASSWORD env.');
        } else {
            $this->command?->warn("Generated password (SHOWN ONCE — copy it now): {$password}");
            $this->command?->warn('Tip: set ADMIN_PASSWORD in .env to choose your own.');
        }
        $this->command?->warn('──────────────────────────────────────────────────────────');
    }
}
