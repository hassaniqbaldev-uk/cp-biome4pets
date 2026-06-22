<?php

namespace App\Models;

use App\Support\AdminFormatting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A Test = one sample analysis: the raw CSV / lab-derived data for a pet.
 * Reports are the INTERPRETATION of a Test (Report belongsTo Test). The raw lab
 * fields live HERE; AI copy, scores, plan and snapshots stay on the Report.
 */
class Test extends Model
{
    use SoftDeletes;

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
     * The raw lab fields a report stores/snapshots and that the Report proxies
     * read back through. (sample_id/report_date are sample attributes too.)
     */
    public const RAW_LAB_FIELDS = [
        'csv_path', 'csv_data', 'phylum_data', 'diversity_score',
        'species_richness', 'dysbiosis_score', 'microbiome_classification',
        'sample_id', 'report_date',
    ];

    protected static function booted(): void
    {
        // No orphaned PII: delete the private lab CSV when its Test is permanently
        // removed. forceDeleted (NOT deleting) so a SOFT delete keeps the CSV — the
        // test can be restored with its data intact; only a real force-delete wipes
        // the file. (With SoftDeletes, `deleting` fires on soft deletes too, which
        // would destroy a recoverable record's CSV — hence forceDeleted.)
        static::forceDeleted(function (Test $test): void {
            if (filled($test->csv_path) && Storage::disk('local')->exists($test->csv_path)) {
                Storage::disk('local')->delete($test->csv_path);
            }
        });
    }

    public function pet(): BelongsTo
    {
        // withTrashed so a test still resolves its pet/client for display even when
        // the parent was soft-deleted (we don't cascade soft deletes).
        return $this->belongsTo(Pet::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Derived state replacing the old stored status: a test either has a report
     * or doesn't. Uses the loaded relation / withCount when available so tables
     * don't N+1; falls back to an exists() query for a single record.
     */
    public function hasReport(): bool
    {
        if ($this->relationLoaded('reports')) {
            return $this->reports->isNotEmpty();
        }

        if (array_key_exists('reports_count', $this->attributes)) {
            return (int) $this->attributes['reports_count'] > 0;
        }

        return $this->reports()->exists();
    }

    /** Human label for the derived state: "Reported" vs "Awaiting report". */
    public function stateLabel(): string
    {
        return AdminFormatting::testStateLabel($this->hasReport());
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
        ]);

        $test->save();

        return $test;
    }
}
