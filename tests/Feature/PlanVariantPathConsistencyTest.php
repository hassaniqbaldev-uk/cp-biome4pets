<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Consistency lock for plan-variant wiring (Stage 3). It FAILS LOUDLY if a plan can
 * be materialised into a report WITHOUT going through the single variant-aware seam
 * (PlanInstantiation) — the failure mode being a flagged pet stranded on the base
 * AMR with the base checkout link.
 *
 * Reality check on "the three generation paths": only ONE of them actually
 * instantiates a plan into report_steps/report_step_products + subscription_snapshot
 * — the "Apply plan" action (ReportResource). The other two write no plan products:
 *   - createReportFromTest sets plan_id only; the plan is applied later in the editor
 *     (it explicitly defers the subscription_snapshot to apply-time), and
 *   - regenerateReport only re-runs the AI copy and explicitly does NOT touch the
 *     plan, products or snapshot.
 * So variant resolution belongs at the one instantiation seam, and this test locks
 * BOTH directions: the seam goes through PlanInstantiation (which always resolves a
 * variant), and the other two paths never start instantiating products/snapshot
 * behind its back. If either invariant breaks, this test fails.
 */
class PlanVariantPathConsistencyTest extends TestCase
{
    private function source(string $relative): string
    {
        return file_get_contents(base_path($relative));
    }

    public function test_apply_plan_instantiates_through_the_variant_aware_seam(): void
    {
        $res = $this->source('app/Filament/Resources/ReportResource.php');

        // The Apply-plan action builds steps + snapshot via the single seam…
        $this->assertStringContainsString('PlanInstantiation::build($plan, $pet, $copy)', $res);
        $this->assertStringContainsString("\$set('subscription_snapshot', \$instantiation['subscription_snapshot'])", $res);

        // …and no longer rebuilds the snapshot inline (which would bypass resolution).
        $this->assertStringNotContainsString("subscription_snapshot', [", $res);

        // …and carries the combined-gap review flag.
        $this->assertStringContainsString('ReportGeneration::withVariantReviewFlag(', $res);
    }

    public function test_the_seam_always_resolves_a_variant_and_freezes_url_variant_and_includes(): void
    {
        $seam = $this->source('app/Support/PlanInstantiation.php');

        // Resolution runs every time.
        $this->assertStringContainsString('PlanVariantResolver::resolve(', $seam);
        // The snapshot freezes the resolved checkout url + the resolved condition key.
        $this->assertStringContainsString("'url' => \$resolution['checkout_url']", $seam);
        $this->assertStringContainsString("'variant' => \$resolution['condition_key']", $seam);
        // Includes are recomputed with swaps applied (not read straight off the plan).
        $this->assertStringContainsString('private static function includes(', $seam);
        $this->assertStringContainsString('$byFromName', $seam);
    }

    public function test_the_other_two_generation_paths_do_not_instantiate_products_or_snapshot(): void
    {
        $gen = $this->source('app/Support/ReportGeneration.php');

        // createReportFromTest / regenerateReport must NOT WRITE report step products
        // or a subscription snapshot — if they ever need to, they must route through
        // PlanInstantiation (and this assertion must be consciously updated). This is
        // what stops a flagged pet being silently stranded on base products by a path
        // that quietly started instantiating. (A comment may mention the snapshot; the
        // guard targets the array-key WRITE form, not the prose.)
        $this->assertStringNotContainsString("'subscription_snapshot' =>", $gen);
        $this->assertStringNotContainsString('steps()->create', $gen);
    }
}
