<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Report extends Model
{
    use SoftDeletes;

    /**
     * Length of a newly-generated public_token. 16 alphanumeric chars ≈ 95 bits of
     * entropy (62^16) — unguessable, while shorter/friendlier than the original 40.
     * The column is VARCHAR(40), so legacy 40-char tokens still fit and resolve.
     */
    public const PUBLIC_TOKEN_LENGTH = 16;

    protected $fillable = [
        'client_id',
        'pet_id',
        'test_id',
        'ai_summary',
        'ai_bacteroidetes_interpretation',
        'ai_firmicutes_interpretation',
        'ai_fusobacteria_interpretation',
        'ai_proteobacteria_interpretation',
        'ai_diversity_interpretation',
        'vet_notes',
        'status',
        'slug',
        'public_token',
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
        'hide_subscribe',
        'pet_snapshot',
        'klaviyo_last_sent_at',
        'klaviyo_last_result',
        'app_last_sent_at',
        'app_last_result',
        // Phase 3 quality flags.
        'needs_review',
        'review_flags',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        // Raw lab fields (csv_data/phylum_data/diversity_score/dysbiosis_score/
        // species_richness) now live on the Test and are cast there; the
        // Report→Test proxy returns the already-cast Test value.
        'subscription_snapshot' => 'array',
        'hide_subscribe' => 'boolean',
        'pet_snapshot' => 'array',
        'klaviyo_last_sent_at' => 'datetime',
        'klaviyo_last_result' => 'array',
        'app_last_sent_at' => 'datetime',
        'app_last_result' => 'array',
        // Phase 3: the visible flag is a bool; review_flags is the recorded verdict.
        'needs_review' => 'boolean',
        'review_flags' => 'array',
        'reviewed_at' => 'datetime',
    ];

    /**
     * The recorded quality issues (all tiers), as stored by the generation paths.
     *
     * @return array<int,array{code:string,severity:string,tier:string,detail:string}>
     */
    public function reviewIssues(): array
    {
        return $this->review_flags['issues'] ?? [];
    }

    /**
     * Deterministic issues only — these are what drove needs_review and what the
     * edit-page banner surfaces prominently.
     */
    public function deterministicReviewIssues(): array
    {
        return array_values(array_filter(
            $this->reviewIssues(),
            fn (array $i): bool => ($i['tier'] ?? null) === 'deterministic',
        ));
    }

    /**
     * Heuristic issues only — recorded for the record but log-only/informational;
     * they never count toward needs_review and are shown only in a clearly
     * separated "unverified" subsection.
     */
    public function heuristicReviewIssues(): array
    {
        return array_values(array_filter(
            $this->reviewIssues(),
            fn (array $i): bool => ($i['tier'] ?? null) === 'heuristic',
        ));
    }

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
    public static function buildPetSnapshot(?Pet $pet, Carbon|string|null $asOf = null): ?array
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
            // Part 2: freeze the pet's health-notes history AS OF the report date
            // (all entries up to and including it). Same snapshot key as before;
            // only the value's source (the log) and format (dated history) changed.
            'health_notes' => $pet->healthNotesForContext($asOf),
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

    /**
     * Whether the report should surface the "speak to a nutritionist" CTA. Driven
     * by the pet's diet on the FROZEN snapshot (via petField), so it reflects the
     * report's data as generated, not a later edit to the live pet. Kibble only
     * for now (Mixed may be added later). One shared accessor so the web and PDF
     * templates stay in lockstep.
     */
    public function recommendsNutritionist(): bool
    {
        return $this->petField('diet') === 'Kibble';
    }

    protected static function booted(): void
    {
        static::creating(function (Report $report) {
            if (empty($report->slug)) {
                $petName = $report->pet?->name ?? optional(Pet::find($report->pet_id))->name;
                $base = Str::slug($petName.'-'.$report->sample_id);
                $slug = $base;
                $counter = 1;

                while (static::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$counter;
                    $counter++;
                }

                $report->slug = $slug;
            }

            // High-entropy public URL key — the report is served at
            // /report/{public_token}, NOT the guessable slug, so URLs can't be
            // enumerated even when the pet name / sample id are known.
            //
            // 16 alphanumeric chars (Str::random draws from [A-Za-z0-9]) is 62^16
            // ≈ 4.8e28 ≈ 95 bits of entropy — completely infeasible to guess, just
            // friendlier in the URL than the original 40. The VARCHAR(40) column
            // still holds legacy 40-char tokens, which keep resolving unchanged.
            if (empty($report->public_token)) {
                do {
                    $token = Str::random(self::PUBLIC_TOKEN_LENGTH);
                } while (static::where('public_token', $token)->exists());

                $report->public_token = $token;
            }
        });
    }

    public function getReportUrlAttribute(): string
    {
        return url('/report/'.$this->public_token);
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
            .' — '.$status
            .($message !== '' ? ': '.$message : '');
    }

    /**
     * Persist the outcome of a manual "Send via App" (direct SMTP) send. Mirrors
     * recordKlaviyoSend; called only from the admin action, never automatically.
     */
    public function recordAppSend(bool $ok, string $message): void
    {
        $this->update([
            'app_last_sent_at' => now(),
            'app_last_result' => ['ok' => $ok, 'message' => $message],
        ]);
    }

    /** Human-readable "last emailed via the app" line, mirroring the Klaviyo one. */
    public function appLastSentSummary(): string
    {
        if (! $this->app_last_sent_at) {
            return 'Not yet sent';
        }

        $result = $this->app_last_result ?? [];
        $status = ! empty($result['ok']) ? 'OK' : 'Failed';
        $message = $result['message'] ?? '';

        return $this->app_last_sent_at->format('M j, Y g:ia')
            .' — '.$status
            .($message !== '' ? ': '.$message : '');
    }

    /**
     * Has this report been successfully delivered to the customer via EITHER
     * channel — Klaviyo or the App (direct SMTP)? A send is only counted when its
     * recorded result is OK; a failed attempt still stamps the *_last_sent_at
     * timestamp but must NOT read as "sent".
     */
    public function hasBeenSent(): bool
    {
        $klaviyoOk = $this->klaviyo_last_sent_at !== null && ! empty($this->klaviyo_last_result['ok']);
        $appOk = $this->app_last_sent_at !== null && ! empty($this->app_last_result['ok']);

        return $klaviyoOk || $appOk;
    }

    /**
     * The status shown to admins: Draft → Published → Sent. "Sent" is DERIVED from
     * the send timestamps (never stored), so it can't drift from reality — a report
     * can only be sent once published, so a successful send supersedes "Published"
     * in the single status column. Returns 'draft' | 'published' | 'sent'.
     */
    public function displayStatus(): string
    {
        if ($this->hasBeenSent()) {
            return 'sent';
        }

        return $this->status === 'published' ? 'published' : 'draft';
    }

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function pet()
    {
        return $this->belongsTo(Pet::class)->withTrashed();
    }

    public function test()
    {
        // withTrashed is essential: the Report→Test proxy (getAttribute below) reads
        // its raw lab data from the linked Test. A soft-deleted Test must still
        // resolve so a recoverable report keeps showing its data, and the public
        // report doesn't break just because the test was (accidentally) deleted.
        return $this->belongsTo(Test::class)->withTrashed();
    }

    /**
     * Raw lab fields a report transparently reads from its Test. The report's own
     * columns were dropped in Phase 3d, so parent::getAttribute() always returns
     * null for these and every read resolves to the linked Test — no caller
     * changes. (A report with no test resolves to null, as before.)
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
