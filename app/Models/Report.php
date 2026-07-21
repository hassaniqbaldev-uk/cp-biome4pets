<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
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
        // NB: 'vet_summary' is a MISNOMER — despite the name it is NOT vet-facing. It
        // is the DETAIL paragraph of the owner-facing personal summary, rendered
        // directly beneath 'ai_summary' under the "Your Dog's Personal Summary"
        // heading (see report/show.blade.php). Its AI prompt explicitly asks for warm,
        // jargon-free owner copy. (The static "Veterinary Summary" section on the
        // report is separate boilerplate and does NOT render this field.) The column
        // name is kept to avoid a migration; see OpenAiService's prompt for its job.
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
        return $this->petField('diet') === self::NUTRITIONIST_DIET;
    }

    /** The exact diet value that triggers the nutritionist copy (the pet form's enum). */
    public const NUTRITIONIST_DIET = 'Kibble';

    /**
     * Whether to show the nutritionist DIET REVIEW recommendation in place of the
     * generic nutritionist nudge. The client's rule: the pet is KIBBLE fed AND its
     * microbiome classification is "Imbalanced" OR "Imbalanced & Depleted".
     *
     * BOTH must hold — kibble + Stable, or non-kibble + Imbalanced, keep the existing
     * copy. Classification matching is delegated to
     * ReportContent::isUnwellClassification(), which does a STRICT in_array against
     * the two exact strings: "Imbalanced" and "Imbalanced & Depleted" share a prefix,
     * so a substring test would be fragile (we hit that trap in the PDF classification
     * bug). "Stable", null and any unknown value are NOT unwell, so a missing or
     * unrecognised classification safely falls back to the existing copy — as does a
     * missing diet. Diet reads the FROZEN snapshot (petField), matching the rest of
     * the report. One shared accessor so the web and PDF templates stay in lockstep.
     */
    public function recommendsDietReview(): bool
    {
        return $this->recommendsNutritionist()
            && \App\Support\ReportContent::isUnwellClassification($this->microbiome_classification);
    }

    protected static function booted(): void
    {
        static::creating(function (Report $report) {
            if (empty($report->slug)) {
                $petName = $report->pet?->name ?? optional(Pet::find($report->pet_id))->name;
                // NB: the slug is "{pet}-{sample_id}", so for a pet whose name and
                // sample id coincide (e.g. pet "PP1A", sample "PP1A") it reads
                // "pp1a-pp1a". That is not a duplicated pet name — the two halves just
                // happen to match for that pet — and it is only for admin display
                // (the public URL uses public_token). Uniqueness is what matters here.
                $report->slug = static::uniqueSlug(Str::slug($petName.'-'.$report->sample_id));
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

    /**
     * A slug that does not collide with the reports_slug_unique index.
     *
     * CRITICAL: the check runs withTrashed(). reports.slug is UNIQUE at the DB level
     * and that index INCLUDES soft-deleted rows, but the default query scope hides
     * them — so the old check (static::where('slug', ...)) could report "free" for a
     * slug still held by a trashed report, and the insert then hit a duplicate-key
     * 500 ("Duplicate entry 'pp1a-pp1a' for key 'reports_slug_unique'"). Checking ALL
     * rows fixes that. Appends -2, -3, … until free; falls back to "report" when the
     * base slugifies to empty (e.g. a pet with only non-latin characters).
     */
    protected static function uniqueSlug(string $base): string
    {
        $base = $base !== '' ? $base : 'report';
        $slug = $base;
        $counter = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $counter++;
            $slug = $base.'-'.$counter;
        }

        return $slug;
    }

    /**
     * Race-safe insert. The uniqueSlug() pre-check closes the soft-delete hole, but a
     * check-then-insert is still a race: two reports created at the same instant can
     * pick the same slug and one hits the unique index. Rather than 500, catch a slug
     * uniqueness violation and retry with a fresh, randomly-suffixed slug (bounded).
     * Centralised here so EVERY creation path — the wizard, ReportGeneration, tests —
     * is protected without touching call sites.
     */
    protected function performInsert(Builder $query)
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::performInsert($query);
            } catch (QueryException $e) {
                if ($attempt >= 5 || ! $this->isSlugUniqueViolation($e)) {
                    throw $e;
                }
                // Regenerate from the current slug plus a short random token, so the
                // retry can't pick the same value the race just lost on.
                $this->slug = static::uniqueSlug(Str::slug($this->slug.'-'.Str::lower(Str::random(4))));
            }
        }
    }

    /** Whether a query exception is a uniqueness violation on the slug index
     *  (matches MySQL's "reports_slug_unique" and SQLite's "reports.slug"). */
    protected function isSlugUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'reports_slug_unique')
            || str_contains($message, 'reports.slug');
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
        return $this->klaviyoHasBeenSent() || $this->appHasBeenSent();
    }

    /**
     * Is this report publicly viewable? The single source of truth for the public
     * publish-gate: the status column is 'published'. (A "sent" report keeps
     * status 'published' — "sent" is a derived display state, not a stored status —
     * so it stays viewable.) Drafts and unpublished reports are NOT public.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * The checkout/subscribe URL to send THIS report's customer to. The single
     * source of truth for the public redirect: the variant-or-base url FROZEN into
     * the report at apply-plan time (subscription_snapshot['url']), with the LIVE
     * plan's url as a fallback for reports generated before that was frozen (or any
     * edge case where the snapshot has no url).
     *
     * So a variant report goes to its own quoted Loop link, while base reports are
     * unchanged (the frozen url equals the base plan url) and pre-Stage-3 reports
     * fall back to the live plan url exactly as before. Null when neither exists.
     */
    public function checkoutUrl(): ?string
    {
        return data_get($this->subscription_snapshot, 'url') ?: $this->plan?->subscription_url;
    }

    /**
     * A FLAT, snake_case summary of this report's plan for the Klaviyo "Report
     * Published" event — key plan fields plus a simple list of product names, chosen
     * to be easy to use in email templates and segments (no deep nested tree).
     *
     * NULL-SAFE by construction: plan_id is nullable and many reports have no plan,
     * so a report with no plan applied returns has_plan=false, null strings, 0 counts
     * and an empty product list — never an error. Read entirely from the report's
     * FROZEN data (subscription_snapshot for price/url; the report_steps /
     * report_step_products for the phases + product names), so it matches exactly what
     * the report itself renders and can't drift with a later live-plan edit. The plan
     * NAME comes from the plan relation, the same source the report's plan section
     * displays (the name isn't part of the snapshot).
     *
     * plan_products / plan_product_count include EVERY product attached to the plan's
     * steps (both 'included' and 'optional' add-ons), i.e. everything shown in the
     * plan section. Filter to inclusion==='included' here if only protocol products
     * are ever wanted.
     *
     * @return array{has_plan:bool, plan_name:?string, subscription_price:?string, subscription_url:?string, plan_phase_count:int, plan_product_count:int, plan_products:array<int,string>}
     */
    public function klaviyoPlanProperties(): array
    {
        // No plan applied → the safe, empty shape. Guarded first so nothing below can
        // surface stray steps/snapshot data for a planless report.
        if ($this->plan_id === null) {
            return [
                'has_plan' => false,
                'plan_name' => null,
                'subscription_price' => null,
                'subscription_url' => null,
                'plan_phase_count' => 0,
                'plan_product_count' => 0,
                'plan_products' => [],
            ];
        }

        // Frozen at apply-plan time (never the live plan).
        $snapshot = is_array($this->subscription_snapshot) ? $this->subscription_snapshot : [];

        // Frozen phases + their products. loadMissing so a single send doesn't N+1
        // and a caller that already eager-loaded steps.products.catalogProduct reuses it.
        $this->loadMissing('steps.products.catalogProduct');

        $productNames = $this->steps
            ->flatMap(fn (ReportStep $step) => $step->products->map(fn ($p) => $p->catalogProduct?->name))
            ->filter(fn (?string $name): bool => filled($name))
            ->values()
            ->all();

        return [
            'has_plan' => true,
            'plan_name' => $this->plan?->name,
            'subscription_price' => $snapshot['price'] ?? null,
            'subscription_url' => $snapshot['url'] ?? null,
            'plan_phase_count' => $this->steps->count(),
            'plan_product_count' => count($productNames),
            'plan_products' => $productNames,
        ];
    }

    /**
     * Has this report been successfully sent to Klaviyo at least once? (A failed
     * attempt stamps klaviyo_last_sent_at but must NOT count.) Used to surface the
     * "already sent — send again?" confirmation on a repeat Klaviyo send.
     */
    public function klaviyoHasBeenSent(): bool
    {
        return $this->klaviyo_last_sent_at !== null && ! empty($this->klaviyo_last_result['ok']);
    }

    /** Has this report been successfully emailed via the App (SMTP) at least once? */
    public function appHasBeenSent(): bool
    {
        return $this->app_last_sent_at !== null && ! empty($this->app_last_result['ok']);
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
     * Stage 1 read helper for the deterministic health-insights rework: every
     * bacteria percentage the forthcoming insights need, as a display-name =>
     * percent map (naming-robust phyla + the persisted Blautia / Escherichia-
     * Shigella genus totals). Delegates to the single source in ReportContent so
     * the rule layer (next stage) consumes ONE definition. Absent → 0.0.
     *
     * @return array<string,float>
     */
    public function insightTaxonPercentages(): array
    {
        return \App\Support\ReportContent::insightTaxonPercentages($this);
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
