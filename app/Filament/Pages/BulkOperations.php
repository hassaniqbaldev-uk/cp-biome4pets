<?php

namespace App\Filament\Pages;

use App\Models\BulkOperationRun;
use App\Models\Report;
use App\Support\ReportGeneration;
use App\Support\ReportSender;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
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
 * Bulk Operations — Super-Admin-only tool to run a chosen operation across many
 * reports. Today: REGENERATE (re-run AI generation so reports pick up generation
 * fixes). The infrastructure (filter → tick-select → chunked in-portal processing
 * → persisted run → resume) is operation-aware so Send / Re-send can slot in next
 * via the processReport() seam — regenerate behaves exactly as it always has.
 *
 * Heavy guardrails: regenerate runs OpenAI at real cost and OVERWRITES stored AI
 * content. Flow: FILTER the table (date / status / needs-review) → TICK the reports
 * (or select-all) → choose the operation → explicit CONFIRM → process IN-PORTAL in
 * small chunks with a live progress bar.
 *
 * EXECUTION: fully self-contained, NO queue, NO cron, NO worker. The admin's
 * browser auto-continues through the batch via Livewire polling: each poll is a
 * separate request that processes up to CHUNK_SIZE reports, then the next poll
 * fires the next chunk, until done. Each chunk commits immediately + persists to
 * bulk_regenerate_runs, so partial completion is safe and a closed tab is resumable
 * (the dashboard surfaces interrupted/completed runs).
 */
class BulkOperations extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    /**
     * Reports processed per chunk (per poll request) for REGENERATE. Conservative:
     * each regenerate report is an OpenAI call of a few seconds, so 3 keeps every
     * request well under shared-host PHP max_execution_time with no worker/cron.
     */
    public const CHUNK_SIZE = 3;

    /**
     * SEND chunk size per channel — smaller, because sends are irreversible and the
     * channels rate-limit. Klaviyo is generous (~225/min at 3/poll, with the
     * 429-retryable backstop); App/SMTP is the tight case on shared hosting/SES, so
     * 1/poll (~75/min) is the safe default. Adjustable here once the real SMTP
     * limit is confirmed. (resend reuses these in a later step.)
     */
    public const SEND_CHUNK_SIZES = [
        BulkOperationRun::CHANNEL_KLAVIYO => 3,
        BulkOperationRun::CHANNEL_APP => 1,
    ];

    /**
     * Blast-radius limiter: a single Send run will not dispatch more than this many
     * eligible emails. Over the limit is refused (narrow the selection / batch it),
     * because the action is irreversible.
     */
    public const MAX_BULK_SEND = 200;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Bulk Operations';

    protected static ?string $title = 'Bulk Operations';

    protected static ?string $navigationGroup = 'System';

    // Just after Settings (10) and Users (15).
    protected static ?int $navigationSort = 16;

    protected static string $view = 'filament.pages.bulk-operations';

    // ── Live in-portal run state (drives the chunked auto-continue) ──
    /** True while a run is in progress (the view polls processChunk while true). */
    public bool $running = false;

    /** Which operation this run performs: regenerate | send | resend. */
    public string $operation = BulkOperationRun::OPERATION_REGENERATE;

    /** Send channel for send/resend runs (klaviyo | app); null for regenerate. */
    public ?string $channel = null;

    /** Remaining report ids still to process this run (FIFO; chunk takes the head). */
    public array $pendingIds = [];

    public int $total = 0;

    public int $succeeded = 0;

    public int $failed = 0;

    /** Reports skipped (e.g. unpublished / no-email on send). Stays 0 for regenerate. */
    public int $skipped = 0;

    /** How many regenerated reports came back flagged needs_review (regenerate-only). */
    public int $needsReviewCount = 0;

    /** True once a run has finished, so the result summary stays visible. */
    public bool $finished = false;

    /** The persisted bulk_regenerate_runs row id for the active/resumed run. */
    public ?int $runId = null;

    /**
     * Sensitive tool (paid OpenAI calls + destructive overwrite, and soon real
     * customer emails): Super Admins ONLY. canAccess() is the security gate
     * (Filament 403s on direct URL access for Admins); shouldRegisterNavigation()
     * also hides the nav item.
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
     * FILTER narrows which rows are shown; the admin then ticks the ones to act on
     * (or select-all the filtered set). Only reports with lab data (a linked test)
     * are shown — those are the actionable ones.
     */
    public function table(Table $table): Table
    {
        return $table
            // Only actionable reports (those with lab data = a linked test).
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
                // Send-relevant status the operator sees BEFORE acting: whether this
                // report has already been delivered, and whether it has an email to
                // send to. Derived (no stored column) — display only.
                TextColumn::make('sent_status')->label('Sent')->badge()
                    ->state(fn (Report $record): string => $record->hasBeenSent() ? 'Sent' : 'Unsent')
                    ->color(fn (Report $record): string => $record->hasBeenSent() ? 'success' : 'gray'),
                IconColumn::make('has_email')->label('Has email')->boolean()
                    ->state(fn (Report $record): bool => filled($record->petClient?->email)),
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
                        $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                        BulkOperationRun::OPERATION_REGENERATE,
                    )),

                // SEND (unsent): the first operation that emails real customers. A
                // SEPARATE action (primary colour) with a required channel pick and a
                // loud, count-itemised confirmation. Eligibility = published & unsent
                // & has-email; ineligible selected rows are skipped (never sent).
                BulkAction::make('send_unsent')
                    ->label('Send selected reports')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->modalHeading('Send the selected reports to customers?')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalIconColor('warning')
                    ->modalDescription(fn (Collection $records): HtmlString => new HtmlString($this->sendConfirmationMessage($records)))
                    ->form([
                        Radio::make('channel')
                            ->label('Send via')
                            ->options([
                                BulkOperationRun::CHANNEL_KLAVIYO => 'Klaviyo',
                                BulkOperationRun::CHANNEL_APP => 'App (our SMTP)',
                            ])
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('Send now')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records, array $data) => $this->startSendRun($records, (string) ($data['channel'] ?? ''))),
            ]);
    }

    /**
     * Partition a selection into send buckets by CURRENT state (priority order so a
     * report is in exactly one bucket): unpublished → no-email → already-sent →
     * eligible. hasBeenSent() implies published, so already-sent is published.
     *
     * @return array{eligible:array<int,int>, eligible_count:int, already_sent:int, no_email:int, unpublished:int, total:int}
     */
    protected function partitionForSend(Collection $records): array
    {
        $eligible = [];
        $alreadySent = 0;
        $noEmail = 0;
        $unpublished = 0;

        foreach ($records as $report) {
            if ($report->status !== 'published') {
                $unpublished++;
            } elseif (blank($report->petClient?->email)) {
                $noEmail++;
            } elseif ($report->hasBeenSent()) {
                $alreadySent++;
            } else {
                $eligible[] = (int) $report->id;
            }
        }

        return [
            'eligible' => $eligible,
            'eligible_count' => count($eligible),
            'already_sent' => $alreadySent,
            'no_email' => $noEmail,
            'unpublished' => $unpublished,
            'total' => $records->count(),
        ];
    }

    /** The loud, count-itemised send confirmation (computed from the live selection). */
    protected function sendConfirmationMessage(Collection $records): string
    {
        $p = $this->partitionForSend($records);
        $over = $p['eligible_count'] > self::MAX_BULK_SEND;

        $msg = '<p>This will send <strong>'.$p['eligible_count'].'</strong> real email(s) to customers. <strong>This CANNOT be undone.</strong></p>'
            .'<p style="margin-top:8px;">Of your '.$p['total'].' selected report(s):</p>'
            .'<ul style="margin:8px 0 8px 18px; list-style:disc;">'
            .'<li><strong>'.$p['eligible_count'].'</strong> will be sent.</li>'
            .'<li><strong>'.$p['already_sent'].'</strong> already sent (skipped).</li>'
            .'<li><strong>'.$p['no_email'].'</strong> have no email (skipped).</li>'
            .'<li><strong>'.$p['unpublished'].'</strong> not published (skipped).</li>'
            .'</ul>';

        if ($over) {
            $msg .= '<p style="color:#b91c1c;"><strong>This is over the '.self::MAX_BULK_SEND.'-per-run safety limit — it will be refused. Narrow your selection or send in batches.</strong></p>';
        } else {
            $msg .= '<p>Choose a channel and confirm. Processing runs here in your browser in small batches — keep this page open until it finishes.</p>';
        }

        return $msg;
    }

    /**
     * Validate + start a SEND run. The batch is the WHOLE selection (so ineligible
     * rows are visibly skipped+counted during processing, re-checked authoritatively
     * per report), but the per-run CAP is on the ELIGIBLE email count — the
     * irreversible blast radius.
     */
    protected function startSendRun(Collection $records, string $channel): void
    {
        if (! in_array($channel, [BulkOperationRun::CHANNEL_KLAVIYO, BulkOperationRun::CHANNEL_APP], true)) {
            Notification::make()->title('Pick a send channel')->warning()->send();

            return;
        }

        $p = $this->partitionForSend($records);

        if ($p['eligible_count'] === 0) {
            Notification::make()
                ->title('Nothing eligible to send')
                ->body('None of the selected reports are published, unsent and have an email address.')
                ->warning()
                ->send();

            return;
        }

        if ($p['eligible_count'] > self::MAX_BULK_SEND) {
            Notification::make()
                ->title('Over the per-run limit')
                ->body('This would send '.$p['eligible_count'].' emails, over the '.self::MAX_BULK_SEND.'-per-run safety limit. Narrow your selection or send in batches.')
                ->danger()
                ->send();

            return;
        }

        // Batch = all selected (eligible are sent; ineligible are skipped per report).
        $this->startRun(
            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            BulkOperationRun::OPERATION_SEND,
            $channel,
        );
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
     * counters AND the operation/channel from the stored row so the resumed run
     * continues as the right TYPE (regenerate vs send) and stats stay cumulative.
     */
    protected function resumeRun(int $runId): void
    {
        $run = BulkOperationRun::query()
            ->forUser((int) auth()->id())
            ->find($runId);

        if (! $run || empty($run->remaining_ids) || $run->status === BulkOperationRun::STATUS_CANCELLED
            || $run->status === BulkOperationRun::STATUS_COMPLETED) {
            return;
        }

        $this->runId = $run->id;
        $this->operation = $run->operation;
        $this->channel = $run->channel;
        $this->pendingIds = array_values($run->remaining_ids);
        $this->total = $run->total;
        $this->succeeded = $run->regenerated_count;
        $this->failed = $run->failed_count;
        $this->skipped = $run->skipped_count;
        $this->needsReviewCount = $run->needs_review_count;
        $this->finished = false;
        $this->running = true;

        $run->update(['status' => BulkOperationRun::STATUS_RUNNING, 'last_progress_at' => now()]);

        Notification::make()
            ->title('Resuming '.$run->operationLabel())
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
    public function startRun(array $ids, string $operation = BulkOperationRun::OPERATION_REGENERATE, ?string $channel = null): void
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            Notification::make()->title('Nothing selected')->warning()->send();

            return;
        }

        $this->operation = $operation;
        $this->channel = $channel;
        $this->pendingIds = $ids;
        $this->total = count($ids);
        $this->succeeded = 0;
        $this->failed = 0;
        $this->skipped = 0;
        $this->needsReviewCount = 0;
        $this->finished = false;
        $this->running = true;

        // Persist the run so it survives a closed tab. The SELECTED ids become the
        // run's batch_ids; each chunk updates this row (heartbeat + progress).
        $run = BulkOperationRun::create([
            'started_by' => auth()->id(),
            'operation' => $operation,
            'channel' => $channel,
            'total' => $this->total,
            'batch_ids' => $ids,
            'remaining_ids' => $ids,
            'regenerated_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'needs_review_count' => 0,
            'status' => BulkOperationRun::STATUS_RUNNING,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
        $this->runId = $run->id;
    }

    /** Reports processed per poll for the CURRENT run (per-channel for send). */
    protected function currentChunkSize(): int
    {
        if ($this->operation === BulkOperationRun::OPERATION_REGENERATE) {
            return self::CHUNK_SIZE;
        }

        return self::SEND_CHUNK_SIZES[$this->channel] ?? 1;
    }

    /**
     * Process ONE chunk (up to currentChunkSize reports) and persist progress — each
     * call is its own short request (driven by the view's poll), so nothing runs
     * long enough to time out. Resilient: a report that errors is logged, counted as
     * failed, and SKIPPED — the run continues.
     *
     * Persistence is PER REPORT (remaining_ids shrinks as each id finishes), so a
     * crash leaves at most ONE in-flight report ambiguous and the done ones are
     * already out of remaining_ids — the key to safe resume for irreversible sends.
     * A retryable (429) report is LEFT in remaining_ids and the chunk stops, so a
     * later poll retries it without it ever being burned as failed or sent.
     */
    public function processChunk(): void
    {
        if (! $this->running) {
            return;
        }

        // Bail immediately if the run was cancelled from the dashboard.
        if ($this->runCancelled()) {
            $this->running = false;

            return;
        }

        $size = $this->currentChunkSize();
        $processed = 0;

        while ($processed < $size && $this->pendingIds !== []) {
            $id = $this->pendingIds[0]; // peek the head; only remove once truly done

            try {
                $report = Report::find($id);
                if (! $report) {
                    $this->failed++;
                    array_shift($this->pendingIds);
                    Log::warning('Bulk operation: report not found', ['report_id' => $id, 'operation' => $this->operation]);
                    $this->persistProgress();
                    $processed++;

                    continue;
                }

                $outcome = $this->processReport($report);

                // Retryable (rate-limited): leave it in remaining_ids and stop this
                // chunk — a later poll retries it. Not counted as failed or sent.
                if ($outcome['retryable']) {
                    Log::info('Bulk operation: report retryable, left for retry', ['report_id' => $id, 'operation' => $this->operation]);
                    $this->persistProgress();

                    return;
                }

                if ($outcome['skipped']) {
                    $this->skipped++;
                } elseif ($outcome['ok']) {
                    $this->succeeded++;
                    if ($outcome['needs_review']) {
                        $this->needsReviewCount++;
                    }
                } else {
                    $this->failed++;
                }

                array_shift($this->pendingIds); // done → remove from remaining

                Log::info('Bulk operation: report processed', [
                    'report_id' => $id, 'operation' => $this->operation,
                    'ok' => $outcome['ok'], 'skipped' => $outcome['skipped'], 'reason' => $outcome['reason'],
                ]);
            } catch (\Throwable $e) {
                // One report's hard failure must NEVER abort the batch.
                $this->failed++;
                array_shift($this->pendingIds);
                Log::error('Bulk operation: report errored (skipped)', ['report_id' => $id, 'operation' => $this->operation, 'error' => $e->getMessage()]);
            }

            $this->persistProgress(); // PER-REPORT commit (resume safety)
            $processed++;
        }

        if ($this->pendingIds === []) {
            $this->running = false;
            $this->finished = true;
            $this->persistProgress(done: true);
            $this->sendCompletionNotification();
        }
    }

    /** Has the persisted run been cancelled from the dashboard? */
    protected function runCancelled(): bool
    {
        return $this->runId
            && BulkOperationRun::whereKey($this->runId)->value('status') === BulkOperationRun::STATUS_CANCELLED;
    }

    /** Persist live counters + remaining_ids to the run row (heartbeat + resume source). */
    protected function persistProgress(bool $done = false): void
    {
        if (! $this->runId || ! ($run = BulkOperationRun::find($this->runId))) {
            return;
        }

        if ($run->status === BulkOperationRun::STATUS_CANCELLED) {
            $this->running = false;

            return;
        }

        $run->update([
            'remaining_ids' => $this->pendingIds,
            'regenerated_count' => $this->succeeded,
            'failed_count' => $this->failed,
            'skipped_count' => $this->skipped,
            'needs_review_count' => $this->needsReviewCount,
            'last_progress_at' => now(),
            'status' => $done ? BulkOperationRun::STATUS_COMPLETED : BulkOperationRun::STATUS_RUNNING,
            'finished_at' => $done ? now() : null,
        ]);
    }

    /**
     * Per-report dispatch — the operation SEAM. Returns a uniform outcome:
     *   ['ok' => bool, 'skipped' => bool, 'needs_review' => bool, 'reason' => ?string, 'retryable' => bool]
     *
     * @return array{ok:bool, skipped:bool, needs_review:bool, reason:?string, retryable:bool}
     */
    protected function processReport(Report $report): array
    {
        return match ($this->operation) {
            BulkOperationRun::OPERATION_REGENERATE => $this->regenerateOne($report),
            BulkOperationRun::OPERATION_SEND => $this->sendOne($report),
            // OPERATION_RESEND slots in here next step (reuses sendOne, sent-targeted).
            default => throw new \LogicException("Unsupported bulk operation [{$this->operation}]"),
        };
    }

    /** Regenerate one report — byte-identical to the original processor logic. */
    protected function regenerateOne(Report $report): array
    {
        $result = ReportGeneration::regenerateReport($report);

        return [
            'ok' => (bool) $result['ok'],
            'skipped' => false,
            // A soft failure (AI error / no lab data) keeps existing content.
            'needs_review' => $result['ok'] && $result['needs_review'],
            'reason' => $result['reason'],
            'retryable' => false,
        ];
    }

    /**
     * Send one report (SEND operation) through the SHARED ReportSender. Authoritative
     * per-report re-checks at send time (the table snapshot can be stale):
     *   - already sent → SKIP (idempotency; this is the resume double-send guard —
     *     a successful send that crashed before remaining_ids was written is skipped
     *     on retry, so it can never be sent twice),
     *   - publish-gate / no-email are handled inside ReportSender (→ skipped),
     *   - a 429 is retryable (records nothing, left for a later poll).
     */
    protected function sendOne(Report $report): array
    {
        // Authoritative current state, not the stale selection snapshot.
        $report->refresh();

        if ($report->hasBeenSent()) {
            return ['ok' => false, 'skipped' => true, 'needs_review' => false, 'reason' => 'already_sent', 'retryable' => false];
        }

        $result = ReportSender::send($report, (string) $this->channel);

        if ($result['retryable'] ?? false) {
            return ['ok' => false, 'skipped' => false, 'needs_review' => false, 'reason' => 'rate_limited', 'retryable' => true];
        }

        return [
            'ok' => (bool) $result['ok'],
            'skipped' => (bool) $result['skipped'],
            'needs_review' => false,
            'reason' => $result['reason'],
            'retryable' => false,
        ];
    }

    /** Operation-aware completion toast (regenerate keeps its original wording). */
    protected function sendCompletionNotification(): void
    {
        if ($this->operation === BulkOperationRun::OPERATION_REGENERATE) {
            Notification::make()
                ->title('Regeneration complete')
                ->body("Regenerated {$this->succeeded}, failed {$this->failed}, flagged needs-review {$this->needsReviewCount}. Use the Reports → Needs review filter to check the flagged ones.")
                ->success()
                ->persistent()
                ->send();

            return;
        }

        // Send / resend (wired next step) — generic summary including skips.
        $noun = $this->operation === BulkOperationRun::OPERATION_RESEND ? 'Re-send' : 'Send';
        Notification::make()
            ->title($noun.' complete')
            ->body("Sent {$this->succeeded}, failed {$this->failed}, skipped {$this->skipped}.")
            ->success()
            ->persistent()
            ->send();
    }

    /** Reports processed so far (for the progress bar). */
    public function getProcessedCountProperty(): int
    {
        return $this->succeeded + $this->failed + $this->skipped;
    }

    /** Progress as a 0-100 percentage for the bar. */
    public function getProgressPercentProperty(): int
    {
        return $this->total > 0 ? (int) floor(($this->processedCount / $this->total) * 100) : 0;
    }

    /** Rough ETA for the running batch, e.g. "about 4 min" — null when not running. */
    public function getEtaProperty(): ?string
    {
        $remaining = count($this->pendingIds);
        if (! $this->running || $remaining <= 0) {
            return null;
        }

        // One short poll per chunk; pad for per-report latency (sends are slower).
        $perReportSeconds = $this->operation === BulkOperationRun::OPERATION_REGENERATE ? 3 : 4;
        $seconds = (int) ceil($remaining * $perReportSeconds / max(1, $this->currentChunkSize()));

        if ($seconds < 60) {
            return 'about '.max(5, $seconds).' seconds';
        }

        return 'about '.(int) ceil($seconds / 60).' min';
    }
}
