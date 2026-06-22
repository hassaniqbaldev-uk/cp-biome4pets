<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Filament\Resources\ReportResource;
use App\Models\Pet;
use App\Models\ReportStep;
use App\Models\Setting;
use App\Services\KlaviyoService;
use App\Support\PaidActionLimiter;
use App\Support\Utm;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class EditReport extends EditRecord
{
    protected static string $resource = ReportResource::class;

    /**
     * True only when this edit page was reached straight from creating the report
     * (CreateReport redirects here with ?created=1). The wizard form uses it to
     * show a "Report created" confirmation banner with next-step actions instead
     * of silently dropping the user back on step 1.
     */
    public bool $justCreated = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->justCreated = request()->boolean('created');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Phase 3: acknowledge the advisory quality flag. Clears needs_review
            // (so it leaves the dashboard/list/nav surfaces) but KEEPS review_flags
            // for the record and stamps who/when. Purely advisory — it does not
            // touch the draft/published flow.
            Actions\Action::make('mark_reviewed')
                ->label('Mark as reviewed')
                ->icon('heroicon-o-flag')
                ->color('warning')
                ->visible(fn (): bool => (bool) $this->record->needs_review)
                ->requiresConfirmation()
                ->modalHeading('Mark this report as reviewed')
                ->modalDescription('Clears the "needs review" flag. The recorded issues are kept for the audit trail.')
                ->action(function (): void {
                    $this->record->update([
                        'needs_review' => false,
                        'reviewed_at' => now(),
                        'reviewed_by' => auth()->id(),
                    ]);
                    $this->fillForm();
                    Notification::make()->title('Marked as reviewed')->success()->send();
                }),
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

                    $url = route('report.show', $this->record->public_token);

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
                ->url(fn () => route('report.show', $this->record->public_token))
                ->openUrlInNewTab()
                ->visible(fn () => $this->record->status === 'published'),
            Actions\Action::make('send_report')
                ->label('Send Report')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                // Sub-label / tooltip tells the admin the channel and the Klaviyo
                // send status (the relocated "not sent" indicator) — or, when
                // blocked, exactly why the button is greyed out.
                ->tooltip(fn (): string => $this->sendReportBlockedReason()
                    ?? ('Via Klaviyo · last sent: '.($this->record->klaviyoLastSentSummary() ?? 'not yet sent')))
                ->disabled(fn (): bool => $this->sendReportBlockedReason() !== null)
                ->requiresConfirmation()
                ->modalHeading('Send Report via Klaviyo')
                ->modalDescription(fn (): HtmlString => new HtmlString(
                    'Send a <strong>report_published</strong> event to <strong>'
                    .e($this->record->petClient?->email ?? '—')
                    .'</strong> via Klaviyo.<br><span style="color:#6b7280;">Last sent: '
                    .e($this->record->klaviyoLastSentSummary()).'</span>'
                ))
                ->modalSubmitActionLabel('Send now')
                ->action(function () {
                    // L2: Klaviyo send — cap per admin.
                    if (PaidActionLimiter::exceeded('klaviyo-send', 10)) {
                        return;
                    }

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
                        // Klaviyo delivers this as an email link → tag for attribution.
                        'report_url' => Utm::klaviyo($report->report_url, 'report_published', 'email_button'),
                        // report_date is proxied from the linked Test; for a report
                        // with no test (legacy/orphan) it is null — send null rather
                        // than Carbon::parse(null) which would silently emit today.
                        'report_date' => $report->report_date
                            ? Carbon::parse($report->report_date)->format('F j, Y')
                            : null,
                        'client_name' => $report->petClient?->name,
                    ]);

                    $report->recordKlaviyoSend(
                        $result['ok'],
                        $result['ok'] ? 'Report sent to Klaviyo' : $result['message'],
                    );
                    $this->fillForm();

                    Notification::make()
                        ->title($result['ok'] ? 'Report sent to Klaviyo' : 'Send failed')
                        ->body($result['ok'] ? 'Sent to '.$email : $result['message'])
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
                // The copied link is shared with the customer (e.g. pasted into a
                // message), so tag it as a shareable report link for attribution.
                ->alpineClickHandler(fn () => $this->copyLinkJs(
                    Utm::report(route('report.show', $this->record->public_token), 'report_share', 'copy_link')
                )),
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
        // The edit page reuses the create Wizard. Its step 1 reads sample_id /
        // report_date / raw lab fields and the test-source selector from form
        // state — but those columns were dropped from `reports` (they live on the
        // linked Test now), so attributesToArray() (what Filament fills from)
        // omits them and the getAttribute proxy never fires for array fills.
        // Re-hydrate them from the linked Test so the wizard opens on the right
        // step with the test preselected and its fields populated.
        if ($this->record->test_id) {
            $data = array_merge($data, ReportResource::testFormState($this->record->test_id));
            $data['test_source'] = 'existing';
            $data['existing_test_id'] = $this->record->test_id;
        }

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
        $petName = Pet::find($data['pet_id'] ?? null)?->name;
        $data['slug'] = Str::slug($petName.'-'.$this->record->sample_id);

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
