<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportResource;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * REGRESSION PIN for the plan recommendation. These outcomes are the exact
 * behaviour of the (Phase 1) hardcoded recommendPlanId() and MUST remain
 * byte-for-byte identical after Phase 2 makes the mapping data-driven. The setup
 * runs the real PlanSeeder, so the same test proves: (before) the hardcoded
 * logic, and (after) that the seeded config reproduces it exactly.
 */
class PlanRecommendationRegressionTest extends TestCase
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
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);

        (new PlanSeeder())->run();
    }

    /** @return array<string, array{0: list<string>, 1: string}> */
    public static function cases(): array
    {
        return [
            'FMT alone → rebuild-renew' => [['FMT'], 'rebuild-renew'],
            'FMT beats AMR+Prebiotic (precedence)' => [['FMT', 'AMR', 'Prebiotic'], 'rebuild-renew'],
            'FMT beats AMR+Antimicrobic (precedence)' => [['FMT', 'AMR', 'Antimicrobic'], 'rebuild-renew'],
            'AMR + Antimicrobic → reset-recover' => [['AMR', 'Antimicrobic'], 'reset-recover'],
            'AMR + Prebiotic → restore-rebalance' => [['AMR', 'Prebiotic'], 'restore-rebalance'],
            'AMR + Antimicrobic + Prebiotic → reset-recover (order)' => [['AMR', 'Antimicrobic', 'Prebiotic'], 'reset-recover'],
            'no triggers → maintain-protect (fallback)' => [[], 'maintain-protect'],
        ];
    }

    #[DataProvider('cases')]
    public function test_recommendation_matches_expected(array $triggers, string $expectedKey): void
    {
        $expectedId = Plan::where('key', $expectedKey)->value('id');
        $this->assertNotNull($expectedId, "seeded plan {$expectedKey} missing");

        $this->assertSame($expectedId, ReportResource::recommendPlanId($triggers));
    }

    public function test_fired_but_no_rule_matches_returns_null(): void
    {
        // A trigger fires but no plan's full condition is satisfied → no
        // recommendation (NOT the fallback, which is the no-triggers case).
        $this->assertNull(ReportResource::recommendPlanId(['Prebiotic']));
        $this->assertNull(ReportResource::recommendPlanId(['Antimicrobic']));
        $this->assertNull(ReportResource::recommendPlanId(['AMR']));
        $this->assertNull(ReportResource::recommendPlanId(['SomeUnknownTrigger']));
    }
}
