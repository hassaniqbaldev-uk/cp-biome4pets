<?php

namespace Tests\Feature;

use App\Support\LogReader;
use Tests\TestCase;

/**
 * The read-only log reader behind the Error Logs page. Covers entry parsing
 * (timestamp / level / message / stack, newest first), bounded tail reads, and —
 * most importantly — the whitelist filename resolution that blocks path traversal
 * outside storage/logs.
 */
class LogReaderTest extends TestCase
{
    /** Temp log files created in storage/logs to clean up afterwards. */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        $this->created = [];

        parent::tearDown();
    }

    /** Write a uniquely-named real log file into storage/logs and track it. */
    private function writeLog(string $contents): string
    {
        $name = 'laravel-test-'.uniqid().'.log';
        $path = LogReader::logDir().'/'.$name;
        file_put_contents($path, $contents);
        $this->created[] = $path;

        return $name;
    }

    private const SAMPLE = <<<'LOG'
        [2026-06-19 12:00:00] production.INFO: Process CSV button clicked {"type":"string"}
        [2026-06-19 12:30:00] production.ERROR: Method App\Filament\Resources\ReportResource\Pages\EditReport::fillForm does not exist {"exception":"[object] (BadMethodCallException(code: 0): ...)"}
        #0 /var/www/app/Http/Kernel.php(123): Illuminate\Foundation\Http\Kernel->handle()
        #1 /var/www/public/index.php(55): $kernel->handle()
        [2026-06-19 13:00:00] production.WARNING: Plan copy guardrail: step count drift
        LOG;

    public function test_parse_extracts_fields_and_orders_newest_first(): void
    {
        $entries = LogReader::parse(self::SAMPLE);

        $this->assertCount(3, $entries);

        // Newest first: the 13:00 WARNING leads, the 12:00 INFO is last.
        $this->assertSame('2026-06-19 13:00:00', $entries[0]['timestamp']);
        $this->assertSame('WARNING', $entries[0]['level']);
        $this->assertSame('2026-06-19 12:00:00', $entries[2]['timestamp']);

        $error = $entries[1];
        $this->assertSame('ERROR', $error['level']);
        $this->assertSame('production', $error['env']);
        // Message is the first line minus the trailing {json} context.
        $this->assertStringContainsString('fillForm does not exist', $error['message']);
        $this->assertStringNotContainsString('{"exception"', $error['message']);
        // The stack trace frames stay with the entry's stack block.
        $this->assertStringContainsString('#0 /var/www/app/Http/Kernel.php', $error['stack']);
        $this->assertStringContainsString('#1 /var/www/public/index.php', $error['stack']);
    }

    public function test_parse_returns_empty_for_unrecognised_text(): void
    {
        $this->assertSame([], LogReader::parse(''));
        $this->assertSame([], LogReader::parse("just some\nplain text\n"));
    }

    public function test_error_levels_constant_excludes_info_and_warning(): void
    {
        $this->assertContains('ERROR', LogReader::ERROR_LEVELS);
        $this->assertContains('CRITICAL', LogReader::ERROR_LEVELS);
        $this->assertNotContains('INFO', LogReader::ERROR_LEVELS);
        $this->assertNotContains('WARNING', LogReader::ERROR_LEVELS);
    }

    public function test_tail_reads_only_the_last_bytes(): void
    {
        // 1000 'A's then a 9-byte A-free marker; tail(9) must read just the marker,
        // never the leading kilobyte — proving it seeks to the tail, not a full slurp.
        $contents = str_repeat('A', 1000).'<<<END>>>';
        $name = $this->writeLog($contents);
        $path = LogReader::logDir().'/'.$name;

        $tail = LogReader::tail($path, 9);

        $this->assertSame(9, strlen($tail));
        $this->assertSame('<<<END>>>', $tail);
        $this->assertStringNotContainsString('A', $tail);
    }

    public function test_available_files_lists_log_basenames(): void
    {
        $name = $this->writeLog("[2026-06-19 12:00:00] production.ERROR: hi\n");

        $this->assertContains($name, LogReader::availableFiles());
    }

    public function test_resolve_accepts_a_real_log_file(): void
    {
        $name = $this->writeLog("[2026-06-19 12:00:00] production.ERROR: hi\n");

        $resolved = LogReader::resolve($name);

        $this->assertNotNull($resolved);
        $this->assertSame(LogReader::logDir().'/'.$name, $resolved);
    }

    public function test_resolve_blocks_path_traversal_and_unknown_files(): void
    {
        // None of these resolve — they are not whitelisted *.log basenames.
        $this->assertNull(LogReader::resolve('../.env'));
        $this->assertNull(LogReader::resolve('../../.env'));
        $this->assertNull(LogReader::resolve('/etc/passwd'));
        $this->assertNull(LogReader::resolve('laravel.log/../../../etc/passwd'));
        $this->assertNull(LogReader::resolve('does-not-exist.log'));
        $this->assertNull(LogReader::resolve(null));
        $this->assertNull(LogReader::resolve(''));
    }

    public function test_entries_reads_and_parses_a_real_file(): void
    {
        $name = $this->writeLog(self::SAMPLE);
        $path = LogReader::resolve($name);

        $entries = LogReader::entries($path);

        $this->assertCount(3, $entries);
        $this->assertSame('WARNING', $entries[0]['level']);
    }
}
