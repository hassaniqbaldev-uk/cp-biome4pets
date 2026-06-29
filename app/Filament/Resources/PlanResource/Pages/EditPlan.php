<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Filament\Resources\PlanResource\Concerns\ManagesPlanVariants;
use App\Models\Plan;
use App\Models\PlanStep;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    use ManagesPlanVariants;

    protected static string $resource = PlanResource::class;

    protected array $planSteps = [];

    protected array $planTriggerConditions = [];

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

        // Hydrate the editable trigger conditions (ordered by position).
        $data['trigger_conditions'] = $this->record->triggerConditions
            ->map(fn ($condition) => ['required_triggers' => $condition->required_triggers ?? []])
            ->all();

        // Hydrate the condition variants + their product swaps.
        $data['variants'] = $this->variantFormRows();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Validate variants BEFORE the plan is written (dup condition / no-op swap).
        $this->guardVariants($data);

        // Hold the raw plan steps + trigger conditions + variants aside; persisted via
        // relations in afterSave (kept out of the core Plan mass-assignment).
        $this->planSteps = $data['steps'] ?? [];
        unset($data['steps']);

        $this->planTriggerConditions = $data['trigger_conditions'] ?? [];
        unset($data['trigger_conditions']);

        $this->planVariants = $data['variants'] ?? [];
        unset($data['variants']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->persistPlanSteps($this->planSteps);
        $this->persistTriggerConditions($this->planTriggerConditions);
        $this->persistVariants($this->planVariants);
        $this->enforceSingleFallback();
        $this->syncSubscriptionIncludes();
    }

    /**
     * Rebuild plan_trigger_conditions from the `trigger_conditions` form state
     * (delete + recreate in order). Empty rows are dropped — an empty required
     * set would trivially match everything in the matcher.
     */
    protected function persistTriggerConditions(array $rows): void
    {
        $this->record->triggerConditions()->delete();

        foreach (array_values($rows) as $position => $row) {
            $triggers = array_values(array_filter((array) ($row['required_triggers'] ?? [])));

            if ($triggers === []) {
                continue;
            }

            $this->record->triggerConditions()->create([
                'position' => $position,
                'required_triggers' => $triggers,
            ]);
        }
    }

    /**
     * Guard rail: at most one fallback plan. When this plan was saved as the
     * fallback, clear the flag on every other plan so the matcher has a single,
     * unambiguous "no triggers fired" target.
     */
    protected function enforceSingleFallback(): void
    {
        if (! $this->record->is_fallback) {
            return;
        }

        Plan::where('id', '!=', $this->record->getKey())
            ->where('is_fallback', true)
            ->update(['is_fallback' => false]);
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
