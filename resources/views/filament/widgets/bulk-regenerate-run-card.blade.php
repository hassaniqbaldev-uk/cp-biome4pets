@php($run = $this->getRun())

<div>
    @if ($run && $run->status === \App\Models\BulkRegenerateRun::STATUS_COMPLETED)
        {{-- Completed (unacknowledged) → success card --}}
        <x-filament::section>
            <div style="display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap;">
                <div style="flex:0 0 auto; width:40px; height:40px; border-radius:9999px; background:#dcfce7; display:flex; align-items:center; justify-content:center;">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6" style="color:#16a34a;" />
                </div>
                <div style="flex:1; min-width:240px;">
                    <h3 style="font-weight:700; color:#301C47; margin:0 0 4px;">Bulk regeneration completed</h3>
                    <p style="margin:0; color:#374151; font-size:14px;">
                        <strong>{{ $run->regenerated_count }}</strong> regenerated,
                        <strong>{{ $run->failed_count }}</strong> failed,
                        <strong>{{ $run->needs_review_count }}</strong> flagged for review
                        (of {{ $run->total }}).
                    </p>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    @if ($run->needs_review_count > 0)
                        <x-filament::button tag="a" :href="$this->needsReviewUrl()" color="warning" size="sm" icon="heroicon-o-flag">
                            Review {{ $run->needs_review_count }}
                        </x-filament::button>
                    @endif
                    <x-filament::button wire:click="acknowledge" color="gray" size="sm">
                        Dismiss
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

    @elseif ($run && $run->status === \App\Models\BulkRegenerateRun::STATUS_INTERRUPTED)
        {{-- Interrupted (running + stale heartbeat) → warning card --}}
        <x-filament::section>
            <div style="display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap;">
                <div style="flex:0 0 auto; width:40px; height:40px; border-radius:9999px; background:#fef3c7; display:flex; align-items:center; justify-content:center;">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-6 w-6" style="color:#b45309;" />
                </div>
                <div style="flex:1; min-width:240px;">
                    <h3 style="font-weight:700; color:#301C47; margin:0 0 4px;">A bulk regeneration was interrupted</h3>
                    <p style="margin:0; color:#374151; font-size:14px;">
                        <strong>{{ $run->doneCount() }}</strong> of <strong>{{ $run->total }}</strong> completed,
                        <strong>{{ $run->remainingCount() }}</strong> remaining. The tab was closed before it finished.
                    </p>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <x-filament::button tag="a" :href="$this->resumeUrl($run)" color="primary" size="sm" icon="heroicon-o-arrow-path">
                        Resume
                    </x-filament::button>
                    <x-filament::button wire:click="cancel" color="gray" size="sm">
                        Cancel
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif
</div>
