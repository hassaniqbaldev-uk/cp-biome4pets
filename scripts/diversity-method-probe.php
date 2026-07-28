<?php

/**
 * THROWAWAY diagnostic — reverse-engineer BaseSpace's Shannon diversity method.
 * Computes H under many candidate methods for each probe CSV and tables them
 * against the known BaseSpace/Carol figures. Reads CSVs only; changes nothing.
 *
 *   php scripts/diversity-method-probe.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\CsvParserService;

const DIR = __DIR__.'/../storage/app/diversity-probe';

/** sample => [our value, known target|null] (target = BaseSpace / Carol-correct). */
$SAMPLES = [
    '4281' => ['ours' => 3.09, 'target' => 2.30],
    '3837' => ['ours' => 2.12, 'target' => 1.80],
    '4209' => ['ours' => 2.21, 'target' => 2.21],
    '4050' => ['ours' => 2.67, 'target' => 2.67],
    '4366' => ['ours' => 2.94, 'target' => null],
    '4339' => ['ours' => 2.08, 'target' => null],
];

/** Renormalised Shannon H = -Σ p·ln(p), p = value/Σvalue. base=2 → log2. */
function shannon(array $values, float $base = M_E): float
{
    $vals = array_values(array_filter($values, fn ($v) => $v > 0));
    $total = array_sum($vals);
    if ($total <= 0) {
        return 0.0;
    }
    $h = 0.0;
    foreach ($vals as $v) {
        $p = $v / $total;
        $h -= $p * ($base === M_E ? log($p) : log($p, $base));
    }

    return $h;
}

/**
 * Pick a consistent count source for one computation: num_hits when EVERY bin has
 * a usable (>0) count, else the %_hits fallback — never mixed within a computation.
 *
 * @param  array<int,array{num:float,pct:float}>  $bins
 * @return array{0:array<int,float>,1:string}  [values, unit]
 */
function values(array $bins): array
{
    $nums = array_map(fn ($b) => $b['num'], $bins);
    $allCounts = $bins !== [] && count(array_filter($nums, fn ($n) => $n > 0)) === count($bins);

    return $allCounts
        ? [$nums, 'num_hits']
        : [array_map(fn ($b) => $b['pct'], $bins), '%_hits'];
}

/** Aggregate bins by a taxon key: sum num + pct for rows where the key is non-empty. */
function groupSum(array $rows, string $key): array
{
    $bins = [];
    foreach ($rows as $r) {
        if ($r[$key] === '') {
            continue;
        }
        $bins[$r[$key]] ??= ['num' => 0.0, 'pct' => 0.0];
        $bins[$r[$key]]['num'] += $r['num'];
        $bins[$r[$key]]['pct'] += $r['pct'];
    }

    return array_values($bins);
}

/** Read one probe CSV into normalised row records. */
function readRows(string $path): array
{
    $h = fopen($path, 'r');
    $header = array_map('trim', fgetcsv($h));
    $rows = [];
    while (($row = fgetcsv($h)) !== false) {
        if (count($row) !== count($header)) {
            continue;
        }
        $d = array_combine($header, array_map('trim', $row));
        $rows[] = [
            'phylum' => $d['Phylum'] ?? '',
            'family' => $d['Family'] ?? '',
            'genus' => $d['Genus'] ?? '',
            'species' => $d['Species'] ?? '',
            'num' => (float) ($d['num_hits'] ?? 0),
            'pct' => (float) ($d['%_hits'] ?? 0),
        ];
    }
    fclose($h);

    return $rows;
}

$svc = new CsvParserService();
$results = [];
$stats = [];

foreach ($SAMPLES as $id => $meta) {
    $path = DIR."/test-{$id}.csv";
    $rows = readRows($path);

    // Inclusion rule mirrors CsvParserService: Species non-blank AND %_hits > 0.
    $speciesRows = array_values(array_filter($rows, fn ($r) => $r['species'] !== '' && $r['pct'] > 0));
    $unresolvedRows = array_values(array_filter($rows, fn ($r) => $r['species'] === ''));

    // ---- A: real service (true baseline) + which unit path it used --------
    $parsed = $svc->parse($path);
    $speciesNums = array_map(fn ($r) => $r['num'], $speciesRows);
    $countsUsable = $speciesRows !== [] && count(array_filter($speciesNums, fn ($n) => $n > 0)) === count($speciesRows);
    $A = (float) $parsed['diversity_score'];
    $Apath = $countsUsable ? 'num_hits' : '%_hits';

    // ---- B: species rows, renormalised -----------------------------------
    [$bVals] = values(array_map(fn ($r) => ['num' => $r['num'], 'pct' => $r['pct']], $speciesRows));
    $B = shannon($bVals);

    // ---- C: species rows, p = %_hits/100, NO renormalisation (old bug) ----
    $C = 0.0;
    foreach ($speciesRows as $r) {
        $p = $r['pct'] / 100;
        if ($p > 0) {
            $C -= $p * log($p);
        }
    }

    // ---- D: genus over ALL rows with a Genus (incl. blank-species), renorm-
    [$dVals] = values(groupSum($rows, 'genus'));
    $D = shannon($dVals);

    // ---- E: genus over species-resolved rows only, renorm -----------------
    [$eVals] = values(groupSum($speciesRows, 'genus'));
    $E = shannon($eVals);

    // ---- F: family over ALL rows with a Family, renorm --------------------
    [$fVals] = values(groupSum($rows, 'family'));
    $F = shannon($fVals);

    // ---- G: every row as its own bin, renorm ------------------------------
    [$gVals] = values(array_map(fn ($r) => ['num' => $r['num'], 'pct' => $r['pct']], $rows));
    $G = shannon($gVals);

    // ---- H: species rows + ALL unresolved reads pooled into ONE extra bin -
    $hBins = array_map(fn ($r) => ['num' => $r['num'], 'pct' => $r['pct']], $speciesRows);
    $pooledNum = array_sum(array_map(fn ($r) => $r['num'], $unresolvedRows));
    $pooledPct = array_sum(array_map(fn ($r) => $r['pct'], $unresolvedRows));
    if ($pooledNum > 0 || $pooledPct > 0) {
        $hBins[] = ['num' => $pooledNum, 'pct' => $pooledPct];
    }
    [$hVals] = values($hBins);
    $H = shannon($hVals);

    // ---- I: method B but log2 (completeness check) ------------------------
    $I = shannon($bVals, 2.0);

    $results[$id] = compact('A', 'Apath', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I');

    // ---- characterisation stats ------------------------------------------
    $genusSpeciesCount = [];   // genus => # of species-resolved rows
    foreach ($speciesRows as $r) {
        if ($r['genus'] !== '') {
            $genusSpeciesCount[$r['genus']] = ($genusSpeciesCount[$r['genus']] ?? 0) + 1;
        }
    }
    $totalNum = array_sum(array_map(fn ($r) => $r['num'], $rows));
    $speciesNumSum = array_sum($speciesNums);
    $stats[$id] = [
        'rows' => count($rows),
        'species_rows' => count($speciesRows),
        'genus_blank_species' => count(array_filter($rows, fn ($r) => $r['genus'] !== '' && $r['species'] === '')),
        'species_frac_of_reads' => $totalNum > 0 ? $speciesNumSum / $totalNum : 0,
        'distinct_genera' => count(array_unique(array_filter(array_map(fn ($r) => $r['genus'], $rows), fn ($g) => $g !== ''))),
        'genera_multi_species' => count(array_filter($genusSpeciesCount, fn ($c) => $c > 1)),
        'sum_pct_all' => array_sum(array_map(fn ($r) => $r['pct'], $rows)),
        'sum_pct_species' => array_sum(array_map(fn ($r) => $r['pct'], $speciesRows)),
    ];
}

// ============================ OUTPUT ============================

$methods = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
$legend = [
    'A' => 'CURRENT (real CsvParserService)',
    'B' => 'species rows, renormalised',
    'C' => 'species rows, %/100, NO renorm (old bug)',
    'D' => 'genus over ALL rows w/ genus, renorm',
    'E' => 'genus over species rows only, renorm',
    'F' => 'family over ALL rows, renorm',
    'G' => 'every row = one bin, renorm',
    'H' => 'species rows + unresolved pooled (1 bin)',
    'I' => 'method B but log2',
];

echo "\n=== CHARACTERISATION (why some match, some don't) ===\n\n";
printf("%-6s %6s %8s %10s %11s %9s %11s %9s %11s\n",
    'sample', 'rows', 'sppRows', 'genusOnly', 'spp%ofReads', 'genera', 'multiSppGen', 'ΣpctAll', 'Σpctspp');
foreach ($stats as $id => $s) {
    printf("%-6s %6d %8d %10d %10.1f%% %9d %11d %9.1f %11.1f\n",
        $id, $s['rows'], $s['species_rows'], $s['genus_blank_species'],
        $s['species_frac_of_reads'] * 100, $s['distinct_genera'], $s['genera_multi_species'],
        $s['sum_pct_all'], $s['sum_pct_species']);
}

echo "\n=== METHOD LEGEND ===\n";
foreach ($legend as $k => $v) {
    echo "  {$k} — {$v}\n";
}

echo "\n=== SAMPLES × METHODS  (H values; 'ours'/'target' from the brief) ===\n\n";
printf("%-6s %6s %7s | ", 'sample', 'ours', 'target');
foreach ($methods as $m) {
    printf('%7s ', $m);
}
echo "  (A path)\n";
echo str_repeat('-', 100)."\n";
foreach ($SAMPLES as $id => $meta) {
    $r = $results[$id];
    printf('%-6s %6.2f %7s | ', $id, $meta['ours'], $meta['target'] === null ? '—' : number_format($meta['target'], 2));
    foreach ($methods as $m) {
        printf('%7.3f ', $r[$m]);
    }
    printf("  %s\n", $r['Apath']);
}

echo "\n=== ABSOLUTE DELTA vs TARGET (known samples only; lower = closer) ===\n\n";
printf("%-6s %7s | ", 'sample', 'target');
foreach ($methods as $m) {
    printf('%7s ', $m);
}
echo "\n".str_repeat('-', 100)."\n";
$sumDelta = array_fill_keys($methods, 0.0);
$maxDelta = array_fill_keys($methods, 0.0);
$known = 0;
foreach ($SAMPLES as $id => $meta) {
    if ($meta['target'] === null) {
        continue;
    }
    $known++;
    $r = $results[$id];
    printf('%-6s %7.2f | ', $id, $meta['target']);
    foreach ($methods as $m) {
        $d = abs($r[$m] - $meta['target']);
        $sumDelta[$m] += $d;
        $maxDelta[$m] = max($maxDelta[$m], $d);
        printf('%7.3f ', $d);
    }
    echo "\n";
}
echo str_repeat('-', 100)."\n";
printf('%-6s %7s | ', 'Σ|Δ|', '');
foreach ($methods as $m) {
    printf('%7.3f ', $sumDelta[$m]);
}
echo "\n";
printf('%-6s %7s | ', 'max|Δ|', '');
foreach ($methods as $m) {
    printf('%7.3f ', $maxDelta[$m]);
}
echo "\n";

// Verdict: a method "reproduces" all targets if max|Δ| over known samples ≤ 0.05
// (rounding tolerance — service rounds to 2 dp; targets are 2 dp).
echo "\n=== VERDICT (max|Δ| ≤ 0.05 across all ".$known." known samples) ===\n\n";
$winners = array_filter($methods, fn ($m) => $maxDelta[$m] <= 0.05);
if ($winners !== []) {
    foreach ($winners as $m) {
        printf("  ✓ Method %s reproduces all targets (max|Δ| = %.3f) — %s\n", $m, $maxDelta[$m], $legend[$m]);
    }
} else {
    echo "  ✗ No method reproduces all four targets within 0.05.\n";
    $best = null;
    foreach ($methods as $m) {
        if ($best === null || $maxDelta[$m] < $maxDelta[$best]) {
            $best = $m;
        }
    }
    printf("  Closest by worst-case: Method %s (max|Δ| = %.3f, Σ|Δ| = %.3f) — %s\n",
        $best, $maxDelta[$best], $sumDelta[$best], $legend[$best]);
}
echo "\n";
