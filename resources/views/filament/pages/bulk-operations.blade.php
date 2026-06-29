<x-filament-panels::page>
    @php
        $isSend = $operation === \App\Models\BulkOperationRun::OPERATION_SEND;
        $etaSuffix = $this->eta ? ' (' . $this->eta . ' remaining)' : '';
        $sendChunk = $this->channel === \App\Models\BulkOperationRun::CHANNEL_APP
            ? \App\Filament\Pages\BulkOperations::SEND_CHUNK_SIZES[\App\Models\BulkOperationRun::CHANNEL_APP]
            : \App\Filament\Pages\BulkOperations::SEND_CHUNK_SIZES[\App\Models\BulkOperationRun::CHANNEL_KLAVIYO];
    @endphp

    <div class="space-y-6">
        {{-- INCLUSIVE selection: filter, tick reports (or select-all), then choose an
             action — "Regenerate selected reports" (re-run AI) or "Send selected
             reports" (email customers). Hidden once a run starts. --}}
        @if (! $running && ! $finished)
            <p class="text-sm" style="color:#6b7280;">
                Filter below, then <strong>tick the reports</strong> (or use the header select-all) and choose an action:
                <strong>Regenerate</strong> (re-run AI) or <strong>Send</strong> (email the report to the customer).
                The <strong>Sent</strong> and <strong>Has email</strong> columns show each report's send status before you act.
            </p>
            {{ $this->table }}
        @endif

        {{-- Live progress panel. While $running the wrapper carries wire:poll, so the
             browser AUTO-CONTINUES via processChunk() every ~800ms (each poll = one
             short request processing up to the chunk size for this operation). NO
             queue, NO cron, NO worker — the open page drives the chunks. --}}
        @if ($total > 0)
            <div {!! $running ? 'wire:poll.800ms="processChunk"' : '' !!}>
                <x-filament::section>
                    <x-slot name="heading">
                        @if ($running)
                            {{ $isSend ? 'Sending' : 'Regenerating' }}… {{ $this->processedCount }} of {{ $total }} done
                        @else
                            {{ $isSend ? 'Send complete' : 'Regeneration complete' }}
                        @endif
                    </x-slot>

                    {{-- Progress bar --}}
                    <div style="background:#E5E7EB; border-radius:9999px; height:14px; overflow:hidden;">
                        <div style="height:100%; width:{{ $this->progressPercent }}%; background:#4654A4; border-radius:9999px; transition:width .3s ease;"></div>
                    </div>

                    <div class="mt-3 text-sm" style="display:flex; gap:18px; flex-wrap:wrap; color:#374151;">
                        <span><strong>{{ $this->processedCount }}</strong> / {{ $total }} processed</span>
                        @if ($isSend)
                            <span style="color:#15803d;">Sent: <strong>{{ $succeeded }}</strong></span>
                            <span style="color:#b91c1c;">Failed: <strong>{{ $failed }}</strong></span>
                            <span style="color:#a16207;">Skipped: <strong>{{ $skipped }}</strong></span>
                        @else
                            <span style="color:#15803d;">Regenerated: <strong>{{ $succeeded }}</strong></span>
                            <span style="color:#b91c1c;">Failed: <strong>{{ $failed }}</strong></span>
                            <span style="color:#a16207;">Flagged needs-review: <strong>{{ $needsReviewCount }}</strong></span>
                        @endif
                    </div>

                    @if ($running && $isSend)
                        <p class="mt-3 text-xs" style="color:#b45309;"><strong>Real emails are being sent to customers.</strong> Keep this page open until it finishes{{ $etaSuffix }}. Reports are processed {{ $sendChunk }} at a time.</p>
                    @elseif ($running)
                        <p class="mt-3 text-xs" style="color:#6b7280;">Keep this page open{{ $etaSuffix }}. Reports are processed {{ \App\Filament\Pages\BulkOperations::CHUNK_SIZE }} at a time in your browser; large batches can take a little while.</p>
                    @elseif ($isSend)
                        <p class="mt-3 text-sm" style="color:#374151;">Done. <strong>{{ $succeeded }}</strong> sent, <strong>{{ $failed }}</strong> failed, <strong>{{ $skipped }}</strong> skipped (already-sent / unpublished / no email).</p>
                    @else
                        <p class="mt-3 text-sm" style="color:#374151;">Done. Open <strong>Reports → Needs review</strong> to check the {{ $needsReviewCount }} report(s) flagged for review.</p>
                    @endif
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
