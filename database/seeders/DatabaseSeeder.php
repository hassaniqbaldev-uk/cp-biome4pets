<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Single staging admin account (no generic/test user).
        $this->call(UserSeeder::class);

        // Restore Setting boilerplate (e.g. Signs of Stability) that migrate:fresh
        // wipes; idempotent and never overwrites an admin-customised value.
        $this->call(SettingsSeeder::class);

        $this->call(ProductRuleSeeder::class);
        // CatalogProductSeeder must run before PlanSeeder: plans reference the
        // six live shop products it ensures exist.
        $this->call(CatalogProductSeeder::class);
        $this->call(PlanSeeder::class);
    }
}
