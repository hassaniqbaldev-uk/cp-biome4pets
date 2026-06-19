<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-chat-bubble-left-right" heading="Share feedback or report an issue">
        <div class="text-sm leading-6 text-gray-600 dark:text-gray-300">
            <p>
                On the right of the screen you will see a floating white feedback bar. Use it to
                capture screenshots, record video, and send feedback or report issues to us directly.
                Just click it to submit your feedback.
            </p>
            <p class="mt-3">
                Everything you send reaches the CreativePixels team, who build and support this portal.
            </p>
        </div>
    </x-filament::section>

    <div class="flex flex-col items-center gap-2 pt-4 text-center">
        <span class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">
            Built and supported by
        </span>
        <a href="https://cp.agency" target="_blank" rel="noopener noreferrer">
            <img
                src="{{ \App\Filament\Pages\ReportAnIssue::AGENCY_LOGO }}"
                alt="CreativePixels"
                class="h-9 w-auto dark:invert"
            >
        </a>
    </div>
</x-filament-panels::page>
