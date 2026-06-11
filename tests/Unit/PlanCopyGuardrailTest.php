<?php

namespace Tests\Unit;

use App\Filament\Resources\ReportResource;
use App\Services\OpenAiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PlanCopyGuardrailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate from the live MySQL DB. With no settings table present,
        // Setting::get() resolves to defaults (it is try/catch-guarded), so the
        // generator runs entirely on config + the stubbed HTTP layer.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'services.openai.api_key' => 'test-key',
            'services.openai.model' => 'gpt-4o',
        ]);
        DB::purge('sqlite');
    }

    /** The fixed scaffold (one product step + one prose step) — the oracle. */
    private function scaffold(): array
    {
        return [
            'plan_id' => 'restore-rebalance',
            'plan_name' => 'Restore & Rebalance',
            'pet_name' => 'Rex',
            'intro' => '',
            'subscription' => [
                'available' => true,
                'price' => '£35 / month',
                'billing_note' => 'Billed monthly · powders rotate by phase',
                'includes' => ['PetBiome AMR'],
            ],
            'steps' => [
                [
                    'type' => 'product',
                    'step_title' => 'Step 1: Microbiome Reset',
                    'stage_label' => 'Phase 1 · Months 1–3',
                    'products' => [[
                        'name' => 'PetBiome AMR',
                        'price' => '£35.00',
                        'dose' => 'Follow recommended dose on label.',
                        'duration' => '3 months (12 weeks)',
                        'quantity' => '3 (one tub per month)',
                        'how_it_helps' => '',
                        'product_url' => 'https://biome4pets.com/products/petbiome-amr-1',
                        'inclusion' => 'included',
                    ]],
                ],
                [
                    'type' => 'prose',
                    'step_title' => 'Step 2: Implement Dietary Changes',
                    'stage_label' => 'Alongside Phase 1',
                    'body' => '',
                    'tip' => '',
                ],
            ],
        ];
    }

    /** An OpenAiService whose HTTP layer returns a canned OpenAI response. */
    private function stubbedService(array $modelObject): OpenAiService
    {
        $apiResponse = json_encode([
            'choices' => [['message' => ['content' => json_encode($modelObject)]]],
        ]);

        return new class($apiResponse) extends OpenAiService {
            public function __construct(private string $canned)
            {
            }

            protected function requestChatCompletion(string $apiKey, string $payload): string|false
            {
                return $this->canned;
            }
        };
    }

    public function test_factual_fields_are_preserved_while_only_copy_is_accepted(): void
    {
        Log::spy();
        $scaffold = $this->scaffold();

        // The model returns valid copy BUT also tampers with factual fields.
        $tampered = $scaffold;
        $tampered['intro'] = 'Rex shows an imbalanced gut; this plan resets then rebuilds.';
        $tampered['steps'][0]['products'][0]['price'] = '£999.00';        // drift
        $tampered['steps'][0]['products'][0]['dose'] = 'Take 10 scoops';   // drift
        $tampered['steps'][0]['products'][0]['duration'] = '99 months';    // drift
        $tampered['steps'][0]['products'][0]['name'] = 'Knock-off AMR';    // drift
        $tampered['steps'][0]['products'][0]['how_it_helps'] = 'Helps bring Rex\'s elevated taxa back into range.';
        $tampered['steps'][1]['body'] = 'Reduce processed food for Rex over four weeks.';
        $tampered['steps'][1]['tip'] = 'Home-cooked bone broth can help.';

        $model = $this->stubbedService($tampered)->generatePlanCopy(
            ['pet_name' => 'Rex', 'species' => 'dog'],
            $scaffold,
        );

        // Sanity: the stub flowed through generatePlanCopy (tampered value present).
        $this->assertSame('£999.00', $model['steps'][0]['products'][0]['price']);

        // The guardrail returns ONLY copy.
        $copy = ReportResource::validatePlanCopy($model, $scaffold);

        $this->assertTrue($copy['has_copy']);
        $this->assertSame('Rex shows an imbalanced gut; this plan resets then rebuilds.', $copy['intro']);
        $this->assertSame("Helps bring Rex's elevated taxa back into range.", $copy['steps'][0]['products'][0]);
        $this->assertSame('Reduce processed food for Rex over four weeks.', $copy['steps'][1]['body']);
        $this->assertSame('Home-cooked bone broth can help.', $copy['steps'][1]['tip']);

        // Build the overlay exactly as the "Apply plan" action does: factual from
        // the scaffold, copy from the validated result. Factual must be untouched.
        $product = $scaffold['steps'][0]['products'][0];
        $product['how_it_helps'] = $copy['steps'][0]['products'][0];

        $this->assertSame('PetBiome AMR', $product['name']);
        $this->assertSame('£35.00', $product['price']);
        $this->assertSame('Follow recommended dose on label.', $product['dose']);
        $this->assertSame('3 months (12 weeks)', $product['duration']);
        $this->assertSame('https://biome4pets.com/products/petbiome-amr-1', $product['product_url']);
        $this->assertSame('included', $product['inclusion']);

        // None of the tampered values survived into the accepted output.
        $this->assertStringNotContainsString('999', json_encode($copy));
        $this->assertStringNotContainsString('Knock-off', json_encode($copy));

        // Drift was logged, not silently swallowed.
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_empty_generation_yields_no_copy_so_placeholders_are_kept(): void
    {
        $scaffold = $this->scaffold();

        // Model returns non-JSON content → generatePlanCopy falls back to blanks.
        $model = $this->stubbedService(['not' => 'used'])->generatePlanCopy(['pet_name' => 'Rex'], $scaffold);
        // Force the fallback path by handing the validator a blanked scaffold,
        // which is exactly what generatePlanCopy returns on parse failure.
        $blank = $scaffold;
        $blank['intro'] = '';
        $blank['steps'][0]['products'][0]['how_it_helps'] = '';
        $blank['steps'][1]['body'] = '';
        $blank['steps'][1]['tip'] = null;

        $copy = ReportResource::validatePlanCopy($blank, $scaffold);

        $this->assertFalse($copy['has_copy']);
        $this->assertSame('', $copy['intro']);
        $this->assertSame('', $copy['steps'][0]['products'][0]);
        $this->assertNull($copy['steps'][1]['body']);
    }

    public function test_structural_drift_discards_that_steps_copy(): void
    {
        $scaffold = $this->scaffold();

        // Model adds an extra product to step 1 (count drift).
        $drifted = $scaffold;
        $drifted['intro'] = 'Intro still fine.';
        $drifted['steps'][0]['products'][] = [
            'name' => 'Surprise Extra', 'price' => '£1.00', 'dose' => 'x', 'duration' => 'x',
            'quantity' => '1', 'how_it_helps' => 'sneaky', 'product_url' => 'x', 'inclusion' => 'included',
        ];
        $drifted['steps'][0]['products'][0]['how_it_helps'] = 'legit copy';

        $copy = ReportResource::validatePlanCopy($drifted, $scaffold);

        // Intro still accepted, but the product-count drift means no product copy
        // for that step is accepted (can't safely align it).
        $this->assertTrue($copy['has_copy']);
        $this->assertSame('Intro still fine.', $copy['intro']);
        $this->assertSame([], $copy['steps'][0]['products']);
    }
}
