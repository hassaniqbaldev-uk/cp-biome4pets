<?php

namespace App\Filament\Resources\PlanResource\Concerns;

use App\Models\CatalogProduct;
use App\Models\PlanVariant;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * Shared plan-variant form handling for CreatePlan / EditPlan: pre-save validation,
 * persistence (delete-and-recreate, mirroring steps/triggers), and edit hydration.
 * Variants are inert until defined, so a plan with no variant rows is unchanged.
 */
trait ManagesPlanVariants
{
    /** Raw `variants` form rows, held aside in the mutate hook for afterSave/afterCreate. */
    protected array $planVariants = [];

    /**
     * Validate the variant rows BEFORE the plan is written, so a violation halts the
     * save with a friendly notification instead of a raw DB error or a silent no-op:
     *   - a condition may appear at most once per plan (the unique constraint), and
     *   - a swap's "from" product must actually be used in the plan's steps (else the
     *     swap resolves against nothing and would never fire).
     */
    protected function guardVariants(array $data): void
    {
        $variants = $data['variants'] ?? [];

        // Duplicate condition on the same plan.
        $conditions = array_filter(array_map(fn (array $v): ?string => $v['condition'] ?? null, $variants));
        $duplicate = array_diff_assoc($conditions, array_unique($conditions));
        if ($duplicate !== []) {
            $key = reset($duplicate);
            $label = PlanVariant::CONDITION_LABELS[$key] ?? $key;
            $this->failVariants("Each condition can have only one variant per plan — remove the duplicate \"{$label}\" variant.");
        }

        // Swap "from" must be a product used in this plan's steps.
        $stepProductIds = collect($data['steps'] ?? [])
            ->flatMap(fn (array $step): array => collect($step['products'] ?? [])->pluck('catalog_product_id')->all())
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        foreach ($variants as $variant) {
            foreach ($variant['product_overrides'] ?? [] as $override) {
                $from = $override['from_catalog_product_id'] ?? null;
                if (blank($from)) {
                    continue;
                }
                if (! in_array((int) $from, $stepProductIds, true)) {
                    $name = CatalogProduct::find($from)?->name ?? "#{$from}";
                    $this->failVariants("The product swap \"{$name}\" isn't used in this plan's steps, so it would do nothing. Add it to a step, or remove the swap.");
                }
            }
        }
    }

    /** Notify + halt the save (used by guardVariants on a validation failure). */
    private function failVariants(string $message): void
    {
        Notification::make()->title('Check the condition variants')->body($message)->danger()->send();

        throw new Halt;
    }

    /**
     * Rebuild plan_variants / plan_variant_product_overrides from the `variants`
     * form state (delete + recreate; the DB cascade clears the overrides of removed
     * variants). Blank-condition rows and incomplete swaps are skipped.
     */
    protected function persistVariants(array $rows): void
    {
        $this->record->variants()->delete();

        foreach (array_values($rows) as $row) {
            $condition = $row['condition'] ?? null;
            if (blank($condition)) {
                continue;
            }

            $variant = $this->record->variants()->create([
                'condition' => $condition,
                'enabled' => (bool) ($row['enabled'] ?? true),
                // Blank overrides store null so the resolver inherits the base plan.
                'subscription_url' => $row['subscription_url'] ?: null,
                'subscription_price' => $row['subscription_price'] ?: null,
                'subscription_full_price' => $row['subscription_full_price'] ?: null,
                'subscription_billing_note' => $row['subscription_billing_note'] ?: null,
                'subscription_saving_label' => $row['subscription_saving_label'] ?: null,
            ]);

            foreach (array_values($row['product_overrides'] ?? []) as $override) {
                if (empty($override['from_catalog_product_id']) || empty($override['to_catalog_product_id'])) {
                    continue;
                }

                $variant->productOverrides()->create([
                    'from_catalog_product_id' => $override['from_catalog_product_id'],
                    'to_catalog_product_id' => $override['to_catalog_product_id'],
                    'dose' => $override['dose'] ?: null,
                    'quantity' => $override['quantity'] ?: null,
                    'duration' => $override['duration'] ?: null,
                ]);
            }
        }
    }

    /**
     * The `variants` form rows for edit hydration (variants + their overrides).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function variantFormRows(): array
    {
        return $this->record->variants()->with('productOverrides')->get()
            ->map(fn (PlanVariant $variant): array => [
                'condition' => $variant->condition,
                'enabled' => $variant->enabled,
                'subscription_url' => $variant->subscription_url,
                'subscription_price' => $variant->subscription_price,
                'subscription_full_price' => $variant->subscription_full_price,
                'subscription_billing_note' => $variant->subscription_billing_note,
                'subscription_saving_label' => $variant->subscription_saving_label,
                'product_overrides' => $variant->productOverrides->map(fn ($o): array => [
                    'from_catalog_product_id' => $o->from_catalog_product_id,
                    'to_catalog_product_id' => $o->to_catalog_product_id,
                    'dose' => $o->dose,
                    'quantity' => $o->quantity,
                    'duration' => $o->duration,
                ])->all(),
            ])->all();
    }
}
