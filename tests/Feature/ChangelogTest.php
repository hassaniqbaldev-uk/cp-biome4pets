<?php

namespace Tests\Feature;

use App\Filament\Pages\Changelog;
use App\Support\ChangelogReader;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The READ-ONLY Changelog viewer: it reads the repo's CHANGELOG.md and displays it
 * grouped by version (newest first), like the error-log viewer reads a file. No
 * add/edit/delete anywhere; staff-only (Admins + Super Admins). The old EDITABLE
 * changelog (table/resource/model/migration) must be fully gone.
 */
class ChangelogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
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
            'email' => $role.uniqid().'@e.com',
            'role' => $role,
            'password' => Hash::make('secret'),
        ]);
    }

    private const SAMPLE = <<<'MD'
        # Changelog

        Some preamble that must be ignored.

        ## [v2.0.0] - 2026-06-29

        ### Added
        - A shiny new thing
        - Another new thing

        ### Fixed
        - A bug squashed

        ## [v1.0.0] - 2026-01-01

        ### Improved
        - Made something nicer
        MD;

    // ── Parsing ──────────────────────────────────────────────────────────

    public function test_parse_reads_versions_newest_first_with_categories_and_entries(): void
    {
        $versions = ChangelogReader::parse(self::SAMPLE);

        // Two versions, file order (newest first) preserved.
        $this->assertCount(2, $versions);
        $this->assertSame('v2.0.0', $versions[0]['version']);
        $this->assertSame('2026-06-29', $versions[0]['date']);
        $this->assertSame('v1.0.0', $versions[1]['version']);

        // Categories + entries within the newest version.
        $this->assertSame('Added', $versions[0]['groups'][0]['category']);
        $this->assertSame(['A shiny new thing', 'Another new thing'], $versions[0]['groups'][0]['entries']);
        $this->assertSame('Fixed', $versions[0]['groups'][1]['category']);
        $this->assertSame(['A bug squashed'], $versions[0]['groups'][1]['entries']);

        // The preamble before the first version header is ignored.
        $this->assertNotContains('Some preamble that must be ignored.', array_column($versions, 'version'));
    }

    public function test_parse_strips_bold_markers_from_entries(): void
    {
        $versions = ChangelogReader::parse("## [v1] - 2026-01-01\n### Added\n- **Bold lead.** rest of text");

        $this->assertSame('Bold lead. rest of text', $versions[0]['groups'][0]['entries'][0]);
    }

    public function test_missing_or_malformed_file_yields_empty_not_an_error(): void
    {
        // Empty / whitespace / no version headers → empty array (friendly message path).
        $this->assertSame([], ChangelogReader::parse(''));
        $this->assertSame([], ChangelogReader::parse("   \n  \n"));
        $this->assertSame([], ChangelogReader::parse("# Just a title\n\nNo version headers here at all."));
    }

    public function test_real_changelog_file_parses_into_versions(): void
    {
        // The committed CHANGELOG.md parses into the full history, newest first.
        $this->assertTrue(ChangelogReader::exists());
        $versions = ChangelogReader::versions();

        $this->assertSame(
            ['v1.4.0', 'v1.3.0', 'v1.2.0', 'v1.1.0', 'v1.0.0'],
            array_column($versions, 'version'),
        );
        $this->assertSame('v1.4.0', $versions[0]['version']);
        $this->assertNotEmpty($versions[0]['groups']);
    }

    public function test_latest_version_is_the_changelog_top_entry(): void
    {
        $versions = ChangelogReader::versions();

        // The single source of truth: latestVersion() === the top entry's label.
        $this->assertSame($versions[0]['version'], ChangelogReader::latestVersion());
        $this->assertSame('v1.4.0', ChangelogReader::latestVersion());
    }

    public function test_latest_version_semantics_are_null_for_an_unparseable_file(): void
    {
        // latestVersion() is versions()[0]['version'] ?? null; an unparseable file
        // yields [] → null, which is what the footer's ?? 'v1.4.0' fallback relies on.
        $emptyVersions = ChangelogReader::parse('');
        $this->assertSame([], $emptyVersions);
        $this->assertNull($emptyVersions[0]['version'] ?? null);
    }

    public function test_footer_shows_the_changelog_version_not_the_stale_v1_0(): void
    {
        // The admin footer renders the version from the changelog's top entry, so it
        // reads v1.4.0 (matching the changelog) and never the old hardcoded v1.0.
        $html = view('filament.footer-version')->render();

        $this->assertStringContainsString(ChangelogReader::latestVersion().' - Biome4Pets Portal', $html);
        $this->assertStringContainsString('v1.4.0 - Biome4Pets Portal', $html);
        $this->assertStringNotContainsString('v1.0 - Biome4Pets Portal', $html);
    }

    // ── Access gating (Admins + Super Admins; staff-only) ────────────────

    public function test_admins_and_super_admins_can_access(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $this->assertTrue(Changelog::canAccess());

        $this->actingAs($this->user(User::ROLE_ADMIN));
        $this->assertTrue(Changelog::canAccess());
    }

    public function test_non_admin_is_forbidden(): void
    {
        // A user whose role is neither admin nor super admin is refused (staff-only).
        $this->actingAs($this->user('viewer'));

        $this->assertFalse(Changelog::canAccess());
        $this->get(Changelog::getUrl())->assertForbidden();
    }

    public function test_admin_can_load_the_page_and_sees_versions_newest_first(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        $html = $this->get(Changelog::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('v1.4.0', $html);
        $this->assertStringContainsString('v1.3.0', $html);
        // Newest version appears before the older one.
        $this->assertLessThan(strpos($html, 'v1.3.0'), strpos($html, 'v1.4.0'));
    }

    // ── The editable version is fully removed ────────────────────────────

    public function test_editable_changelog_is_completely_gone(): void
    {
        $this->assertFalse(class_exists(\App\Models\ChangelogEntry::class), 'ChangelogEntry model should be deleted');
        $this->assertFalse(class_exists(\App\Filament\Resources\ChangelogResource::class), 'ChangelogResource should be deleted');
        $this->assertFalse(class_exists(\App\Filament\Pages\ChangelogReadView::class), 'ChangelogReadView should be deleted');

        // No table, and no migration left to recreate it.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('changelog_entries'));
        $this->assertEmpty(glob(database_path('migrations/*create_changelog_entries_table*')));
    }
}
