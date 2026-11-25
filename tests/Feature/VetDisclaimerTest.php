<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\Test;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The static "Important Veterinary Disclaimer" at the bottom of the Help and Contacts
 * section. View-only, no data/logic: it must render on BOTH the web report and the PDF,
 * for a first test AND a retest, always with the exact fixed wording.
 */
class VetDisclaimerTest extends TestCase
{
    private const HEADING = 'Important Veterinary Disclaimer';

    private const SNIPPET = 'Microbiome analysis is not a substitute for veterinary examination, diagnosis or treatment.';

    private const CLOSING = 'working alongside, not replacing, professional veterinary care.';

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
    }

    /** @return array{0: Report, 1: Report} [firstTest, retest] for the same pet. */
    private function makeReports(): array
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $mkTest = fn (): Test => Test::create([
            'client_id' => $client->id, 'pet_id' => $pet->id,
            'order_id' => 'O'.uniqid(), 'sample_id' => 'S'.uniqid(), 'report_date' => '2026-01-01',
        ]);

        $first = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $mkTest()->id, 'status' => 'published',
        ]);
        $retest = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $mkTest()->id, 'status' => 'published',
            'retest_of_report_id' => $first->id,
        ]);

        return [$first->fresh(), $retest->fresh()];
    }

    private function render(Report $report, string $view): string
    {
        return view($view, ['report' => $report->fresh()->load([
            'client', 'pet.client', 'test', 'plan', 'catalogProducts', 'steps.products.catalogProduct',
        ])])->render();
    }

    public function test_web_report_renders_the_disclaimer(): void
    {
        [$first, $retest] = $this->makeReports();

        foreach ([$first, $retest] as $report) {
            $html = $this->render($report, 'report.show');
            $this->assertStringContainsString(self::HEADING, $html);
            $this->assertStringContainsString(self::SNIPPET, $html);
            $this->assertStringContainsString(self::CLOSING, $html);
        }
    }

    public function test_pdf_report_renders_the_disclaimer(): void
    {
        [$first, $retest] = $this->makeReports();

        foreach ([$first, $retest] as $report) {
            $html = $this->render($report, 'report.pdf');
            $this->assertStringContainsString(self::HEADING, $html);
            $this->assertStringContainsString(self::SNIPPET, $html);
            $this->assertStringContainsString(self::CLOSING, $html);
        }
    }
}
