<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Services\OpenAiService;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Health-notes Part 2: generation + pet_snapshot read the dated log AS OF the
 * report date. Notes after the report date are excluded; notes on/before are
 * included; the frozen snapshot is immutable to later log edits; a new report
 * captures the extended history; empty history yields no notes line.
 */
class HealthNotesContextTest extends TestCase
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
    }

    private function petWithHistory(): Pet
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'owner@example.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Biscuit']);

        $pet->healthNotes()->create(['date' => '2026-01-10', 'weight_kg' => 7.2, 'note' => 'Started new kibble']);
        $pet->healthNotes()->create(['date' => '2026-03-02', 'weight_kg' => 7.6, 'note' => 'Stools firmer']);
        // After the report date below — must be excluded.
        $pet->healthNotes()->create(['date' => '2026-09-01', 'weight_kg' => 8.0, 'note' => 'Should be excluded']);

        return $pet;
    }

    public function test_notes_after_the_as_of_date_are_excluded_on_or_before_are_included(): void
    {
        $pet = $this->petWithHistory();

        $context = $pet->healthNotesForContext('2026-06-17');

        $this->assertStringContainsString('2026-01-10 · 7.20 kg · Started new kibble', $context);
        $this->assertStringContainsString('2026-03-02 · 7.60 kg · Stools firmer', $context);
        $this->assertStringNotContainsString('Should be excluded', $context);
        $this->assertStringNotContainsString('2026-09-01', $context);

        // A note exactly ON the as-of date is included (inclusive boundary).
        $pet->healthNotes()->create(['date' => '2026-06-17', 'note' => 'On the boundary']);
        $this->assertStringContainsString('2026-06-17 · On the boundary', $pet->healthNotesForContext('2026-06-17'));

        // With no as-of date, the entire history (including the future note) is returned.
        $this->assertStringContainsString('Should be excluded', $pet->healthNotesForContext());
    }

    public function test_snapshot_freezes_as_of_history_and_is_immutable_to_later_log_edits(): void
    {
        $pet = $this->petWithHistory();

        // Freeze a report as of the report date (Sept note excluded).
        $report = Report::create([
            'client_id' => $pet->client_id,
            'pet_id' => $pet->id,
            'sample_id' => 'SNAP-CTX',
            'report_date' => '2026-06-17',
            'status' => 'draft',
            'pet_snapshot' => Report::buildPetSnapshot($pet, '2026-06-17'),
        ]);

        $expected = "2026-01-10 · 7.20 kg · Started new kibble\n2026-03-02 · 7.60 kg · Stools firmer";
        $this->assertSame($expected, $report->fresh()->pet_snapshot['health_notes']);

        // Owner edits the log AFTER the report is generated: a new entry and a
        // backdated entry that falls within the original as-of window.
        $pet->healthNotes()->create(['date' => '2026-07-01', 'note' => 'Added after the report']);
        $pet->healthNotes()->create(['date' => '2026-02-01', 'note' => 'Backdated after the report']);

        // The frozen snapshot is unchanged — Phase 1's guarantee still holds.
        $this->assertSame($expected, $report->fresh()->pet_snapshot['health_notes']);

        // A NEW report generated later captures the extended history up to its date.
        $later = Report::buildPetSnapshot($pet->fresh(), '2026-07-15');
        $this->assertStringContainsString('2026-02-01 · Backdated after the report', $later['health_notes']);
        $this->assertStringContainsString('2026-07-01 · Added after the report', $later['health_notes']);
        // Still bounded by the new as-of (Sept note excluded).
        $this->assertStringNotContainsString('Should be excluded', $later['health_notes']);
    }

    public function test_generation_pet_context_receives_the_dated_history_and_the_prompt_includes_it(): void
    {
        $pet = $this->petWithHistory();

        $context = ReportGeneration::petContext($pet, '2026-06-17');
        $this->assertSame('Biscuit', $context['name']);
        $this->assertStringContainsString('2026-01-10 · 7.20 kg · Started new kibble', $context['health_notes']);
        $this->assertStringNotContainsString('Should be excluded', $context['health_notes']);

        // The dated history flows into the interpretations prompt under the
        // unchanged Phase 2 owner-reported framing (not a diagnosis).
        $prompt = (new OpenAiService())->buildInterpretationsPrompt(['Firmicutes' => 50], 2.1, $context);
        $this->assertStringContainsString('Owner-reported health notes for this pet', $prompt);
        $this->assertStringContainsString('2026-03-02 · 7.60 kg · Stools firmer', $prompt);
        $this->assertStringContainsString('do NOT diagnose from these', $prompt);
    }

    public function test_empty_history_yields_no_notes_line(): void
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o2@example.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'NoNotes']);

        $this->assertNull($pet->healthNotesForContext('2026-06-17'));

        $context = ReportGeneration::petContext($pet, '2026-06-17');
        $this->assertNull($context['health_notes']);

        // Phase 2 blank-handling: no owner-notes line in the prompt at all.
        $prompt = (new OpenAiService())->buildInterpretationsPrompt(['Firmicutes' => 50], 2.1, $context);
        $this->assertStringNotContainsString('Owner-reported health notes', $prompt);

        // And the snapshot freezes a null health_notes (no entries in range).
        $snapshot = Report::buildPetSnapshot($pet, '2026-06-17');
        $this->assertNull($snapshot['health_notes']);
    }
}
