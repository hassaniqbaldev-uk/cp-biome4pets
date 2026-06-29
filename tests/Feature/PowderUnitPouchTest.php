<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\Pet;
use App\Models\Report;
use App\Models\ReportStep;
use App\Models\ReportStepProduct;
use App\Models\Test;
use Database\Seeders\CatalogProductSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The powder unit reads "pouch", not "tub", in product quantity text. The unit
 * lives in the free-text `quantity` field on plan_step_products (templates) and
 * report_step_products (per-report copy). Only powders (PetBiome AMR / Prebiotic /
 * Antimicrobic) ever said "tub"; Gut Renew's "course" and plain quantities must be
 * left alone. Covers both the seeder output (fresh installs) and the data
 * migration that fixes rows already stored.
 */
class PowderUnitPouchTest extends TestCase
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
    }

    public function test_seeded_plan_powder_products_use_pouch_not_tub(): void
    {
        (new CatalogProductSeeder)->run();
        (new PlanSeeder)->run();

        $quantities = DB::table('plan_step_products')->pluck('quantity')->filter()->all();

        // No seeded quantity says "tub" any more...
        foreach ($quantities as $q) {
            $this->assertStringNotContainsStringIgnoringCase('tub', $q, "Unexpected 'tub' in seeded quantity: {$q}");
        }

        // ...the powders now say "pouch"...
        $this->assertNotEmpty(array_filter($quantities, fn ($q) => str_contains($q, 'one pouch per month')));

        // ...and Gut Renew's "course" unit is untouched.
        $this->assertNotEmpty(array_filter($quantities, fn ($q) => str_contains($q, 'one course per month')));
    }

    public function test_migration_renames_legacy_tub_quantities_and_leaves_other_units(): void
    {
        $migration = require database_path('migrations/2026_06_30_000000_rename_tub_to_pouch_in_product_quantities.php');

        (new CatalogProductSeeder)->run();
        (new PlanSeeder)->run();

        // Simulate legacy rows that still carry "tub" (and one "course" that must NOT change).
        $amr = CatalogProduct::where('name', 'PetBiome AMR')->firstOrFail();
        $renew = CatalogProduct::where('name', 'Gut Renew')->firstOrFail();

        $planStepId = DB::table('plan_step_products')->value('plan_step_id');
        $tubPlanRow = DB::table('plan_step_products')->insertGetId([
            'plan_step_id' => $planStepId, 'catalog_product_id' => $amr->id,
            'quantity' => '3 (one tub per month)', 'inclusion' => 'included', 'position' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $coursePlanRow = DB::table('plan_step_products')->insertGetId([
            'plan_step_id' => $planStepId, 'catalog_product_id' => $renew->id,
            'quantity' => '3 (one course per month)', 'inclusion' => 'included', 'position' => 98,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A legacy report-side row too (the per-report copy).
        $client = Client::create(['name' => 'O', 'email' => 'o@e.com']);
        $pet = Pet::create(['client_id' => $client->id, 'name' => 'B']);
        $test = Test::create(['client_id' => $client->id, 'pet_id' => $pet->id, 'order_id' => 'O1', 'sample_id' => 'S1', 'report_date' => '2026-06-15']);
        $report = Report::create(['client_id' => $client->id, 'pet_id' => $pet->id, 'test_id' => $test->id, 'status' => 'published']);
        $step = ReportStep::create(['report_id' => $report->id, 'title' => 'Step 3', 'type' => 'product', 'position' => 0]);
        $tubReportRow = ReportStepProduct::create([
            'report_step_id' => $step->id, 'catalog_product_id' => $amr->id,
            'quantity' => '4 (one tub per month)', 'inclusion' => 'included', 'position' => 0,
        ]);

        $migration->up();

        // tub → pouch on both tables...
        $this->assertSame('3 (one pouch per month)', DB::table('plan_step_products')->where('id', $tubPlanRow)->value('quantity'));
        $this->assertSame('4 (one pouch per month)', $tubReportRow->fresh()->quantity);

        // ...and the "course" unit is left exactly as it was.
        $this->assertSame('3 (one course per month)', DB::table('plan_step_products')->where('id', $coursePlanRow)->value('quantity'));

        // No "tub" survives anywhere.
        $this->assertSame(0, DB::table('plan_step_products')->where('quantity', 'like', '%tub%')->count());
        $this->assertSame(0, DB::table('report_step_products')->where('quantity', 'like', '%tub%')->count());

        // down() is reversible.
        $migration->down();
        $this->assertSame('3 (one tub per month)', DB::table('plan_step_products')->where('id', $tubPlanRow)->value('quantity'));
        $this->assertSame('3 (one course per month)', DB::table('plan_step_products')->where('id', $coursePlanRow)->value('quantity'));
    }
}
