<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Test;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Lab CSVs are customer PII. They now live on the PRIVATE 'local' disk (not the
 * public web root), are downloadable only via an authenticated admin route as an
 * attachment, are deleted with their Test, and uploads are MIME-validated server
 * side so a spoofed non-CSV can't be stored.
 */
class CsvSecurityTest extends TestCase
{
    private const RULE = ['file' => ['mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel']];

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
        Storage::fake('local');
    }

    private function makeTestWithCsv(?string $csvPath = 'csv/lab.csv'): Test
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        if ($csvPath) {
            Storage::disk('local')->put($csvPath, "Phylum,%_hits\nFirmicutes,50\nBacteroidetes,25\n");
        }

        return Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'KMS734', 'sample_id' => 'KMS734', 'csv_path' => $csvPath,
        ]);
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'a'.uniqid().'@e.com', 'role' => 'admin', 'password' => Hash::make('x')]);
    }

    public function test_csv_is_stored_on_the_private_disk_not_the_public_web_root(): void
    {
        Storage::fake('public');
        $this->makeTestWithCsv();

        // On the private disk…
        $this->assertTrue(Storage::disk('local')->exists('csv/lab.csv'));
        // …and NOT on the public (web-served) disk.
        $this->assertFalse(Storage::disk('public')->exists('csv/lab.csv'));
    }

    public function test_download_requires_authentication(): void
    {
        $test = $this->makeTestWithCsv();

        // Unauthenticated → forbidden (never public).
        $this->get(route('admin.tests.csv', $test))->assertForbidden();
    }

    public function test_authenticated_admin_downloads_csv_as_attachment(): void
    {
        $test = $this->makeTestWithCsv();
        $this->actingAs($this->admin());

        $res = $this->get(route('admin.tests.csv', $test));

        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('Firmicutes', $res->streamedContent());
    }

    public function test_download_404s_when_the_test_has_no_csv(): void
    {
        $test = $this->makeTestWithCsv(csvPath: null);
        $this->actingAs($this->admin());

        $this->get(route('admin.tests.csv', $test))->assertNotFound();
    }

    public function test_soft_deleting_a_test_keeps_its_csv_and_force_delete_removes_it(): void
    {
        // Soft delete (the normal admin action) is recoverable, so the CSV must
        // survive — the file is only wiped on a permanent force-delete.
        $test = $this->makeTestWithCsv();
        $this->assertTrue(Storage::disk('local')->exists('csv/lab.csv'));

        $test->delete();
        $this->assertTrue(Storage::disk('local')->exists('csv/lab.csv'), 'soft delete must keep the CSV');

        $test->forceDelete();
        $this->assertFalse(Storage::disk('local')->exists('csv/lab.csv'), 'force delete wipes the CSV');
    }

    public function test_upload_validation_rejects_spoofed_non_csv_content(): void
    {
        // Each is named *.csv with a benign client MIME — the spoof. The mimetypes
        // rule re-sniffs the real bytes with finfo (exactly as Laravel does for a
        // real upload), so the disguise doesn't hold.
        $csv = $this->realUpload('lab.csv', "Phylum,%_hits\nFirmicutes,50\nBacteroidetes,25\n");
        $html = $this->realUpload('evil.csv', '<html><body><script>alert(document.cookie)</script></body></html>');
        $svg = $this->realUpload('evil.csv', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->assertTrue(Validator::make(['file' => $csv], self::RULE)->passes(), 'genuine CSV should pass');
        $this->assertTrue(Validator::make(['file' => $html], self::RULE)->fails(), 'HTML disguised as .csv must be rejected');
        $this->assertTrue(Validator::make(['file' => $svg], self::RULE)->fails(), 'SVG disguised as .csv must be rejected');
    }

    /**
     * A real UploadedFile (test mode) backed by a temp file — its getMimeType()
     * content-sniffs via finfo, unlike UploadedFile::fake() which guesses from the
     * extension. This is what the validator actually sees in production.
     */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csvsec');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
