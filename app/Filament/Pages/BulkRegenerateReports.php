<?php

namespace App\Filament\Pages;

use App\Models\BulkRegenerateRun;
use App\Models\Report;
use App\Support\ReportGeneration;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

/**
 * Bulk Regenerate Reports — Super-Admin-only tool to re-run AI generation on
 * existing reports so they pick up generation fixes (e.g. the band-determinism /
 * band_contradiction fix). REGENERATE ONLY — it never sends anything.
 *
 * Heavy guardrails: it runs OpenAI at real cost and OVERWRITES stored AI content.
 * Flow: FILTER the table (date / status / needs-review) → TICK the reports to
 * regenerate (or select-all) → "Regenerate selected reports" → explicit CONFIRM
 * (count + cost + irreversibility) → process IN-PORTAL in small chunks with a live
 * progress bar. Selection is INCLUSIVE (ticking includes), the familiar admin
 * table pattern — the SELECTED reports are the batch.
 *
 * EXECUTION: fully self-contained, NO queue, NO cron, NO worker. The admin's
 * browser auto-continues through the batch via Livewire polling: each poll is a
 * separate request that regenerates up to CHUNK_SIZE reports, then the next poll
 * fires the next chunk, until done. Each chunk commits immediately + persists to
 * bulk_regenerate_runs, so partial completion is safe and a closed tab is
 * resumable (the dashboard surfaces interrupted/completed runs).
 */
class BulkRegenerateReports extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    /**
     * Reports regenerated per chunk (per poll request). Conservative: each report
     * is an OpenAI call of a few seconds, so 3 keeps every request well under
     * shared-host PHP max_execution_time with no worker/cron involved.
     */
    public const CHUNK_SIZE = 3;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Bulk Regenerate Reports';

    protected static ?string $title = 'Bulk Regenerate Reports';

    protected static ?string $navigationGroup = 'System';

    // Just after Settings (10) and Users (15).
    protected static ?int $navigationSort = 16;

    protected static string $view = 'filament.pages.bulk-regenerate-reports';

    // ── Live in-portal run state (drives the chunked auto-continue) ──
    /** True while a run is in progress (the view polls processChunk while true). */
    public bool $running = false;

    /** Remaining report ids still to process this run (FIFO; chunk takes the head). */
    public array $pendingIds = [];

    public int $total = 0;

    public int $succeeded = 0;

    public int $failed = 0;

    /** How many regenerated reports came back flagged needs_review. */
    public int $needsReviewCount = 0;

    /** True once a run has finished, so the result summary stays visible. */
    public bool $finished = false;

    /** The persisted bulk_regenerate_runs row id for the active/resumed run. */
    public ?int $runId = null;

    /**
     * Sensitive tool (paid OpenAI calls + destructive overwrite): Super Admins
     * ONLY. canAccess() is the security gate (Filament 403s on direct URL access
     * for Admins); shouldRegisterNavigation() also hides the nav item.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        // Resume an interrupted run: ?resume={id} (the dashboard "Resume" button).
        // Loads the REMAINING ids from the persisted run and continues chunked
        // processing from where it stopped — completed reports are NOT redone.
        if ($resumeId = request()->integer('resume')) {
            $this->resumeRun($resumeId);
        }
    }

    /**
     * The selectable table: the same useful columns as the reports list, with
     * native row checkboxes + a header select-all. The date / status / needs-review
     * FILTER narrows which rows are shown; the admin then ticks the ones to
     * regenerate (or select-all the filtered set). Only reports with lab data (a
     * linked test) are shown — those are the regeneratable ones.
     */
    public function table(Table $table): Table
    {
        return $table
            // Only regeneratable reports (those with lab data = a linked test).
            // test_id is a real column, so this avoids a relation subquery in the
            // base render path. The date filter joins the test for report_date.
            ->query(fn (): Builder => Report::query()->whereNotNull('test_id'))
            ->defaultSort('id', 'desc')
            // Filtering is the PRIMARY action here, so the date / status /
            // needs-review filters sit ALWAYS-VISIBLE above the list rather than
            // tucked behind the filter-icon dropdown.
            ->filtersLayout(FiltersLayout::AboveContent)
            ->columns([
                TextColumn::make('pet.name')->label('Pet')->placeholder('—'),
                TextColumn::make('client_name')->label('Client')->placeholder('—')
                    ->state(fn (Report $record): ?string => $record->petClient?->name),
                TextColumn::make('report_date')->label('Report date')->date('j M Y')->placeholder('—'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                IconColumn::make('needs_review')->label('Needs review')->boolean(),
            ])
            ->filters([
                Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')->label('Report date from'),
                        DatePicker::make('to')->label('Report date to'),
                    ])
                    // report_date lives on the linked test. Filter via a raw EXISTS
                    // subquery on the tests table (not the belongsTo→withTrashed
                    // relation, which trips whereHas in the table context).
                    // NOTE: the closure's query parameter MUST be named $query —
                    // Filament's evaluate() injects the live table query by parameter
                    // NAME. A differently-named param gets a throwaway builder by type,
                    // so the constraint silently never applies.
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? null;
                        $to = $data['to'] ?? null;

                        if (! filled($from) && ! filled($to)) {
                            return $query;
                        }

                        return $query->whereExists(function ($sub) use ($from, $to): void {
                            $sub->selectRaw('1')->from('tests')
                                ->whereColumn('tests.id', 'reports.test_id')
                                ->when(filled($from), fn ($s) => $s->whereDate('tests.report_date', '>=', $from))
                                ->when(filled($to), fn ($s) => $s->whereDate('tests.report_date', '<=', $to));
                        });
                    }),
                SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published']),
                TernaryFilter::make('needs_review')->label('Needs review'),
            ])
            ->bulkActions([
                // INCLUSIVE selection: this runs on the SELECTED (ticked) reports.
                // The toolbar button only appears once ≥1 row is selected, so a
                // zero-selection run is impossible.
                BulkAction::make('regenerate')
                    ->label('Regenerate selected reports')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Regenerate the selected reports?')
                    ->modalDescription(fn (Collection $records): HtmlString => new HtmlString($this->confirmationMessage($records->count())))
                    ->modalSubmitActionLabel(fn (Collection $records): string => 'Yes, regenerate '.$records->count().' report(s)')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->startRun(
                        $records->pluck('id')->map(fn ($id): int => (int) $id)->all()
                    )),
            ]);
    }

    protected function confirmationMessage(int $n): string
    {
        return '<p>This will regenerate the <strong>'.$n.'</strong> selected report(s).</p>'
            .'<ul style="margin:8px 0 8px 18px; list-style:disc;">'
            .'<li>It will <strong>OVERWRITE all AI-generated content</strong>, including any manual edits staff have made — those reports will need re-checking.</li>'
            .'<li>It will make up to <strong>'.$n.'</strong> OpenAI API calls (real cost).</li>'
            .'<li>This <strong>cannot be undone.</strong></li>'
            .'</ul>'
            .'<p>Processing runs here in your browser in small batches (keep this page open). Customers\' existing links will then show the corrected report (nothing is re-sent).</p>';
    }

    /**
     * Resume handler: reload a persisted run and continue it. Only the run's owner
     * can resume, and only an interrupted/running run with work left. Seeds the live
     * counters from the stored totals so stats stay cumulative across the resume.
     */
    protected function resumeRun(int $runId): void
    {
        $run = BulkRegenerateRun::query()
            ->forUser((int) auth()->id())
            ->find($runId);

        if (! $run || empty($run->remaining_ids) || $run->status === BulkRegenerateRun::STATUS_CANCELLED
            || $run->status === BulkRegenerateRun::STATUS_COMPLETED) {
            return;
        }

        $this->runId = $run->id;
        $this->pendingIds = array_values($run->remaining_ids);
        $this->total = $run->total;
        $this->succeeded = $run->regenerated_count;
        $this->failed = $run->failed_count;
        $this->needsReviewCount = $run->needs_review_count;
        $this->finished = false;
        $this->running = true;

        $run->update(['status' => BulkRegenerateRun::STATUS_RUNNING, 'last_progress_at' => now()]);

        Notification::make()
            ->title('Resuming bulk regeneration')
            ->body('Continuing the remaining '.count($this->pendingIds).' of '.$this->total.' report(s).')
            ->info()
            ->send();
    }

    /**
     * Start a run on the SELECTED report ids (from the table's bulk action). It does
     * NOT process here — it persists the run + seeds pendingIds and flips $running
     * on; the view's wire:poll then drives processChunk() chunk-by-chunk.
     *
     * @param  array<int,int>  $ids
     */
    public function startRun(array $ids): void
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            Notification::make()->title('Nothing selected to regenerate')->warning()->send();

            return;
        }

        $this->pendingIds = $ids;
        $this->total = count($ids);
        $this->succeeded = 0;
        $this->failed = 0;
        $this->needsReviewCount = 0;
        $this->finished = false;
        $this->running = true;

        // Persist the run so it survives a closed tab. The SELECTED ids become the
        // run's batch_ids; each chunk updates this row (heartbeat + progress).
        $run = BulkRegenerateRun::create([
            'started_by' => auth()->id(),
            'total' => $this->total,
            'batch_ids' => $ids,
            'remaining_ids' => $ids,
            'regenerated_count' => 0,
            'failed_count' => 0,
            'needs_review_count' => 0,
            'status' => BulkRegenerateRun::STATUS_RUNNING,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
        $this->runId = $run->id;
    }

    /**
     * Process ONE chunk (up to CHUNK_SIZE reports) and persist progress — each call
     * is its own short request (driven by the view's poll), so nothing runs long
     * enough to time out. Resilient: a report that errors is logged, counted as
     * failed, and SKIPPED — the run continues. Each report commits immediately.
     */
    public function processChunk(): void
    {
        if (! $this->running) {
            return;
        }

        $chunk = array_splice($this->pendingIds, 0, self::CHUNK_SIZE);

        foreach ($chunk as $id) {
            try {
                $report = Report::find($id);
                if (! $report) {
                    $this->failed++;
                    Log::warning('Bulk regenerate: report not found', ['report_id' => $id]);

                    continue;
                }

                $result = ReportGeneration::regenerateReport($report);

                if ($result['ok']) {
                    $this->succeeded++;
                    if ($result['needs_review']) {
                        $this->needsReviewCount++;
                    }
                } else {
                    // A soft failure (AI error / no lab data) keeps existing content.
                    $this->failed++;
                }

                Log::info('Bulk regenerate: report processed', [
                    'report_id' => $id, 'ok' => $result['ok'], 'reason' => $result['reason'],
                ]);
            } catch (\Throwable $e) {
                // One report's hard failure must NEVER abort the batch.
                $this->failed++;
                Log::error('Bulk regenerate: report errored (skipped)', ['report_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $done = $this->pendingIds === [];

        // Persist progress every chunk (source of truth for resume + dashboard).
        if ($this->runId && $run = BulkRegenerateRun::find($this->runId)) {
            if ($run->status === BulkRegenerateRun::STATUS_CANCELLED) {
                $this->running = false;

                return;
            }

            $run->update([
                'remaining_ids' => $this->pendingIds,
                'regenerated_count' => $this->succeeded,
                'failed_count' => $this->failed,
                'needs_review_count' => $this->needsReviewCount,
                'last_progress_at' => now(),
                'status' => $done ? BulkRegenerateRun::STATUS_COMPLETED : BulkRegenerateRun::STATUS_RUNNING,
                'finished_at' => $done ? now() : null,
            ]);
        }

        if ($done) {
            $this->running = false;
            $this->finished = true;

            Notification::make()
                ->title('Regeneration complete')
                ->body("Regenerated {$this->succeeded}, failed {$this->failed}, flagged needs-review {$this->needsReviewCount}. Use the Reports → Needs review filter to check the flagged ones.")
                ->success()
                ->persistent()
                ->send();
        }
    }

    /** Reports processed so far (for the progress bar). */
    public function getProcessedCountProperty(): int
    {
        return $this->succeeded + $this->failed;
    }

    /** Progress as a 0-100 percentage for the bar. */
    public function getProgressPercentProperty(): int
    {
        return $this->total > 0 ? (int) floor(($this->processedCount / $this->total) * 100) : 0;
    }
}
