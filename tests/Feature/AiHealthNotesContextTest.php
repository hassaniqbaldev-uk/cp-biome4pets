<?php

namespace Tests\Feature;

use App\Services\OpenAiService;
use App\Support\PetFindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2: the pet's owner-reported health_notes are fed into BOTH AI flows —
 * the interpretations prompt and the plan-copy findings payload — as additional
 * grounding context. When notes are blank, no empty notes line/key appears.
 */
class AiHealthNotesContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate on in-memory sqlite; settings table so Setting::get resolves.
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

        Schema::create('settings', function ($table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    private function prompt(array $petContext): string
    {
        return (new OpenAiService())->buildInterpretationsPrompt(
            ['Bacteroidetes' => 40, 'Firmicutes' => 35, 'Fusobacteria' => 15, 'Proteobacteria' => 10],
            3.2,
            $petContext,
        );
    }

    public function test_interpretations_prompt_includes_health_notes_when_present(): void
    {
        $notes = 'Occasional loose stools; itchy skin in summer.';

        $prompt = $this->prompt([
            'name' => 'Rex',
            'breed' => 'Labrador',
            'health_notes' => $notes,
        ]);

        // The notes are present, labelled as owner-reported context.
        $this->assertStringContainsString('Owner-reported health notes for this pet', $prompt);
        $this->assertStringContainsString($notes, $prompt);
        // The not-a-diagnosis qualifier travels with the notes.
        $this->assertStringContainsString('do NOT diagnose from these', $prompt);

        // It sits within the pet-context portion (before the phylum data), and the
        // existing pet details + safety rules are all still intact.
        $this->assertStringContainsString('Pet details: Name: Rex. Breed: Labrador.', $prompt);
        $this->assertStringContainsString('Do NOT use em dashes', $prompt);
        $this->assertStringContainsString('Return ONLY the JSON object, no markdown or extra text.', $prompt);
        $this->assertTrue(
            strpos($prompt, 'Owner-reported health notes') < strpos($prompt, 'Phylum percentages:'),
            'notes should appear in the pet-context portion, before the phylum data',
        );
    }

    public function test_interpretations_prompt_omits_notes_line_when_blank(): void
    {
        $withName = $this->prompt(['name' => 'Rex', 'breed' => 'Labrador']);
        $withBlank = $this->prompt(['name' => 'Rex', 'breed' => 'Labrador', 'health_notes' => '   ']);

        // No label at all when notes are missing or whitespace-only.
        $this->assertStringNotContainsString('Owner-reported health notes', $withName);
        $this->assertStringNotContainsString('Owner-reported health notes', $withBlank);

        // And a blank/whitespace notes value yields the exact same prompt as no key.
        $this->assertSame($withName, $withBlank);
    }

    public function test_plan_findings_payload_includes_notes_when_present_and_omits_when_blank(): void
    {
        $with = PetFindings::build([
            'pet_name' => 'Rex',
            'health_notes' => 'Sensitive stomach after kibble change.',
            'diversity_score' => 3.2,
        ]);

        $this->assertArrayHasKey('owner_reported_health_notes', $with);
        $this->assertSame('Sensitive stomach after kibble change.', $with['owner_reported_health_notes']);

        // Blank / absent → key omitted entirely (no empty value in the payload).
        $blank = PetFindings::build(['pet_name' => 'Rex', 'health_notes' => '   ', 'diversity_score' => 3.2]);
        $absent = PetFindings::build(['pet_name' => 'Rex', 'diversity_score' => 3.2]);

        $this->assertArrayNotHasKey('owner_reported_health_notes', $blank);
        $this->assertArrayNotHasKey('owner_reported_health_notes', $absent);
    }
}
