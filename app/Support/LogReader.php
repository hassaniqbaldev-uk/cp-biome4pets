<?php

namespace App\Support;

/**
 * Reader for the Laravel log files under storage/logs, powering the Super-Admin
 * Error Logs page. Every method is read-only EXCEPT clear(), which empties a
 * whitelisted log in place for the manual "Clear logs" button (it truncates to
 * zero bytes — it never deletes the file, so logging continues into the same file).
 *
 * Two safety properties matter here:
 *   - Bounded memory: a production log can be tens of MB. We only ever read the
 *     last TAIL_BYTES of a file (seek to the tail) and cap the parsed result at
 *     MAX_ENTRIES, so the page can't OOM on a huge log.
 *   - No path traversal: a caller-supplied filename is resolved by WHITELIST —
 *     only a basename that appears in availableFiles() (the *.log files actually
 *     in the logs dir) is accepted, so '../.env', absolute paths, etc. resolve to
 *     null and can never escape storage/logs.
 */
class LogReader
{
    /** Read at most the last 256 KB of any log file — keeps memory bounded. */
    public const TAIL_BYTES = 262144;

    /** Never return more than this many parsed entries (most recent first). */
    public const MAX_ENTRIES = 200;

    /** Monolog levels treated as "errors" for the errors-only view. */
    public const ERROR_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** The single directory this reader is ever allowed to read from. */
    public static function logDir(): string
    {
        return storage_path('logs');
    }

    /**
     * The *.log files in the logs dir, most-recently-modified first. Returned as
     * basenames — this list is the whitelist resolve() validates against.
     *
     * @return list<string>
     */
    public static function availableFiles(): array
    {
        $dir = self::logDir();

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.log') ?: [];

        // Most recent first so the picker defaults to the freshest log.
        usort($files, fn (string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));

        return array_values(array_map('basename', $files));
    }

    /**
     * Resolve a user-supplied filename to a readable path INSIDE the logs dir, or
     * null. Whitelist-based: basename($name) must be one of availableFiles(), so
     * traversal sequences / absolute paths can never reach outside storage/logs.
     */
    public static function resolve(?string $name): ?string
    {
        if (blank($name)) {
            return null;
        }

        $base = basename($name);

        if (! in_array($base, self::availableFiles(), true)) {
            return null;
        }

        $path = self::logDir().'/'.$base;

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * Empty (truncate to zero bytes) a whitelisted log file IN PLACE for the manual
     * "Clear logs" button. Path-safety reuses resolve()'s whitelist, so only a real
     * *.log file inside storage/logs can ever be cleared — a traversal/absolute path
     * resolves to null and is refused. The file is truncated, NOT deleted: the inode
     * stays and Laravel keeps appending to the same file afterwards, so logging is
     * never broken. Returns true on success, false if the file can't be resolved or
     * written.
     */
    public static function clear(?string $name): bool
    {
        $path = self::resolve($name);

        if ($path === null) {
            return false;
        }

        // 'w' opens with O_TRUNC and writing '' leaves a zero-byte file in place.
        return @file_put_contents($path, '') !== false;
    }

    /**
     * Read the last $maxBytes of a file without loading the whole thing into
     * memory. Seeks to (size - maxBytes); any partial first line that leaves is
     * discarded by the parser (it won't start with a [timestamp] header).
     */
    public static function tail(string $path, int $maxBytes = self::TAIL_BYTES): string
    {
        $size = @filesize($path);
        $handle = @fopen($path, 'rb');

        if ($size === false || $handle === false) {
            return '';
        }

        try {
            $offset = max(0, $size - $maxBytes);
            if ($offset > 0) {
                fseek($handle, $offset);
            }
            $data = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return $data === false ? '' : $data;
    }

    /**
     * Parsed entries from a log file's tail, most recent first. Empty array when
     * the file is missing/unreadable or holds no recognisable entries.
     *
     * @return list<array{timestamp:string, env:string, level:string, message:string, stack:string}>
     */
    public static function entries(string $path, int $maxEntries = self::MAX_ENTRIES): array
    {
        return self::parse(self::tail($path), $maxEntries);
    }

    /**
     * Parse raw Laravel log text into entries, most recent first. An entry begins
     * at a line of the form "[<timestamp>] <env>.<LEVEL>: <message>" and runs
     * (including its multi-line stack trace / context) until the next such header.
     *
     * @return list<array{timestamp:string, env:string, level:string, message:string, stack:string}>
     */
    public static function parse(string $raw, int $maxEntries = self::MAX_ENTRIES): array
    {
        if ($raw === '') {
            return [];
        }

        // Header: [2026-06-19 12:34:56(.uuuuuu)(+00:00)] production.ERROR: message
        $pattern = '/^\[(\d{4}-\d{2}-\d{2}[ T][0-9:.+\-]+)\]\s+([A-Za-z0-9_\-]+)\.([A-Z]+):\s?(.*)$/m';

        if (! preg_match_all($pattern, $raw, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $entries = [];
        $count = count($matches);

        for ($i = 0; $i < $count; $i++) {
            $start = $matches[$i][0][1];
            $end = ($i + 1 < $count) ? $matches[$i + 1][0][1] : strlen($raw);

            $entries[] = [
                'timestamp' => $matches[$i][1][0],
                'env' => $matches[$i][2][0],
                'level' => strtoupper($matches[$i][3][0]),
                'message' => self::cleanMessage($matches[$i][4][0]),
                'stack' => rtrim(substr($raw, $start, $end - $start)),
            ];
        }

        // The file is append-ordered (chronological); newest first for the viewer.
        return array_slice(array_reverse($entries), 0, $maxEntries);
    }

    /**
     * The human-readable first line of an entry: trimmed, with the trailing
     * {"exception": ...} / context JSON dropped (it's reproduced in the stack).
     */
    protected static function cleanMessage(string $firstLine): string
    {
        $message = trim($firstLine);

        // Laravel appends the context/exception payload as " {...}" — cut it.
        $bracePos = strpos($message, ' {"');
        if ($bracePos !== false) {
            $message = rtrim(substr($message, 0, $bracePos));
        }

        return $message !== '' ? $message : '(no message)';
    }
}
