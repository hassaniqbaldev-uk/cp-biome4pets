<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Test = one sample analysis: the raw CSV / lab-derived data for a pet.
 * Reports are the INTERPRETATION of a Test (Report belongsTo Test). The raw lab
 * fields live HERE; AI copy, scores, plan and snapshots stay on the Report.
 */
class Test extends Model
{
    protected $fillable = [
        'pet_id',
        'client_id',
        'order_id',
        'sample_id',
        'report_date',
        'collected_at',
        'csv_path',
        'csv_data',
        'phylum_data',
        'diversity_score',
        'species_richness',
        'dysbiosis_score',
        'microbiome_classification',
        'status',
        'external_ids',
    ];

    protected $casts = [
        'report_date' => 'date',
        'collected_at' => 'date',
        'csv_data' => 'array',
        'phylum_data' => 'array',
        'diversity_score' => 'float',
        'dysbiosis_score' => 'float',
        'species_richness' => 'integer',
        'external_ids' => 'array',
    ];

    /**
     * Lifecycle statuses. Room to grow (ordered/kit_sent/at_lab) later; for now a
     * manually-created test sits at results_received until a report is generated.
     */
    public const STATUSES = [
        'results_received' => 'Results received',
        'report_generated' => 'Report generated',
    ];

    /**
     * The raw lab fields a report stores/snapshots and that the Report proxies
     * read back through. (sample_id/report_date are sample attributes too.)
     */
    public const RAW_LAB_FIELDS = [
        'csv_path', 'csv_data', 'phylum_data', 'diversity_score',
        'species_richness', 'dysbiosis_score', 'microbiome_classification',
        'sample_id', 'report_date',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Find-or-create the Test for a report's (pet_id + order_id) and store the
     * raw lab data on it. order_id == the report's sample_id today. Reused by
     * BOTH the create flow and the EditReport regenerate flow so there is one
     * code path that owns the raw data. Existing fields are preserved when a key
     * is absent (a reuse never wipes data it wasn't given).
     *
     * Expects keys: pet_id, client_id, sample_id, report_date, csv_path,
     * csv_data, phylum_data, diversity_score, species_richness, dysbiosis_score,
     * microbiome_classification. Returns the persisted Test.
     */
    public static function syncRawForReport(array $data): self
    {
        $test = static::firstOrNew([
            'pet_id' => $data['pet_id'] ?? null,
            'order_id' => $data['sample_id'] ?? null,
        ]);

        $keep = fn (string $key, $current) => array_key_exists($key, $data) && $data[$key] !== null
            ? $data[$key]
            : $current;

        $test->fill([
            'client_id' => $keep('client_id', $test->client_id),
            'sample_id' => $data['sample_id'] ?? $test->sample_id,
            'report_date' => $keep('report_date', $test->report_date),
            'csv_path' => $keep('csv_path', $test->csv_path),
            'csv_data' => $keep('csv_data', $test->csv_data),
            'phylum_data' => $keep('phylum_data', $test->phylum_data),
            'diversity_score' => $keep('diversity_score', $test->diversity_score),
            'species_richness' => $keep('species_richness', $test->species_richness),
            'dysbiosis_score' => $keep('dysbiosis_score', $test->dysbiosis_score),
            'microbiome_classification' => $keep('microbiome_classification', $test->microbiome_classification),
            'status' => $test->status ?: 'report_generated',
        ]);

        $test->save();

        return $test;
    }
}
