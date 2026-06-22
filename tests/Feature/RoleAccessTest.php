<?php

namespace Tests\Feature;

use App\Filament\Pages\ReportAnIssue;
use App\Filament\Pages\Settings;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ReportResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two-tier admin roles. Super Admin ONLY: sensitive Settings + Users management.
 * Admin AND Super Admin: operations (Clients/Pets/Tests/Reports/Plans/Catalog) +
 * Report an Issue. Gating is URL-level (403), not just nav hiding. Plus the
 * seeded Super Admin has no source-committed password.
 */
class RoleAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role . uniqid() . '@e.com',
            'role' => $role,
            'password' => Hash::make('secret'),
        ]);
    }

    public function test_isSuperAdmin_helper(): void
    {
        $this->assertTrue($this->user(User::ROLE_SUPER_ADMIN)->isSuperAdmin());
        $this->assertFalse($this->user(User::ROLE_ADMIN)->isSuperAdmin());
        // Default role from the migration is 'admin'.
        $u = User::create(['name' => 'X', 'email' => 'x' . uniqid() . '@e.com', 'password' => Hash::make('p')]);
        $this->assertSame(User::ROLE_ADMIN, $u->fresh()->role);
        $this->assertFalse($u->isSuperAdmin());
    }

    public function test_isAdmin_helper_covers_both_admin_roles(): void
    {
        // Super Admin is a superset of Admin.
        $this->assertTrue($this->user(User::ROLE_SUPER_ADMIN)->isAdmin());
        $this->assertTrue($this->user(User::ROLE_ADMIN)->isAdmin());
        // A non-staff role (the future client role) is NOT admin-level.
        $this->assertFalse((new User(['role' => 'client']))->isAdmin());
    }

    public function test_super_admin_can_access_the_sensitive_pages(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $this->get(Settings::getUrl())->assertOk();
        $this->get(ReportAnIssue::getUrl())->assertOk();
        $this->get(UserResource::getUrl('index'))->assertOk();
    }

    public function test_admin_is_forbidden_from_sensitive_pages_at_the_URL_level(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        // Sensitive = Settings + Users management → Super Admin ONLY. 403 (not 404,
        // not a redirect) — direct URL access blocked, not just nav hiding.
        $this->get(Settings::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
        // (Report an Issue is NOT sensitive — Admins can reach it; see the
        // core-resources test below.)
    }

    public function test_users_management_is_super_admin_only_at_every_route(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        // Every UserResource route is URL-blocked for an Admin (403), not merely
        // hidden from the nav — an Admin can't see, reach, or act on Users at all.
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
        $this->assertFalse(UserResource::canAccess());
        $this->assertFalse(UserResource::shouldRegisterNavigation());
    }

    public function test_super_admin_can_access_core_resources(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $this->get(ClientResource::getUrl('index'))->assertOk();
        $this->get(ReportResource::getUrl('index'))->assertOk();
        $this->get(\App\Filament\Resources\PlanResource::getUrl('index'))->assertOk();
    }

    public function test_admin_can_access_core_resources_but_not_the_gated_pages(): void
    {
        // Separate method per role: switching users mid-test trips
        // AuthenticateSession (a real protection), which is a test artifact, not
        // an authz issue. One user per request cycle mirrors production.
        $this->actingAs($this->user(User::ROLE_ADMIN));

        // Core resources + Report an Issue: open to Admins.
        $this->get(ClientResource::getUrl('index'))->assertOk();
        $this->get(ReportResource::getUrl('index'))->assertOk();
        $this->get(\App\Filament\Resources\PlanResource::getUrl('index'))->assertOk();
        $this->get(ReportAnIssue::getUrl())->assertOk();

        // Sensitive pages: blocked at the URL level (403).
        $this->get(Settings::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    public function test_seeder_creates_super_admin_with_no_committed_password(): void
    {
        (new UserSeeder())->run();

        $admin = User::where('email', 'admin@biome4pets.com')->first();
        $this->assertNotNull($admin);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->role);

        // The old hard-coded password must NOT authenticate (no committed secret).
        $this->assertFalse(Hash::check('Admin@Biome2026!', $admin->password));
    }

    public function test_seeder_does_not_reset_an_existing_admins_password(): void
    {
        User::create([
            'name' => 'Existing', 'email' => 'admin@biome4pets.com',
            'role' => User::ROLE_ADMIN, 'password' => Hash::make('my-real-password'),
        ]);

        (new UserSeeder())->run();

        $admin = User::where('email', 'admin@biome4pets.com')->first();
        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->role);          // role ensured
        $this->assertTrue(Hash::check('my-real-password', $admin->password)); // password preserved
    }
}
