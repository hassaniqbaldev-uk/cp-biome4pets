<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Models\PlanStep;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected array $planSteps = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Hydrate plan_steps (ordered by position) with their products (ordered
        // by position) into the nested `steps` form key.
        $data['steps'] = $this->record->steps()->with('products')->get()
            ->map(fn (PlanStep $step) => [
                'type' => $step->type,
                'step_title' => $step->step_title,
                'stage_label' => $step->stage_label,
                'body' => $step->body,
                'tip' => $step->tip,
                'products' => $step->products->map(fn ($product) => [
                    'catalog_product_id' => $product->catalog_product_id,
                    'duration' => $product->duration,
                    'quantity' => $product->quantity,
                    'dose' => $product->dose,
                    'inclusion' => $product->inclusion,
                ])->all(),
            ])->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Hold the raw plan steps aside; persisted via relations in afterSave
        // (kept out of the core Plan mass-assignment).
        $this->planSteps = $data['steps'] ?? [];
        unset($data['steps']);

        return $data;
    }

    protected function afterSave(): void
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
