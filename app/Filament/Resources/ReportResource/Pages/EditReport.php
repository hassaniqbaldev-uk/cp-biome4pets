<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Filament\Resources\ReportResource;
use App\Models\CatalogProduct;
use App\Models\ReportStep;
use App\Models\Setting;
use App\Models\Test;
use App\Services\CsvParserService;
use App\Services\KlaviyoService;
use App\Services\LabResultParser;
use App\Services\OpenAiService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class EditReport extends EditRecord
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('publish')
                ->label('Publish Report')
                ->icon('heroicon-o-globe-alt')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Publish Report')
                ->modalDescription('This will make the report publicly accessible. Continue?')
                ->visible(fn () => $this->record->status === 'draft')
                ->action(function () {
                    $this->record->update(['status' => 'published']);
                    $this->fillForm();

                    $url = route('report.show', $this->record->slug);

                    Notification::make()
                        ->title('Report Published')
                        ->body("Shareable URL: {$url}")
                        ->success()
                        ->persistent()
                        ->send();
                }),
            Actions\Action::make('view_report')
                ->label('View Report')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->url(fn () => route('report.show', $this->record->slug))
                ->openUrlInNewTab()
                ->visible(fn () => $this->record->status === 'published'),
            Actions\Action::make('send_report')
                ->label('Send Report')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                // Sub-label / tooltip tells the admin the channel — or, when
                // blocked, exactly why the button is greyed out.
                ->tooltip(fn (): string => $this->sendReportBlockedReason() ?? 'Via Klaviyo')
                ->disabled(fn (): bool => $this->sendReportBlockedReason() !== null)
                ->requiresConfirmation()
                ->modalHeading('Send Report via Klaviyo')
                ->modalDescription(fn (): HtmlString => new HtmlString(
                    'Send a <strong>report_published</strong> event to <strong>'
                    . e($this->record->petClient?->email ?? '—')
                    . '</strong> via Klaviyo.<br><span style="color:#6b7280;">Last sent: '
                    . e($this->record->klaviyoLastSentSummary()) . '</span>'
                ))
                ->modalSubmitActionLabel('Send now')
                ->action(function () {
                    // Re-check the guards at click time — never call with a
                    // disabled integration or a missing/empty client email.
                    $reason = $this->sendReportBlockedReason();
                    if ($reason !== null) {
                        Notification::make()->title('Cannot send')->body($reason)->danger()->send();

                        return;
                    }

                    $report = $this->record;
                    $email = $report->petClient->email;

                    $result = app(KlaviyoService::class)->sendEvent('report_published', $email, [
                        'report_id' => $report->id,
                        'pet_name' => $report->pet?->name,
                        'report_url' => $report->report_url,
                        'report_date' => Carbon::parse($report->report_date)->format('F j, Y'),
                        'client_name' => $report->petClient?->name,
                    ]);

                    $report->recordKlaviyoSend(
                        $result['ok'],
                        $result['ok'] ? 'Report sent to Klaviyo' : $result['message'],
                    );
                    $this->fillForm();

                    Notification::make()
                        ->title($result['ok'] ? 'Report sent to Klaviyo' : 'Send failed')
                        ->body($result['ok'] ? 'Sent to ' . $email : $result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
            Actions\Action::make('copy_link')
                ->label('Copy Report Link')
                ->icon('heroicon-o-clipboard-document')
                ->color('gray')
                ->visible(fn () => $this->record->status === 'published')
                // Pure client-side action. alpineClickHandler() renders the JS as
                // x-on:click AND calls livewireClickHandlerEnabled(false) internally,
                // so Filament omits wire:click="mountAction('copy_link')" entirely —
                // no Livewire round-trip, no DOM re-render to interrupt the clipboard
                // logic. x-data="{}" gives Alpine a scope to evaluate the handler.
                ->extraAttributes(['x-data' => '{}'])
                ->alpineClickHandler(fn () => $this->copyLinkJs(route('report.show', $this->record->slug))),
            Actions\Action::make('parse_and_generate')
                ->label('Parse CSV & Generate AI Interpretations')
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->action(function () {
                    $record = $this->record;

                    if (empty($record->csv_path)) {
                        Notification::make()
                            ->title('No CSV file uploaded')
                            ->danger()
                            ->send();
                        return;
                    }

                    $filePath = Storage::disk('public')->path($record->csv_path);

                    if (!file_exists($filePath)) {
                        Notification::make()
                            ->title('CSV file not found')
                            ->body("Path: {$filePath}")
                            ->danger()
                            ->send();
                        return;
                    }

                    $csvParser = new CsvParserService();
                    // Same parse blob as before (via the extracted helper).
                    $results = (new LabResultParser($csvParser))->fromPath($filePath)['csv_data'];

                    // Build pet context so the AI copy can address the pet by
                    // name and tailor advice to this specific pet.
                    $pet = $record->pet;
                    // Part 2: notes history AS OF this report's date (report_date is
                    // proxied from the linked test; collected_at backs it up).
                    $asOf = $record->report_date ?? $record->test?->collected_at;
                    $petContext = $pet ? [
                        'name' => $pet->name,
                        'breed' => $pet->breed,
                        'sex' => $pet->sex,
                        'diet' => $pet->diet,
                        // Owner-reported notes ground the copy (dated history up to
                        // the report date). Framing in OpenAiService is unchanged.
                        'health_notes' => $pet->healthNotesForContext($asOf),
                    ] : [];

                    // Generate AI interpretations
                    $openAi = new OpenAiService();
                    $interpretations = $openAi->generateReportInterpretations(
                        $results['phylum_totals'],
                        $results['diversity_score'],
                        $petContext,
                    );

                    $allEmpty = collect($interpretations)->every(fn ($val) => empty($val));

                    if ($allEmpty) {
                        Notification::make()
                            ->title('AI interpretations returned empty')
                            ->body('Check your OpenAI API key and credits. See storage/logs/laravel.log for details.')
                            ->warning()
                            ->persistent()
                            ->send();
                    }

                    $record->update([
                        // Raw lab data is written to the Test below; the report
                        // stores only the AI copy, scores and the pet snapshot.
                        // Re-freeze the pet snapshot in lockstep with the AI/CSV
                        // snapshot so the frozen pet matches the regenerated copy
                        // (notes history as of the report date).
                        'pet_snapshot' => \App\Models\Report::buildPetSnapshot($pet, $asOf),
                        'ai_summary' => $interpretations['summary'],
                        'ai_bacteroidetes_interpretation' => $interpretations['bacteroidetes_interpretation'],
                        'ai_firmicutes_interpretation' => $interpretations['firmicutes_interpretation'],
                        'ai_fusobacteria_interpretation' => $interpretations['fusobacteria_interpretation'],
                        'ai_proteobacteria_interpretation' => $interpretations['proteobacteria_interpretation'],
                        'ai_diversity_interpretation' => $interpretations['diversity_interpretation'],
                        'vet_summary' => $interpretations['vet_summary'],
                        'goal' => $interpretations['goal'],
                        'recommended_actions' => $interpretations['recommended_actions'],
                        'score_gut_wall' => $interpretations['score_gut_wall'],
                        'score_skin_allergy' => $interpretations['score_skin_allergy'],
                        'score_behaviour_mood' => $interpretations['score_behaviour_mood'],
                        'score_gut_barrier' => $interpretations['score_gut_barrier'],
                        'score_gas_digestive' => $interpretations['score_gas_digestive'],
                        'score_stress_resilience' => $interpretations['score_stress_resilience'],
                    ]);

                    // Refresh the raw lab data on the Test (its sole home).
                    // Find-or-create by (pet_id + order_id == sample_id) and link
                    // if not yet linked. sample_id/report_date/csv_path resolve
                    // through the Report→Test proxy off the already-linked test.
                    $test = Test::syncRawForReport([
                        'pet_id' => $record->pet_id,
                        'client_id' => $record->client_id,
                        'sample_id' => $record->sample_id,
                        'report_date' => $record->report_date,
                        'csv_path' => $record->csv_path,
                        'csv_data' => $results,
                        'phylum_data' => $results['phylum_totals'],
                        'diversity_score' => $results['diversity_score'],
                        'species_richness' => $results['species_richness'],
                        'dysbiosis_score' => $results['dysbiosis_score'],
                        'microbiome_classification' => $results['microbiome_classification'],
                    ]);
                    if ($record->test_id !== $test->id) {
                        $record->update(['test_id' => $test->id]);
                    }

                    // Auto-select catalog products based on triggered rules
                    $triggeredRules = $csvParser->evaluateProductRules(
                        $results['phylum_totals'],
                        $results['diversity_score'],
                    );

                    $matchedIds = CatalogProduct::active()
                        ->whereHas('triggerEntries', fn ($q) => $q->whereIn('trigger', $triggeredRules))
                        ->pluck('id')
                        ->all();

                    $syncData = [];
                    foreach ($matchedIds as $position => $id) {
                        $syncData[$id] = ['position' => $position];
                    }
                    $record->catalogProducts()->sync($syncData);

                    $record->refresh();
                    $this->fillForm();

                    if (!$allEmpty) {
                        Notification::make()
                            ->title('CSV parsed and AI interpretations generated')
                            ->success()
                            ->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Why the "Send Report" action is blocked, or null when it may fire.
     * Covers all three guards: integration off, no API key, no client email.
     */
    private function sendReportBlockedReason(): ?string
    {
        $enabled = filter_var(Setting::get(Setting::KLAVIYO_ENABLED), FILTER_VALIDATE_BOOLEAN);

        if (! $enabled || blank(Setting::get(Setting::KLAVIYO_API_KEY))) {
            return 'Klaviyo integration is off — enable it in Email & Integrations';
        }

        if (blank($this->record->petClient?->email)) {
            return 'No client email on this report';
        }

        return null;
    }

    /**
     * Build the client-side clipboard handler for the "Copy Report Link" button.
     *
     * Runs entirely in the browser: uses the async Clipboard API in secure
     * contexts, and falls back to a hidden-textarea + execCommand('copy') when
     * navigator.clipboard is unavailable (non-HTTPS / non-localhost). The URL is
     * json_encoded so a quote/apostrophe in the slug can't break the JS string.
     *
     * Notifications are raised via Filament v3's global FilamentNotification JS
     * class (its .send() dispatches the 'notificationSent' event the panel
     * listens for). The success toast fires ONLY inside the try, after the copy
     * actually succeeds; any failure routes to the catch and a danger toast — so
     * we never claim success when the copy did not happen.
     */
    protected function copyLinkJs(string $url): string
    {
        $json = json_encode($url, JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<JS
            (async () => {
                const url = {$json};
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(url);
                    } else {
                        const ta = document.createElement('textarea');
                        ta.value = url;
                        ta.style.position = 'fixed';
                        ta.style.opacity = '0';
                        document.body.appendChild(ta);
                        ta.focus(); ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                    }
                    new window.FilamentNotification()
                        .title('Report link copied to clipboard')
                        .success()
                        .send();
                } catch (e) {
                    new window.FilamentNotification()
                        .title('Could not copy automatically')
                        .body('Link: ' + url)
                        .danger()
                        .send();
                }
            })()
            JS;
    }

    protected array $catalogProductIds = [];

    protected array $planSteps = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['catalog_product_ids'] = $this->record->catalogProducts->pluck('id')->all();

        // Hydrate the phased plan steps (ordered by position) with their
        // products (ordered by position) into the nested `steps` form key.
        // plan_id / plan_intro / subscription_snapshot are plain columns and are
        // already present in $data (Filament fills them from the record).
        $data['steps'] = $this->record->steps()->with('products')->get()
            ->map(fn (ReportStep $step) => [
                'type' => $step->type,
                'title' => $step->title,
                'description' => $step->description,
                'stage_label' => $step->stage_label,
                'body' => $step->body,
                'tip' => $step->tip,
                'products' => $step->products->map(fn ($product) => [
                    'catalog_product_id' => $product->catalog_product_id,
                    'duration' => $product->duration,
                    'quantity' => $product->quantity,
                    'dose' => $product->dose,
                    'inclusion' => $product->inclusion,
                    'how_it_helps' => $product->how_it_helps,
                ])->all(),
            ])->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // sample_id lives on the Test now; resolve it through the Report→Test
        // proxy so the slug stays stable regardless of the (informational) form
        // field. The raw lab columns no longer exist, so there is nothing to
        // guard against an edit-save nulling — the proxy is their sole source.
        $petName = \App\Models\Pet::find($data['pet_id'] ?? null)?->name;
        $data['slug'] = Str::slug($petName . '-' . $this->record->sample_id);

        $this->catalogProductIds = $data['catalog_product_ids'] ?? [];
        unset($data['catalog_product_ids']);

        // Hold the raw plan steps aside; persisted via relations in afterSave
        // (kept out of the core Report mass-assignment).
        $this->planSteps = $data['steps'] ?? [];
        unset($data['steps']);

        return $data;
    }

    protected function afterSave(): void
    {
        $syncData = [];
        foreach ($this->catalogProductIds as $position => $id) {
            $syncData[$id] = ['position' => $position];
        }
        $this->record->catalogProducts()->sync($syncData);

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
}
