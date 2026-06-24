<x-filament-panels::page>
    <div class="space-y-6">
        {{-- INCLUSIVE selection: filter the table, tick the reports to regenerate
             (or select-all), then the "Regenerate selected reports" bulk action.
             Hidden once a run starts so the progress panel takes over. --}}
        @if (! $running && ! $finished)
            <p class="text-sm" style="color:#6b7280;">
                Filter below, then <strong>tick the reports to regenerate</strong> (or use the header select-all),
                and choose <strong>Regenerate selected reports</strong>. Only the ticked reports are regenerated.
            </p>
            {{ $this->table }}
        @endif

        {{-- Live progress panel — shown once a run has started or finished. While
             $running is true the wrapper carries wire:poll, so the browser
             AUTO-CONTINUES by calling processChunk() every ~800ms (each poll = one
             short request that regenerates up to CHUNK_SIZE reports). When the run
             finishes, $running flips false → the poll attribute is gone → polling
             stops. NO queue, NO cron, NO worker — the open page drives the chunks. --}}
        @if ($total > 0)
            <div {!! $running ? 'wire:poll.800ms="processChunk"' : '' !!}>
                <x-filament::section>
                    <x-slot name="heading">
                        @if ($running)
                            Regenerating… {{ $this->processedCount }} of {{ $total }} done
                        @else
                            Regeneration complete
                        @endif
                    </x-slot>

                    {{-- Progress bar --}}
                    <div style="background:#E5E7EB; border-radius:9999px; height:14px; overflow:hidden;">
                        <div style="height:100%; width:{{ $this->progressPercent }}%; background:#4654A4; border-radius:9999px; transition:width .3s ease;"></div>
                    </div>

                    <div class="mt-3 text-sm" style="display:flex; gap:18px; flex-wrap:wrap; color:#374151;">
                        <span><strong>{{ $this->processedCount }}</strong> / {{ $total }} processed</span>
                        <span style="color:#15803d;">Regenerated: <strong>{{ $succeeded }}</strong></span>
                        <span style="color:#b91c1c;">Failed: <strong>{{ $failed }}</strong></span>
                        <span style="color:#a16207;">Flagged needs-review: <strong>{{ $needsReviewCount }}</strong></span>
                    </div>

                    @if ($running)
                        <p class="mt-3 text-xs" style="color:#6b7280;">Keep this page open. Reports are processed {{ \App\Filament\Pages\BulkRegenerateReports::CHUNK_SIZE }} at a time in your browser; large batches can take a little while.</p>
                    @else
                        <p class="mt-3 text-sm" style="color:#374151;">Done. Open <strong>Reports → Needs review</strong> to check the {{ $needsReviewCount }} report(s) flagged for review.</p>
                    @endif
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
