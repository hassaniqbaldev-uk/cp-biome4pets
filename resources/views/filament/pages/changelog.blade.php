<x-filament-panels::page>
    @php
        $versions = $this->versions();

        // Colour the common Keep-a-Changelog categories; anything else is neutral.
        $categoryColor = fn (string $category): string => match (strtolower($category)) {
            'added' => 'success',
            'fixed' => 'danger',
            'changed' => 'warning',
            'improved' => 'info',
            default => 'gray',
        };
    @endphp

    @if (empty($versions))
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No changelog available. Release notes are recorded in
                <code>CHANGELOG.md</code> and will appear here once that file is present.
            </p>
        </x-filament::section>
    @else
        {{-- Spacing is set with inline styles, not Tailwind gap/space utilities: the
             Filament admin uses its own compiled CSS, so custom-page utility classes
             aren't guaranteed to render. Inline margins always apply, so the category
             groups (Added / Fixed / …) and versions stay clearly separated. --}}
        <div>
            @foreach ($versions as $version)
                <div @style(['margin-top: 1.5rem' => ! $loop->first])>
                    <x-filament::section>
                        <x-slot name="heading">
                            <span class="flex items-center gap-3">
                                <x-filament::badge color="primary">{{ $version['version'] }}</x-filament::badge>
                                @if (! empty($version['date']))
                                    <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                                        {{ $version['date'] }}
                                    </span>
                                @endif
                            </span>
                        </x-slot>

                        @if (empty($version['groups']))
                            <p class="text-sm text-gray-500 dark:text-gray-400">No entries for this version.</p>
                        @else
                            <div>
                                @foreach ($version['groups'] as $group)
                                    {{-- Breathing room above every category group after the first. --}}
                                    <div @style(['margin-top: 1.5rem' => ! $loop->first])>
                                        @if ($group['category'] !== '')
                                            <div style="margin-bottom: 0.5rem;">
                                                <x-filament::badge :color="$categoryColor($group['category'])">
                                                    {{ $group['category'] }}
                                                </x-filament::badge>
                                            </div>
                                        @endif

                                        <ul class="list-disc text-sm leading-relaxed text-gray-700 dark:text-gray-300" style="margin: 0; padding-left: 1.25rem;">
                                            @foreach ($group['entries'] as $entry)
                                                <li style="margin-bottom: 0.25rem;">{{ $entry }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </x-filament::section>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
