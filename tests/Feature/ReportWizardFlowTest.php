<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Filament\Resources\ReportResource\Pages\EditReport;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase C — report-builder wizard flow/UX:
 *   #4 CSV upload auto-parses the deterministic metrics (no AI/paid work).
 *   #6 the redundant "Parse CSV & Generate AI Interpretations" action is gone
 *      from the report edit header (Send Report stays).
 *   #3 arriving on edit straight from create (?created=1) flags the done-state.
 *   #5 the persistent Klaviyo "last sent" block is no longer in the form.
 */
class ReportWizardFlowTest extends TestCase
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
        config(['services.openai.api_key' => '', 'services.openai.model' => 'gpt-4o']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function seedReport(): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o' . uniqid() . '@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);
        $test = Test::create([
            'pet_id' => $pet->id, 'client_id' => $client->id,
            'order_id' => 'ORD-FLOW', 'sample_id' => 'ORD-FLOW',
            'report_date' => '2026-05-12',
        ]);

        return Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id,
            'test_id' => $test->id, 'status' => 'draft',
        ]);
    }

    private const CSV = <<<CSV
        Kingdom,Phylum,Class,Order,Family,Genus,Species,num_hits,%_hits
        Bacteria,Fusobacteria,Fusobacteriia,Fusobacteriales,Fusobacteriaceae,Fusobacterium,Fusobacterium_mortiferum(NR_1),6524,30.0
        Bacteria,Bacteroidetes,Bacteroidia,Bacteroidales,Prevotellaceae,Prevotella,Prevotella_copri(AB064923),5754,25.0
        Bacteria,Firmicutes,Clostridia,Clostridiales,Clostridiaceae_1,Clostridium,Clostridium_perfringens(CP000246),1803,30.0
        Bacteria,Proteobacteria,Gammaproteobacteria,Enterobacterales,Enterobacteriaceae,Escherichia,Escherichia_coli(NR_2),900,15.0
        CSV;

    /** #4: parsing the uploaded CSV fills the deterministic metric fields. */
    public function test_uploaded_csv_is_parsed_into_metric_fields(): void
    {
        // CSVs are PII → private 'local' disk (parseUploadedCsv reads from there).
        Storage::fake('local');
        Storage::disk('local')->put('csv/sample.csv', self::CSV);

        $parsed = ReportResource::parseUploadedCsv('csv/sample.csv');

        $this->assertNotSame([], $parsed, 'CSV did not parse');
        $this->assertNotEmpty($parsed['phylum_data']);
        $this->assertArrayHasKey('Firmicutes', $parsed['phylum_data']);
        $this->assertIsNumeric($parsed['diversity_score']);
        $this->assertNotEmpty($parsed['microbiome_classification']);
        // Pure parse: no AI/interpretation keys are produced here.
        $this->assertArrayNotHasKey('ai_summary', $parsed);
    }

    /** #4: an empty / missing upload yields nothing (no crash, no partial state). */
    public function test_parse_returns_empty_for_no_file(): void
    {
        $this->assertSame([], ReportResource::parseUploadedCsv(null));
        $this->assertSame([], ReportResource::parseUploadedCsv('csv/does-not-exist.csv'));
    }

    /** #6: the redundant parse/generate header action is gone; Send Report remains. */
    public function test_report_edit_header_no_longer_has_parse_and_generate(): void
    {
        $report = $this->seedReport();

        Livewire::test(EditReport::class, ['record' => $report->getKey()])
            ->assertActionDoesNotExist('parse_and_generate')
            ->assertActionExists('send_report')
            ->assertActionExists('publish');
    }

    /** #3: ?created=1 puts the edit page in the post-create "done" state. */
    public function test_created_flag_marks_the_done_state(): void
    {
        $report = $this->seedReport();

        Livewire::withQueryParams(['created' => '1'])
            ->test(EditReport::class, ['record' => $report->getKey()])
            ->assertSet('justCreated', true);
    }

    /** #3: a plain edit (no ?created) is not in the done-state. */
    public function test_plain_edit_is_not_in_done_state(): void
    {
        $report = $this->seedReport();

        Livewire::test(EditReport::class, ['record' => $report->getKey()])
            ->assertSet('justCreated', false);
    }

    /** #5: the persistent "Last sent to Klaviyo" block is no longer in the form. */
    public function test_klaviyo_block_is_not_persistently_shown(): void
    {
        $report = $this->seedReport();

        Livewire::test(EditReport::class, ['record' => $report->getKey()])
            ->assertDontSee('Last sent to Klaviyo');
    }
}
