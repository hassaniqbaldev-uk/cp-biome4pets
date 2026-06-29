<?php

namespace Tests\Feature;

use App\Filament\Pages\LogViewer;
use App\Models\User;
use App\Support\LogReader;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Super-Admin Error Logs page. URL-level gating (Admins get 403, like the
 * other System tools), newest-first rendering of error entries, the errors-only
 * filter, and graceful handling when there is no readable log.
 */
class LogViewerTest extends TestCase
{
    private array $created = [];

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

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        $this->created = [];

        parent::tearDown();
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

    private function writeLog(string $contents): string
    {
        $name = 'laravel-test-'.uniqid().'.log';
        $path = LogReader::logDir().'/'.$name;
        file_put_contents($path, $contents);
        $this->created[] = $path;

        return $name;
    }

    private const SAMPLE = <<<'LOG'
        [2026-06-19 12:00:00] production.INFO: Routine info noise here {"x":1}
        [2026-06-19 12:30:00] production.ERROR: Something exploded in production {"exception":"[object] (RuntimeException ...)"}
        #0 /var/www/index.php(1): boom()
        LOG;

    public function test_super_admin_can_access_the_page(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $this->get(LogViewer::getUrl())->assertOk();
    }

    public function test_admin_is_forbidden(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN));

        $this->get(LogViewer::getUrl())->assertForbidden();
    }

    public function test_canAccess_is_super_admin_only(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $this->assertTrue(LogViewer::canAccess());

        $this->actingAs($this->user(User::ROLE_ADMIN));
        $this->assertFalse(LogViewer::canAccess());
    }

    public function test_renders_error_entries_and_hides_info_when_errors_only(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $name = $this->writeLog(self::SAMPLE);

        Livewire::test(LogViewer::class)
            ->set('file', $name)
            ->assertSet('errorsOnly', true)
            ->assertSee('Something exploded in production')
            ->assertDontSee('Routine info noise here');
    }

    public function test_shows_info_entries_when_errors_only_is_off(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $name = $this->writeLog(self::SAMPLE);

        Livewire::test(LogViewer::class)
            ->set('file', $name)
            ->set('errorsOnly', false)
            ->assertSee('Routine info noise here')
            ->assertSee('Something exploded in production');
    }

    public function test_unreadable_selected_file_shows_friendly_message_not_an_error(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));

        // A file that is not a whitelisted log → resolve() returns null.
        Livewire::test(LogViewer::class)
            ->set('file', 'does-not-exist.log')
            ->assertOk()
            ->assertSee('can’t be read')
            ->assertSet('errorsOnly', true);
    }

    public function test_entries_method_is_read_only_and_orders_newest_first(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $name = $this->writeLog(self::SAMPLE);
        $sizeBefore = filesize(LogReader::logDir().'/'.$name);

        $page = new LogViewer;
        $page->file = $name;
        $page->errorsOnly = false;
        $entries = $page->entries();

        // Newest first (the ERROR at 12:30 precedes the INFO at 12:00).
        $this->assertSame('ERROR', $entries[0]['level']);
        $this->assertSame('INFO', $entries[1]['level']);
        // Reading never mutates the file.
        $this->assertSame($sizeBefore, filesize(LogReader::logDir().'/'.$name));
    }
}
