<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Timeline</x-slot>
        <x-slot name="description">Tests, reports and health notes for this pet — newest first.</x-slot>

        {{-- Filters: type + inclusive date range. Combine (type AND range). --}}
        <div class="flex flex-wrap items-end gap-4">
            <div class="flex flex-col gap-1">
                <label for="timeline-type" class="text-sm font-medium text-gray-700 dark:text-gray-200">Type</label>
                <select
                    id="timeline-type"
                    wire:model.live="typeFilter"
                    class="fi-input rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                >
                    @foreach ($this->getTypeOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="timeline-from" class="text-sm font-medium text-gray-700 dark:text-gray-200">From</label>
                <input
                    id="timeline-from"
                    type="date"
                    wire:model.live="fromDate"
                    class="fi-input rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                />
            </div>

            <div class="flex flex-col gap-1">
                <label for="timeline-to" class="text-sm font-medium text-gray-700 dark:text-gray-200">To</label>
                <input
                    id="timeline-to"
                    type="date"
                    wire:model.live="toDate"
                    class="fi-input rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                />
            </div>
        </div>

        @php $events = $this->events; @endphp

        <div class="mt-4">
            @forelse ($events as $event)
                <div @class([
                    'flex items-start gap-3 py-3',
                    'border-t border-gray-100 dark:border-white/10' => ! $loop->first,
                ])>
                    <x-filament::icon
                        :icon="$event['icon']"
                        @class([
                            'mt-0.5 h-5 w-5 shrink-0',
                            'text-info-500' => $event['color'] === 'info',
                            'text-success-500' => $event['color'] === 'success',
                            'text-warning-500' => $event['color'] === 'warning',
                        ])
                    />

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge :color="$event['color']">{{ $event['type_label'] }}</x-filament::badge>
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $event['title'] }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ optional($event['date'])->format('j M Y') ?? '—' }}
                            </span>
                        </div>

                        @if (filled($event['summary']))
                            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $event['summary'] }}</p>
                        @endif

                        @if (! empty($event['links']))
                            <div class="mt-1 flex flex-wrap gap-4">
                                @foreach ($event['links'] as $link)
                                    <a
                                        href="{{ $link['url'] }}"
                                        @if ($link['newTab']) target="_blank" rel="noopener" @endif
                                        class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    >{{ $link['label'] }}</a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No events match these filters.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
