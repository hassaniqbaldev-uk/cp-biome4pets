<?php

namespace App\Support;

/**
 * Read-only reader for the repo's CHANGELOG.md, powering the in-portal Changelog
 * page. It mirrors LogReader: it ONLY opens the file for reading — it never writes,
 * and the changelog is maintained in the repo (shipped with the code), not edited in
 * the portal.
 *
 * The file is a trusted repo file (not user input), but every path is handled
 * defensively: a missing or malformed file yields an empty result, never an error,
 * so the page can show a friendly "no changelog available" message.
 *
 * Format parsed (Keep a Changelog):
 *   ## [v1.4.0] - 2026-06-29      → a version (label + optional date)
 *   ### Added                     → a category within the current version
 *   - some change                 → a bullet entry within the current category
 * Anything before the first version header (the title / workflow preamble) is ignored.
 */
class ChangelogReader
{
    /** The single file this reader ever reads. */
    public static function path(): string
    {
        return base_path('CHANGELOG.md');
    }

    /** Whether the changelog file exists and is readable. */
    public static function exists(): bool
    {
        $path = self::path();

        return is_file($path) && is_readable($path);
    }

    /** Raw file contents, or '' when missing/unreadable (never throws). */
    public static function raw(): string
    {
        if (! self::exists()) {
            return '';
        }

        $contents = @file_get_contents(self::path());

        return $contents === false ? '' : $contents;
    }

    /**
     * Parsed versions, newest first (file order is preserved — the file is authored
     * newest-first). Empty when the file is missing or has no recognisable version
     * headers (malformed) — the caller shows a friendly message for that.
     *
     * @return list<array{version:string, date:?string, groups:list<array{category:string, entries:list<string>}>}>
     */
    public static function versions(): array
    {
        return self::parse(self::raw());
    }

    /**
     * The label of the most recent version (the top entry), or null when the file is
     * missing/malformed. This is the single source of truth for the platform version
     * shown in the admin footer — bumping CHANGELOG.md's top entry moves the footer
     * too, so they can never drift.
     */
    public static function latestVersion(): ?string
    {
        return self::versions()[0]['version'] ?? null;
    }

    /**
     * Parse Keep-a-Changelog markdown into versions → categories → entries. Pure and
     * string-based so it's directly testable.
     *
     * @return list<array{version:string, date:?string, groups:list<array{category:string, entries:list<string>}>}>
     */
    public static function parse(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $versions = [];
        $currentVersion = null;   // index into $versions
        $currentCategory = null;  // index into the current version's groups

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $trimmed = trim($line);

            // Version header: "## [v1.4.0] - 2026-06-29" (date optional).
            if (preg_match('/^##\s+\[([^\]]+)\]\s*(?:-\s*(.+?))?\s*$/', $trimmed, $m)) {
                $versions[] = [
                    'version' => trim($m[1]),
                    'date' => isset($m[2]) && trim($m[2]) !== '' ? trim($m[2]) : null,
                    'groups' => [],
                ];
                $currentVersion = count($versions) - 1;
                $currentCategory = null;

                continue;
            }

            // Ignore everything before the first version header (title / preamble).
            if ($currentVersion === null) {
                continue;
            }

            // Category header: "### Added".
            if (preg_match('/^###\s+(.+?)\s*$/', $trimmed, $m)) {
                $versions[$currentVersion]['groups'][] = [
                    'category' => trim($m[1]),
                    'entries' => [],
                ];
                $currentCategory = count($versions[$currentVersion]['groups']) - 1;

                continue;
            }

            // Bullet entry: "- text" or "* text" (continuation lines of a wrapped
            // bullet are appended to the last entry so multi-line bullets read as one).
            if (preg_match('/^[-*]\s+(.+?)\s*$/', $trimmed, $m)) {
                if ($currentCategory === null) {
                    // A bullet with no category — start an untitled group so it's
                    // still shown rather than silently dropped.
                    $versions[$currentVersion]['groups'][] = ['category' => '', 'entries' => []];
                    $currentCategory = count($versions[$currentVersion]['groups']) - 1;
                }
                $versions[$currentVersion]['groups'][$currentCategory]['entries'][] = self::cleanEntry($m[1]);

                continue;
            }

            // Wrapped continuation of the previous bullet (non-empty, not a header).
            if ($trimmed !== '' && $currentCategory !== null) {
                $entries = &$versions[$currentVersion]['groups'][$currentCategory]['entries'];
                if ($entries !== []) {
                    $entries[count($entries) - 1] = self::cleanEntry($entries[count($entries) - 1].' '.$trimmed);
                }
                unset($entries);
            }
        }

        return $versions;
    }

    /** Strip Keep-a-Changelog **bold** markers so entries read as plain text. */
    protected static function cleanEntry(string $text): string
    {
        return trim(preg_replace('/\*\*(.+?)\*\*/', '$1', $text) ?? $text);
    }
}
