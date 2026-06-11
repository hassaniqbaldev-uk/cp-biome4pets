<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Models\PlanStep;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    protected array $planSteps = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Hold the nested steps aside; persisted via relations after create
        // (kept out of the core Plan mass-assignment).
        $this->planSteps = $data['steps'] ?? [];
        unset($data['steps']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->persistPlanSteps($this->planSteps);
        $this->syncSubscriptionIncludes();
    }

    /**
     * Rebuild plan_steps / plan_step_products from the raw `steps` form state.
     * Existing steps are removed first (the DB cascade clears their products),
     * then recreated in array order with position = index. Mirrors
     * persistPlanSteps() in EditReport.
     */
    protected function persistPlanSteps(array $steps): void
    {
        PlanStep::where('plan_id', $this->record->getKey())->delete();

        foreach (array_values($steps) as $stepIndex => $stepData) {
            $type = $stepData['type'] ?? 'product';

            $step = $this->record->steps()->create([
                'type' => $type,
                'step_title' => $stepData['step_title'] ?? '',
                'stage_label' => $stepData['stage_label'] ?? null,
                'body' => $type === 'prose' ? ($stepData['body'] ?? null) : null,
                'tip' => $type === 'prose' ? ($stepData['tip'] ?? null) : null,
                'position' => $stepIndex,
            ]);

            if ($type !== 'product') {
                continue;
            }

            foreach (array_values($stepData['products'] ?? []) as $productIndex => $productData) {
                if (empty($productData['catalog_product_id'])) {
                    continue;
                }

                $step->products()->create([
                    'catalog_product_id' => $productData['catalog_product_id'],
                    'duration' => $productData['duration'] ?? null,
                    'quantity' => $productData['quantity'] ?? null,
                    'dose' => $productData['dose'] ?? null,
                    'inclusion' => $productData['inclusion'] ?? 'included',
                    'position' => $productIndex,
                ]);
            }
        }
    }

    /**
     * Derive subscription_includes from the "included" products across the
     * plan's product steps (in order, de-duplicated). Keeps the catalogue as
     * the single source of truth for the names shown in the subscribe panel.
     */
    protected function syncSubscriptionIncludes(): void
    {
        $names = $this->record->steps()
            ->where('type', 'product')
            ->with('products.catalogProduct')
            ->get()
            ->flatMap(fn (PlanStep $step) => $step->products)
            ->filter(fn ($product) => $product->inclusion === 'included')
            ->map(fn ($product) => $product->catalogProduct?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->record->update(['subscription_includes' => $names]);
    }
}
