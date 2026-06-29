<x-filament-panels::page>
    @php
        $files = $this->files();
        $entries = $this->entries();

        // Danger for error-and-worse, warning for warnings, gray otherwise.
        $levelColor = fn (string $level): string => match (true) {
            in_array($level, \App\Support\LogReader::ERROR_LEVELS, true) => 'danger',
            $level === 'WARNING' => 'warning',
            default => 'gray',
        };
    @endphp

    <x-filament::section>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-col gap-1">
                <label for="log-file" class="text-sm font-medium text-gray-950 dark:text-white">
                    Log file
                </label>
                @if (count($files))
                    <select
                        id="log-file"
                        wire:model.live="file"
                        class="fi-input block w-full rounded-lg border-none bg-white py-1.5 pe-8 ps-3 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:w-80"
                    >
                        @foreach ($files as $f)
                            <option value="{{ $f }}">{{ $f }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        No log files found in <code>storage/logs</code>.
                    </span>
                @endif
            </div>

            <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input
                    type="checkbox"
                    wire:model.live="errorsOnly"
                    class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-600 dark:bg-white/5 dark:border-white/20"
                >
                Errors only
            </label>
        </div>
    </x-filament::section>

    @if ($this->selectedFileUnreadable())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                That log file can’t be read right now (it may have been removed or isn’t readable).
                Pick another file above, or check again later.
            </p>
        </x-filament::section>
    @elseif (! count($files))
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                There are no log files to show yet. They’ll appear here once the application writes to
                <code>storage/logs</code>.
            </p>
        </x-filament::section>
    @elseif (! count($entries))
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($this->errorsOnly)
                    No error entries in this log. Untick “Errors only” to see every logged entry.
                @else
                    No log entries found in this file.
                @endif
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">
                Recent entries
            </x-slot>
            <x-slot name="description">
                Showing the {{ count($entries) }} most recent
                {{ $this->errorsOnly ? 'error ' : '' }}{{ \Illuminate\Support\Str::plural('entry', count($entries)) }}
                (newest first) from the tail of the log.
            </x-slot>

            <div class="flex flex-col gap-3">
                @foreach ($entries as $entry)
                    <details class="group rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                        <summary class="flex cursor-pointer list-none items-start gap-3 p-3">
                            <x-filament::badge :color="$levelColor($entry['level'])">
                                {{ $entry['level'] }}
                            </x-filament::badge>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $entry['message'] }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $entry['timestamp'] }} · {{ $entry['env'] }}
                                </p>
                            </div>
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="mt-1 h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180"
                            />
                        </summary>
                        <pre class="overflow-x-auto border-t border-gray-950/5 p-3 text-xs leading-relaxed text-gray-700 dark:border-white/10 dark:text-gray-300"><code>{{ $entry['stack'] }}</code></pre>
                    </details>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
