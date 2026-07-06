{{-- Subtle version / product label in the panel footer. The version is read from
     CHANGELOG.md's top entry (the single source of truth) so it can't drift; the
     hardcoded fallback covers a missing/malformed changelog file. --}}
<div class="w-full py-3 text-center text-xs text-gray-400 dark:text-gray-600">
    {{ \App\Support\ChangelogReader::latestVersion() ?? 'v1.4.1' }} - Biome4Pets Portal
</div>
