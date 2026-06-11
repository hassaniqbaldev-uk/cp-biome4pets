<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Filament\Resources\ReportResource;
use App\Models\CatalogProduct;
use App\Models\ReportStep;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\LabResultParser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CreateReport extends CreateRecord
{
    protected static string $resource = ReportResource::class;

    protected array $catalogProductIds = [];

    protected array $planSteps = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Slug is derived from the related pet's name + sample id by the
        // Report model's "creating" hook, which also guarantees uniqueness.
        $data['status'] = $data['status'] ?? 'draft';

        // Use the stored path from the Process CSV action if available
        if (!empty($data['csv_stored_path'])) {
            $data['csv_path'] = $data['csv_stored_path'];
        }
        unset($data['csv_stored_path']);

        // Normalize csv_path if it's still an array
        if (is_array($data['csv_path'] ?? null)) {
            $data['csv_path'] = array_values($data['csv_path'])[0] ?? null;
        }

        // Extract catalog product IDs for syncing after create
        $this->catalogProductIds = $data['catalog_product_ids'] ?? [];
        unset($data['catalog_product_ids']);

        // Extract the phased plan steps; persisted via relations after create
        // (kept out of the core Report mass-assignment).
        $this->planSteps = $data['steps'] ?? [];
        unset($data['steps']);

        // Freeze the pet's identity + health notes as-now, alongside the lab/AI
        // and subscription snapshots. The Pet stays the living source; this is a
        // copy so later edits to the pet don't change an already-generated report.
        $data['pet_snapshot'] = \App\Models\Report::buildPetSnapshot(
            \App\Models\Pet::find($data['pet_id'] ?? null)
        );

        // If phylum_data or diversity_score are missing, re-parse the CSV
        $phylumEmpty = empty($data['phylum_data'] ?? null);
        $diversityEmpty = empty($data['diversity_score'] ?? null);

        if (($phylumEmpty || $diversityEmpty) && !empty($data['csv_path'])) {
            $filePath = Storage::disk('public')->path($data['csv_path']);

            if (file_exists($filePath)) {
                Log::info('CreateReport: Re-parsing CSV to populate missing fields');

                $csvParser = new CsvParserService();
                // Same parse blob as before (via the extracted helper).
                $results = (new LabResultParser($csvParser))->fromPath($filePath)['csv_data'];

                $data['phylum_data'] = $results['phylum_totals'];
                $data['diversity_score'] = $results['diversity_score'];
                $data['csv_data'] = $results;
                $data['species_richness'] = $results['species_richness'];
                $data['dysbiosis_score'] = $results['dysbiosis_score'];
                $data['microbiome_classification'] = $results['microbiome_classification'];

                // If no catalog products selected yet, auto-match from triggered rules
                if (empty($this->catalogProductIds)) {
                    $triggeredRules = $csvParser->evaluateProductRules(
                        $results['phylum_totals'],
                        $results['diversity_score'],
                    );

                    $this->catalogProductIds = CatalogProduct::active()
                        ->whereHas('triggerEntries', fn ($q) => $q->whereIn('trigger', $triggeredRules))
                        ->pluck('id')
                        ->all();
                }
            }
        }

        return $data;
    }

    /**
     * Phase 3c: every report attaches to a Test, created+linked atomically.
     *   - existing-test path: reuse the chosen report-less test.
     *   - new-CSV path: find-or-create the test by (pet_id + order_id==sample_id)
     *     and store the freshly parsed raw data on it.
     * Either way the raw lab data is mirrored onto the report's own columns
     * (dual-write) for now; the test is the source of truth. There is no path
     * that writes raw data only to the report.
     */
    protected function handleRecordCreation(array $data): Model
    {
        // Wizard-only fields (not Report columns).
        $existingTestId = $data['existing_test_id'] ?? null;
        unset($data['existing_test_id'], $data['test_source']);

        return DB::transaction(function () use ($data, $existingTestId) {
            $test = null;

            if (filled($existingTestId)) {
                $test = Test::find($existingTestId);
            } elseif (filled($data['pet_id'] ?? null) && filled($data['sample_id'] ?? null)) {
                $test = Test::syncRawForReport($data);
            }

            if ($test) {
                $data['test_id'] = $test->id;

                // Mirror the test's raw data + sample/date onto the report so the
                // report's own (still-present) columns match the source of truth.
                $data['sample_id'] = $test->sample_id ?? $data['sample_id'] ?? null;
                $data['report_date'] = $data['report_date'] ?? optional($test->report_date)->toDateString();
                foreach (['csv_path', 'csv_data', 'phylum_data', 'diversity_score',
                    'species_richness', 'dysbiosis_score', 'microbiome_classification'] as $field) {
                    $data[$field] = $test->{$field};
                }

                if ($test->status !== 'report_generated') {
                    $test->update(['status' => 'report_generated']);
                }
            }

            return static::getModel()::create($data);
        });
    }

    protected function afterCreate(): void
    {
        if (!empty($this->catalogProductIds)) {
            $syncData = [];
            foreach ($this->catalogProductIds as $position => $id) {
                $syncData[$id] = ['position' => $position];
            }
            $this->record->catalogProducts()->sync($syncData);

            Log::info('CreateReport afterCreate: Synced catalog products', [
                'count' => count($syncData),
            ]);
        }

        $this->persistPlanSteps($this->planSteps);
    }

    /**
     * Rebuild the report_steps / report_step_products relations from the raw
     * `steps` form state. Existing steps are removed first (the DB-level
     * cascade clears their products), then recreated in array order with
     * position = index, products likewise positioned by index.
     */
    protected function persistPlanSteps(array $steps): void
    {
        ReportStep::where('report_id', $this->record->getKey())->delete();

        foreach (array_values($steps) as $stepIndex => $stepData) {
            $type = $stepData['type'] ?? 'product';

            $step = $this->record->steps()->create([
                'title' => $stepData['title'] ?? '',
                'description' => $stepData['description'] ?? null,
                'type' => $type,
                'stage_label' => $stepData['stage_label'] ?? null,
                'body' => $type === 'prose' ? ($stepData['body'] ?? null) : null,
                'tip' => $type === 'prose' ? ($stepData['tip'] ?? null) : null,
                'position' => $stepIndex,
            ]);

            // Prose steps carry no products.
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
                    'how_it_helps' => $productData['how_it_helps'] ?? null,
                    'position' => $productIndex,
                ]);
            }
        }
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
