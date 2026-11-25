<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\Report;
use App\Support\ReportGeneration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The retest-only plan override (step 1a): when a report is a retest whose frozen
 * retest_comparison fires the FMT retest rule, ReportGeneration::applyRetestPlanOverride
 * forces plan_id to the existing "Rebuild & Renew" plan (resolved by key), sets
 * recommendation_reason to 'retest_fmt', and detaches the CSV-trigger products — fully
 * replacing the standard selection. When the rule does not fire, or the report is a first
 * test, nothing is touched. Local empty DB; data built directly (no domain factories exist).
 */
class RetestPlanOverrideTest extends TestCase
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

        // The override resolves this plan by KEY; seed just this row (not the full seeder).
        Plan::create(['key' => 'rebuild-renew', 'name' => 'Rebuild & Renew', 'enabled' => true]);
    }

    private function comparison(float $origDiv, int $origRich, float $retDiv, int $retRich): array
    {
        return [
            'overview' => [
                'original' => ['diversity' => $origDiv, 'species_richness' => $origRich],
                'retest' => ['diversity' => $retDiv, 'species_richness' => $retRich],
            ],
            'taxa' => [],
        ];
    }

    /** A retest report linked to an original, with a crafted frozen comparison + a
     *  pre-existing CSV-trigger product attached (the thing the override must detach). */
    private function makeRetest(array $comparison, ?int $existingPlanId = null): Report
    {
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);

        $original = Report::create(['client_id' => $client->id, 'pet_id' => $pet->id, 'status' => 'published']);

        $retest = Report::create([
            'client_id' => $client->id,
            'pet_id' => $pet->id,
            'status' => 'draft',
            'retest_of_report_id' => $original->id,
            'retest_comparison' => $comparison,
            'plan_id' => $existingPlanId,
        ]);

        // A trigger-matched product as the standard selection would have attached.
        $product = CatalogProduct::create(['name' => 'PetBiome AMR '.uniqid(), 'is_active' => true]);
        $retest->catalogProducts()->attach($product->id, ['position' => 0]);

        return $retest->fresh();
    }

    public function test_case1_both_conditions_met_forces_rebuild_renew_and_detaches_products(): void
    {
        $rebuildRenew = Plan::where('key', 'rebuild-renew')->firstOrFail();
        $retest = $this->makeRetest($this->comparison(3.16, 463, 2.02, 215));

        $override = ReportGeneration::applyRetestPlanOverride($retest);

        $this->assertNotNull($override);
        $this->assertSame('retest_fmt', $override['reason']);

        $fresh = $retest->fresh();
        $this->assertSame($rebuildRenew->id, $fresh->plan_id);
        $this->assertSame('retest_fmt', $fresh->recommendation_reason['code']);
        // CSV-trigger products are gone — plan/products now come solely from the forced plan.
        $this->assertSame(0, $fresh->catalogProducts()->count());
    }

    public function test_case2_diversity_only_does_not_override(): void
    {
        // Diversity drops 0.6, richness only 20%.
        $retest = $this->makeRetest($this->comparison(3.16, 500, 2.56, 400));

        $override = ReportGeneration::applyRetestPlanOverride($retest);

        $this->assertNull($override);
        $fresh = $retest->fresh();
        $this->assertNull($fresh->plan_id);                      // untouched
        $this->assertNull($fresh->recommendation_reason);        // untouched
        $this->assertSame(1, $fresh->catalogProducts()->count()); // still attached
    }

    public function test_case3_neither_condition_met_does_not_override(): void
    {
        $retest = $this->makeRetest($this->comparison(3.16, 463, 3.10, 450));

        $this->assertNull(ReportGeneration::applyRetestPlanOverride($retest));
        $this->assertSame(1, $retest->fresh()->catalogProducts()->count());
    }

    public function test_case4_first_test_is_never_considered(): void
    {
        // Same winning numbers as case 1, but NOT a retest (no retest_of_report_id).
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Rex']);
        $report = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'status' => 'draft',
            // A comparison array present but the report is a first test — must be ignored.
            'retest_comparison' => $this->comparison(3.16, 463, 2.02, 215),
        ]);
        $product = CatalogProduct::create(['name' => 'PetBiome AMR '.uniqid(), 'is_active' => true]);
        $report->catalogProducts()->attach($product->id, ['position' => 0]);

        $this->assertNull(ReportGeneration::retestPlanOverride($report->fresh()));
        $this->assertNull(ReportGeneration::applyRetestPlanOverride($report->fresh()));

        $fresh = $report->fresh();
        $this->assertNull($fresh->plan_id);
        $this->assertNull($fresh->recommendation_reason);
        $this->assertSame(1, $fresh->catalogProducts()->count());
    }

    public function test_case5_zero_richness_and_missing_comparison_do_not_fire_or_error(): void
    {
        $zero = $this->makeRetest($this->comparison(3.16, 0, 2.02, 0));
        $this->assertNull(ReportGeneration::applyRetestPlanOverride($zero));
        $this->assertSame(1, $zero->fresh()->catalogProducts()->count());

        // retest_comparison null (not yet frozen): no fire, no error.
        $client = Client::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'Teddy']);
        $original = Report::create(['client_id' => $client->id, 'pet_id' => $pet->id, 'status' => 'published']);
        $noComparison = Report::create([
            'client_id' => $client->id, 'pet_id' => $pet->id, 'status' => 'draft',
            'retest_of_report_id' => $original->id, 'retest_comparison' => null,
        ]);

        $this->assertNull(ReportGeneration::applyRetestPlanOverride($noComparison->fresh()));
    }
}
