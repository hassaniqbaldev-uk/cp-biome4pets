<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Report extends Model
{
    protected $fillable = [
        'client_id',
        'pet_id',
        'test_id',
        'sample_id',
        'report_date',
        'csv_path',
        'csv_data',
        'phylum_data',
        'diversity_score',
        'ai_summary',
        'ai_bacteroidetes_interpretation',
        'ai_firmicutes_interpretation',
        'ai_fusobacteria_interpretation',
        'ai_proteobacteria_interpretation',
        'ai_diversity_interpretation',
        'vet_notes',
        'status',
        'slug',
        'species_richness',
        'dysbiosis_score',
        'microbiome_classification',
        'score_gut_wall',
        'score_skin_allergy',
        'score_behaviour_mood',
        'score_gut_barrier',
        'score_gas_digestive',
        'score_stress_resilience',
        'vet_summary',
        'goal',
        'recommended_actions',
        'plan_id',
        'plan_intro',
        'subscription_snapshot',
        'pet_snapshot',
        'klaviyo_last_sent_at',
        'klaviyo_last_result',
    ];

    protected $casts = [
        'csv_data' => 'array',
        'phylum_data' => 'array',
        'diversity_score' => 'float',
        'dysbiosis_score' => 'float',
        'species_richness' => 'integer',
        'subscription_snapshot' => 'array',
        'pet_snapshot' => 'array',
        'klaviyo_last_sent_at' => 'datetime',
        'klaviyo_last_result' => 'array',
    ];

    /**
     * The pet fields frozen into pet_snapshot at generation time. Phase 1 of the
     * pet-health-history feature: the Pet model stays the living source of truth;
     * the report just keeps a copy of these as-generated.
     */
    public const PET_SNAPSHOT_FIELDS = ['name', 'breed', 'diet', 'sex', 'date_of_birth', 'health_notes'];

    /**
     * Build the frozen pet snapshot from a Pet (or null when there is no pet).
     * Called at report creation and whenever the AI/CSV snapshot is regenerated,
     * so the frozen pet always matches the copy that was generated from it.
     */
    public static function buildPetSnapshot(?Pet $pet): ?array
    {
        if (! $pet) {
            return null;
        }

        return [
            'name' => $pet->name,
            'breed' => $pet->breed,
            'diet' => $pet->diet,
            'sex' => $pet->sex,
            // Cast Carbon -> 'Y-m-d' string so the JSON is stable and portable.
            'date_of_birth' => $pet->date_of_birth?->toDateString(),
            'health_notes' => $pet->health_notes,
        ];
    }

    /**
     * Resolve a single pet field for display, preferring the frozen snapshot and
     * falling back to the LIVE Pet only when this report has no snapshot (old /
     * sample reports created before this feature). When a snapshot exists it is
     * authoritative — even a null value within it is the frozen truth.
     */
    public function petField(string $key, mixed $default = null): mixed
    {
        if (is_array($this->pet_snapshot)) {
            return $this->pet_snapshot[$key] ?? $default;
        }

        return $this->pet?->{$key} ?? $default;
    }

    protected static function booted(): void
    {
        static::creating(function (Report $report) {
            if (empty($report->slug)) {
                $petName = $report->pet?->name ?? optional(Pet::find($report->pet_id))->name;
                $base = Str::slug($petName . '-' . $report->sample_id);
                $slug = $base;
                $counter = 1;

                while (static::where('slug', $slug)->exists()) {
                    $slug = $base . '-' . $counter;
                    $counter++;
                }

                $report->slug = $slug;
            }
        });
    }

    public function getReportUrlAttribute(): string
    {
        return url('/report/' . $this->slug);
    }

    /**
     * Persist the outcome of a manual Klaviyo send for THIS report. Called only
     * from the "Send Report" admin action — never automatically.
     */
    public function recordKlaviyoSend(bool $ok, string $message): void
    {
        $this->update([
            'klaviyo_last_sent_at' => now(),
            'klaviyo_last_result' => ['ok' => $ok, 'message' => $message],
        ]);
    }

    /**
     * Human-readable "last sent to Klaviyo" line for the admin report view, or
     * "Not yet sent" when this report has never been sent.
     */
    public function klaviyoLastSentSummary(): string
    {
        if (! $this->klaviyo_last_sent_at) {
            return 'Not yet sent';
        }

        $result = $this->klaviyo_last_result ?? [];
        $status = ! empty($result['ok']) ? 'OK' : 'Failed';
        $message = $result['message'] ?? '';

        return $this->klaviyo_last_sent_at->format('M j, Y g:ia')
            . ' — ' . $status
            . ($message !== '' ? ': ' . $message : '');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class);
    }

    public function test()
    {
        return $this->belongsTo(Test::class);
    }

    /**
     * Raw lab fields that a report transparently reads from its Test when the
     * report's own column is null. Phase 3a keeps the report columns (dual-write),
     * so this is a no-op for freshly generated reports; once the columns are
     * dropped, every read resolves to the Test instead — with no caller changes.
     */
    public const TEST_PROXY_FIELDS = [
        'phylum_data', 'diversity_score', 'species_richness', 'dysbiosis_score',
        'microbiome_classification', 'csv_data', 'csv_path', 'sample_id', 'report_date',
    ];

    /**
     * Transparent Report→Test proxy. We override getAttribute (rather than adding
     * get{Field}Attribute accessors) for two reasons:
     *   1. A same-named accessor would RECEIVE the raw, un-cast value and bypass
     *      the existing array/float/int casts — here parent::getAttribute() still
     *      applies those casts for the present-value case.
     *   2. It never shadows a real column or interferes with $fillable / mass
     *      assignment — writes still go through setAttribute unchanged.
     * The fallback fires ONLY when the report's own value is null, so a genuine
     * stored value (incl. 0 or []) always wins.
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if ($value === null && in_array($key, self::TEST_PROXY_FIELDS, true)) {
            return $this->test?->getAttribute($key);
        }

        return $value;
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The owning client reached through the pet. Falls back to the directly
     * stored client_id when the pet relationship is not yet set.
     */
    public function getPetClientAttribute(): ?Client
    {
        return $this->pet?->client ?? $this->client;
    }

    public function catalogProducts(): BelongsToMany
    {
        return $this->belongsToMany(CatalogProduct::class, 'catalog_product_report')
            ->withPivot('position')
            ->withTimestamps()
            ->orderBy('position');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ReportStep::class)->orderBy('position');
    }
}
